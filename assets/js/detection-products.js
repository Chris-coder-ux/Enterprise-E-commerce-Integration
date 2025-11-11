/**
 * Manejo de la lista de productos en el dashboard de detección
 */

jQuery(document).ready(function($) {
  // Cargar productos al cargar la página
  loadProducts();

  // Función para cargar productos
  function loadProducts(filter = 'all') {
    const container = $('.detection-product-list');
    
    // Mostrar indicador de carga
    container.html('<div class="detection-loading">Cargando productos...</div>');

    // Verificar que los datos estén disponibles
    if (typeof miaDetectionData === 'undefined') {
      container.html('<div class="detection-error">Error: Datos de configuración no disponibles</div>');
      return;
    }

    // Hacer la petición AJAX
    $.ajax({
      url: miaDetectionData.ajaxUrl,
      type: 'POST',
      data: {
        action: 'mia_get_detection_products',
        filter,
        nonce: miaDetectionData.nonce
      },
      success(response) {
        if (response.success) {
          renderProducts(response.data.products);
        } else {
          container.html('<div class="detection-error">Error al cargar los productos: ' + response.data.message + '</div>');
        }
      },
      error(xhr, status, error) {
        container.html('<div class="detection-error">Error de conexión: ' + error + '</div>');
      }
    });
  }

  // Función para renderizar los productos
  function renderProducts(products) {
    const container = $('.detection-product-list');
    
    if (!products || products.length === 0) {
      container.html('<div class="detection-empty">No se encontraron productos</div>');
      return;
    }

    let html = '<div class="detection-products-grid">';
    
    for (const product of products) {
      html += `
        <div class="detection-product-card">
          <div class="product-image">
            <img src="${product.image || 'https://via.placeholder.com/150'}" alt="${product.name}">
          </div>
          <div class="product-details">
            <h4>${product.name}</h4>
            <div class="product-meta">
              <span class="product-sku">SKU: ${product.sku || 'N/A'}</span>
              <span class="product-stock ${product.in_stock ? 'in-stock' : 'out-of-stock'}">
                ${product.in_stock ? 'En stock' : 'Sin stock'}
              </span>
            </div>
            <div class="product-actions">
              <button class="button button-primary" onclick="editProduct(${product.id})">Editar</button>
              <button class="button" onclick="viewProduct(${product.id})">Ver</button>
            </div>
          </div>
        </div>
      `;
    }

    html += '</div>';
    container.html(html);
  }

  // Manejador de filtros
  $('.detection-filter-btn').on('click', function() {
    const filter = $(this).data('filter');
    $('.detection-filter-btn').removeClass('active');
    $(this).addClass('active');
    loadProducts(filter);
  });

  // Hacer las funciones accesibles globalmente
  globalThis.filterProducts = function(filter) {
    loadProducts(filter);
  };

  globalThis.editProduct = function(productId) {
    // Implementar lógica de edición
    // eslint-disable-next-line no-console
    console.log('Editar producto:', productId);
  };

  globalThis.viewProduct = function(productId) {
    // Implementar lógica de visualización
    // eslint-disable-next-line no-console
    console.log('Ver producto:', productId);
  };
});
