jQuery(document).ready(function($) {
    'use strict';
    
    // Variables globales
    let currentData = null;
    
    // Cargar estadísticas al inicio
    loadStats();
    
    // Event listeners
    $('#btn-detect').on('click', detectDuplicates);
    $('#btn-refresh').on('click', loadStats);
    $('#btn-export').on('click', exportReport);
    $('.tab-button').on('click', function() {
        switchTab($(this).data('tab'));
    });
    $('#btn-search').on('click', searchProducts);
    $('#search-products').on('keypress', function(e) {
        if (e.which === 13) searchProducts();
    });
    $('.verial-modal-close, #modal-cancel').on('click', closeModal);
    
    function loadStats() {
        $.ajax({
            url: verialAjaxUrl,
            type: 'POST',
            data: {
                action: 'verial_get_stats',
                nonce: verialNonce
            },
            success: function(response) {
                if (response.success) {
                    const stats = response.data;
                    $('#stat-total').text(stats.total.toLocaleString());
                    $('#stat-duplicates').text(stats.duplicates.toLocaleString());
                    $('#stat-no-sku').text(stats.no_sku.toLocaleString());
                    $('#stat-problematic').text(stats.problematic.toLocaleString());
                }
            }
        });
    }
    
    function detectDuplicates() {
        $('#loading').show();
        $('#results-container').hide();
        
        $.ajax({
            url: verialAjaxUrl,
            type: 'POST',
            data: {
                action: 'verial_detect_duplicates',
                nonce: verialNonce
            },
            success: function(response) {
                $('#loading').hide();
                $('#results-container').show();
                
                if (response.success) {
                    currentData = response.data;
                    renderDuplicates(response.data.duplicates);
                    renderNoSku(response.data.no_sku);
                    renderProblematic(response.data.problematic);
                    renderAllProducts(response.data.all_products);
                } else {
                    alert('Error: ' + (response.data || 'Error desconocido'));
                }
            },
            error: function() {
                $('#loading').hide();
                alert('Error al conectar con el servidor');
            }
        });
    }
    
    function renderDuplicates(duplicates) {
        const tbody = $('#duplicates-table-body');
        tbody.empty();
        
        if (duplicates.length === 0) {
            tbody.append('<tr><td colspan="4" class="no-data">No se encontraron SKUs duplicados</td></tr>');
            return;
        }
        
        duplicates.forEach(function(dup) {
            const productIds = dup.products.map(p => p.id).join(', ');
            const productLinks = dup.products.map(p => 
                `<a href="${p.link}" target="_blank">${p.id}</a>`
            ).join(', ');
            
            const row = $(`
                <tr>
                    <td><strong>${escapeHtml(dup.sku)}</strong></td>
                    <td><span class="badge">${dup.count}</span></td>
                    <td>${productLinks}</td>
                    <td class="action-buttons">
                        <button class="button button-small btn-merge" data-sku="${escapeHtml(dup.sku)}" data-ids="${productIds}">
                            🔀 Fusionar
                        </button>
                        <button class="button button-small btn-delete" data-sku="${escapeHtml(dup.sku)}" data-ids="${productIds}">
                            🗑️ Eliminar Duplicados
                        </button>
                    </td>
                </tr>
            `);
            
            row.find('.btn-merge').on('click', function() {
                showMergeModal(dup);
            });
            
            row.find('.btn-delete').on('click', function() {
                showDeleteModal(dup);
            });
            
            tbody.append(row);
        });
    }
    
    function renderNoSku(noSku) {
        const tbody = $('#no-sku-table-body');
        tbody.empty();
        
        if (noSku.length === 0) {
            tbody.append('<tr><td colspan="5" class="no-data">No se encontraron productos sin SKU</td></tr>');
            return;
        }
        
        noSku.forEach(function(product) {
            const row = $(`
                <tr>
                    <td>${product.id}</td>
                    <td><a href="${product.link}" target="_blank">${escapeHtml(product.name)}</a></td>
                    <td><span class="status-${product.status}">${product.status}</span></td>
                    <td>${formatDate(product.date)}</td>
                    <td>
                        <a href="${product.link}" class="button button-small" target="_blank">Editar</a>
                    </td>
                </tr>
            `);
            tbody.append(row);
        });
    }
    
    function renderProblematic(problematic) {
        const tbody = $('#problematic-table-body');
        tbody.empty();
        
        if (problematic.length === 0) {
            tbody.append('<tr><td colspan="5" class="no-data">No se encontraron SKUs problemáticos</td></tr>');
            return;
        }
        
        problematic.forEach(function(product) {
            const row = $(`
                <tr>
                    <td>${product.id}</td>
                    <td><code>${escapeHtml(product.sku)}</code></td>
                    <td><a href="${product.link}" target="_blank">${escapeHtml(product.name)}</a></td>
                    <td><span class="warning">⚠️ ${escapeHtml(product.problem)}</span></td>
                    <td>
                        <a href="${product.link}" class="button button-small" target="_blank">Editar</a>
                    </td>
                </tr>
            `);
            tbody.append(row);
        });
    }
    
    function renderAllProducts(products) {
        const tbody = $('#all-products-table-body');
        tbody.empty();
        
        if (products.length === 0) {
            tbody.append('<tr><td colspan="6" class="no-data">No se encontraron productos</td></tr>');
            return;
        }
        
        products.forEach(function(product) {
            const row = $(`
                <tr>
                    <td>${product.id}</td>
                    <td><code>${escapeHtml(product.sku)}</code></td>
                    <td><a href="${product.link}" target="_blank">${escapeHtml(product.name)}</a></td>
                    <td><span class="status-${product.status}">${product.status}</span></td>
                    <td>${formatPrice(product.price)}</td>
                    <td>
                        <a href="${product.link}" class="button button-small" target="_blank">Editar</a>
                    </td>
                </tr>
            `);
            tbody.append(row);
        });
    }
    
    function switchTab(tabName) {
        $('.tab-button').removeClass('active');
        $(`.tab-button[data-tab="${tabName}"]`).addClass('active');
        
        $('.tab-content').removeClass('active');
        $(`#tab-${tabName}`).addClass('active');
    }
    
    function showMergeModal(dup) {
        const keepId = dup.products[0].id; // Mantener el primero (más antiguo)
        const deleteIds = dup.products.slice(1).map(p => p.id);
        
        let html = `<p>¿Deseas fusionar los productos duplicados con SKU <strong>${escapeHtml(dup.sku)}</strong>?</p>`;
        html += `<p><strong>Mantener:</strong> Producto ID ${keepId} (${dup.products[0].name})</p>`;
        html += `<p><strong>Eliminar:</strong> ${deleteIds.length} producto(s)</p>`;
        html += '<ul>';
        dup.products.slice(1).forEach(function(p) {
            html += `<li>ID ${p.id}: ${escapeHtml(p.name)}</li>`;
        });
        html += '</ul>';
        html += '<p class="warning"><strong>⚠️ Advertencia:</strong> Los productos eliminados se perderán permanentemente.</p>';
        
        $('#modal-title').text('Fusionar Productos Duplicados');
        $('#modal-body').html(html);
        
        $('#modal-confirm').off('click').on('click', function() {
            cleanDuplicates(dup.sku, 'merge', keepId, deleteIds);
        });
        
        $('#verial-modal').show();
    }
    
    function showDeleteModal(dup) {
        const keepId = dup.products[0].id;
        const deleteIds = dup.products.slice(1).map(p => p.id);
        
        let html = `<p>¿Deseas eliminar los productos duplicados con SKU <strong>${escapeHtml(dup.sku)}</strong>?</p>`;
        html += `<p><strong>Mantener:</strong> Producto ID ${keepId} (${dup.products[0].name})</p>`;
        html += `<p><strong>Eliminar:</strong> ${deleteIds.length} producto(s)</p>`;
        html += '<ul>';
        dup.products.slice(1).forEach(function(p) {
            html += `<li>ID ${p.id}: ${escapeHtml(p.name)}</li>`;
        });
        html += '</ul>';
        html += '<p class="warning"><strong>⚠️ Advertencia:</strong> Esta acción no se puede deshacer.</p>';
        
        $('#modal-title').text('Eliminar Productos Duplicados');
        $('#modal-body').html(html);
        
        $('#modal-confirm').off('click').on('click', function() {
            cleanDuplicates(dup.sku, 'delete', keepId, deleteIds);
        });
        
        $('#verial-modal').show();
    }
    
    function cleanDuplicates(sku, action, keepId, deleteIds) {
        $('#loading').show();
        
        $.ajax({
            url: verialAjaxUrl,
            type: 'POST',
            data: {
                action: 'verial_clean_duplicates',
                nonce: verialNonce,
                sku: sku,
                action_type: action,
                keep_id: keepId,
                delete_ids: deleteIds
            },
            success: function(response) {
                $('#loading').hide();
                closeModal();
                
                if (response.success) {
                    alert(`✅ ${response.data.deleted.length} producto(s) procesado(s) correctamente`);
                    detectDuplicates(); // Recargar datos
                    loadStats(); // Actualizar estadísticas
                } else {
                    alert('Error: ' + (response.data || 'Error desconocido'));
                }
            },
            error: function() {
                $('#loading').hide();
                alert('Error al conectar con el servidor');
            }
        });
    }
    
    function searchProducts() {
        const search = $('#search-products').val().toLowerCase();
        const rows = $('#all-products-table-body tr');
        
        if (!search) {
            rows.show();
            return;
        }
        
        rows.each(function() {
            const text = $(this).text().toLowerCase();
            if (text.includes(search)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }
    
    function exportReport() {
        if (!currentData) {
            alert('Primero debes ejecutar la detección de duplicados');
            return;
        }
        
        let csv = 'Tipo,SKU,ID Producto,Nombre,Estado,Problema\n';
        
        // Duplicados
        currentData.duplicates.forEach(function(dup) {
            dup.products.forEach(function(p, index) {
                csv += `Duplicado,"${dup.sku}",${p.id},"${p.name}",${p.status},"${dup.count} duplicados"\n`;
            });
        });
        
        // Sin SKU
        currentData.no_sku.forEach(function(p) {
            csv += `Sin SKU,"",${p.id},"${p.name}",${p.status},"Producto sin SKU"\n`;
        });
        
        // Problemáticos
        currentData.problematic.forEach(function(p) {
            csv += `Problemático,"${p.sku}",${p.id},"${p.name}",,"${p.problem}"\n`;
        });
        
        // Descargar CSV
        const blob = new Blob([csv], { type: 'text/csv' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'reporte-duplicados-' + new Date().toISOString().split('T')[0] + '.csv';
        a.click();
        window.URL.revokeObjectURL(url);
    }
    
    function closeModal() {
        $('#verial-modal').hide();
    }
    
    // Utilidades
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, m => map[m]);
    }
    
    function formatDate(dateString) {
        const date = new Date(dateString);
        return date.toLocaleDateString('es-ES', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
    
    function formatPrice(price) {
        return parseFloat(price).toFixed(2) + ' €';
    }
    
    // Cerrar modal al hacer clic fuera
    $(window).on('click', function(e) {
        if ($(e.target).hasClass('verial-modal')) {
            closeModal();
        }
    });
});


