<?php

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EndpointsPage {
	/**
	 * Constante para el texto de parámetros
	 */
	private const PARAMETERS_LABEL = 'Parámetros:';
	
	public static function render() {
		// Enqueue dashicons
		wp_enqueue_style('dashicons');

		?>
		<div class="wrap mi-integracion-api-admin">
			<!-- Sidebar Unificado -->
			<div class="mi-integracion-api-sidebar">
				<div class="unified-sidebar-header">
					<h2>Mi Integración API</h2>
					<button class="sidebar-toggle" title="Colapsar/Expandir">
						<i class="fas fa-chevron-left"></i>
					</button>
				</div>
				
				<div class="unified-sidebar-content">
					<!-- Navegación Principal -->
					<ul class="unified-nav-menu">
						<li class="unified-nav-item">
							<a href="<?php echo admin_url('admin.php?page=mi-integracion-api'); ?>" class="unified-nav-link" data-page="dashboard">
								<span class="nav-icon dashicons dashicons-admin-home"></span>
								<span class="nav-text">Dashboard</span>
							</a>
						</li>
						<li class="unified-nav-item">
							<a href="<?php echo admin_url('admin.php?page=mia-detection-dashboard'); ?>" class="unified-nav-link" data-page="detection">
								<span class="nav-icon dashicons dashicons-search"></span>
								<span class="nav-text">Detección Automática</span>
							</a>
						</li>
						<li class="unified-nav-item">
							<a href="<?php echo admin_url('admin.php?page=mi-integracion-api-order-sync'); ?>" class="unified-nav-link" data-page="orders">
								<span class="nav-icon dashicons dashicons-cart"></span>
								<span class="nav-text">Sincronización de Pedidos</span>
							</a>
						</li>
						<li class="unified-nav-item active">
							<a href="<?php echo admin_url('admin.php?page=mi-integracion-api-endpoints'); ?>" class="unified-nav-link" data-page="endpoints">
								<span class="nav-icon dashicons dashicons-networking"></span>
								<span class="nav-text">Endpoints</span>
							</a>
						</li>
						<li class="unified-nav-item">
							<a href="<?php echo admin_url('admin.php?page=mi-integracion-api-cache'); ?>" class="unified-nav-link" data-page="cache">
								<span class="nav-icon dashicons dashicons-performance"></span>
								<span class="nav-text">Caché</span>
							</a>
						</li>
						<li class="unified-nav-item">
							<a href="<?php echo admin_url('admin.php?page=mi-integracion-api-retry-settings'); ?>" class="unified-nav-link" data-page="retry">
								<span class="nav-icon dashicons dashicons-update"></span>
								<span class="nav-text">Reintentos</span>
							</a>
						</li>
						<li class="unified-nav-item">
							<a href="<?php echo admin_url('admin.php?page=mi-integracion-api-memory-monitoring'); ?>" class="unified-nav-link" data-page="memory">
								<span class="nav-icon dashicons dashicons-chart-area"></span>
								<span class="nav-text">Monitoreo de Memoria</span>
							</a>
						</li>
					</ul>
					
					<!-- Sección de Acciones Rápidas -->
					<div class="unified-actions-section">
						<h3>Acciones Rápidas</h3>
						<div class="unified-actions-grid">
							<button class="unified-action-btn" data-action="test-endpoint" title="Probar Endpoint">
								<i class="fas fa-play"></i>
								<span>Probar API</span>
							</button>
							<button class="unified-action-btn" data-action="clear-results" title="Limpiar Resultados">
								<i class="fas fa-trash"></i>
								<span>Limpiar</span>
							</button>
							<button class="unified-action-btn" data-action="export-results" title="Exportar Resultados">
								<i class="fas fa-download"></i>
								<span>Exportar</span>
							</button>
							<button class="unified-action-btn" data-action="docs" title="Ver Documentación">
								<i class="fas fa-book"></i>
								<span>Docs</span>
							</button>
						</div>
					</div>
					
					<!-- Sección de Configuración -->
					<div class="unified-config-section">
						<h3>Configuración</h3>
						<div class="unified-config-item">
							<label for="theme-switcher">Tema:</label>
							<select id="theme-switcher" class="theme-switcher">
								<option value="default">Por Defecto</option>
								<option value="dark">Oscuro</option>
								<option value="light">Claro</option>
							</select>
						</div>
						<div class="unified-config-item">
							<label for="precision">Precisión:</label>
							<input type="number" id="precision" class="unified-input" value="2" min="0" max="4">
						</div>
					</div>
					
					<!-- Búsqueda -->
					<div class="unified-search-section">
						<label for="unified-menu-search" class="screen-reader-text"><?php esc_html_e('Buscar en menú', 'mi-integracion-api'); ?></label>
						<input type="text" id="unified-menu-search" class="unified-search-input" placeholder="<?php esc_attr_e('Buscar en menú...', 'mi-integracion-api'); ?>">
					</div>
				</div>
			</div>

			<!-- Contenido principal -->
			<div class="mi-integracion-api-main-content">
				<!-- Banner principal -->
				<div class="mi-integracion-api-banner">
					<div class="banner-content">
						<div class="banner-text">
							<h1><?php _e( 'Pruebas de Endpoints Verial', 'mi-integracion-api' ); ?></h1>
							<p><?php _e( 'Herramienta para probar operaciones POST en el servicio web Verial. Conecta y prueba la API de Verial de forma segura.', 'mi-integracion-api' ); ?></p>
							
							<div class="logo">
								<div class="woo">API</div>
								<div class="sync">TEST</div>
								<div class="shop">VERIAL</div>
							</div>
						</div>
						
						<div class="banner-visual">
							<div class="product-box box-1">
								<div class="product-tittle">WooCommerce</div>
								<div class="product-label">Request</div>
								<div class="product-stock">POST</div>
							</div>
							
							<div class="sync-animation">
								<div class="sync-circle"></div>
							</div>
							
							<div class="arrows">
								<div class="arrow">➔</div>
								<div class="arrow">➔</div>
							</div>
							
							<div class="product-box box-2">
								<div class="product-tittle">Verial API</div>
								<div class="product-label">Response</div>
								<div class="product-stock">JSON</div>
							</div>
							
							<div class="database">
								<div class="db-line"></div>
								<div class="db-line"></div>
								<div class="db-line"></div>
							</div>
						</div>
					</div>

					<!-- Icono de Ayuda -->
					<div class="endpoints-help">
						<a href="<?php echo esc_url(MiIntegracionApi_PLUGIN_URL . 'docs/manual-usuario/manual-endpoints.html'); ?>" 
						target="_blank" 
						class="help-link"
						title="<?php esc_attr_e('Abrir Manual de Endpoints', 'mi-integracion-api'); ?>">
							<i class="fas fa-question-circle"></i>
							<span><?php esc_html_e('Ayuda', 'mi-integracion-api'); ?></span>
						</a>
					</div>
				</div>
			
				<!-- Contenedor principal de endpoints -->
				<div class="mi-integracion-api-card endpoints-container">
					<div class="unified-tabs">
						<a href="#" class="unified-tab-link active" data-tab="tab-endpoints">
							<span class="dashicons dashicons-editor-code"></span>
							<?php _e( 'Llamadas a Endpoints', 'mi-integracion-api' ); ?>
						</a>
						<a href="#" class="unified-tab-link" data-tab="tab-docs">
							<span class="dashicons dashicons-book-alt"></span>
							<?php _e( 'Documentación', 'mi-integracion-api' ); ?>
						</a>
					</div>
				
					<div id="tab-endpoints" class="unified-tab-content active">
						<!-- Introducción -->
						<div class="endpoint-intro">
							<div class="endpoint-intro-icon">
								<span class="dashicons dashicons-upload"></span>
							</div>
							<div class="endpoint-intro-text">
								<h3><?php _e('Prueba de API Verial (POST)', 'mi-integracion-api'); ?></h3>
								<p><?php _e('Selecciona un endpoint POST para enviar datos a Verial. Introduce los parámetros en formato JSON y haz clic en "Probar API" para ver los resultados.', 'mi-integracion-api'); ?></p>
							</div>
						</div>

						<!-- Formulario de prueba -->
						<div class="endpoint-form-container">
							<form id="mi-endpoint-form" class="mi-integracion-api-form-row" autocomplete="off">
								<div class="form-group">
									<label for="mi_endpoint_select">
										<span class="dashicons dashicons-database"></span>
										<?php _e( 'Endpoint', 'mi-integracion-api' ); ?>
									</label>
									<select id="mi_endpoint_select" name="endpoint" class="mi-integracion-api-select">
										<option value="crear_pedido"><?php _e( 'Crear Pedido (crear_pedido)', 'mi-integracion-api' ); ?></option>
										<option value="actualizar_stock"><?php _e( 'Actualizar Stock (actualizar_stock)', 'mi-integracion-api' ); ?></option>
										<option value="crear_cliente"><?php _e( 'Registrar Cliente (crear_cliente)', 'mi-integracion-api' ); ?></option>
										<option value="actualizar_precios"><?php _e( 'Actualizar Precios (actualizar_precios)', 'mi-integracion-api' ); ?></option>
										<option value="crear_articulo"><?php _e( 'Crear Artículo (crear_articulo)', 'mi-integracion-api' ); ?></option>
									</select>
								</div>
								<div class="form-group">
									<label for="mi_endpoint_param">
										<span class="dashicons dashicons-search"></span>
										<?php _e( 'Parámetros JSON', 'mi-integracion-api' ); ?>
									</label>
									<textarea id="mi_endpoint_param" name="param" placeholder="<?php _e( 'Introduce los parámetros en formato JSON...', 'mi-integracion-api' ); ?>" rows="8"></textarea>
								</div>
								<div class="form-group form-submit">
									<button type="submit" class="mi-integracion-api-button primary">
										<span class="dashicons dashicons-rest-api"></span>
										<?php _e( 'Probar API', 'mi-integracion-api' ); ?>
									</button>
								</div>
							</form>
						</div>
					
						<!-- Área de feedback -->
						<div id="mi-endpoint-feedback"></div>
						
						<!-- Tabla de resultados -->
						<div class="mi-integracion-api-table-wrapper">
							<div class="mi-integracion-api-table-header">
								<h3 class="results-title" style="display:none;">
									<span class="dashicons dashicons-list-view"></span>
									<?php _e('Resultados', 'mi-integracion-api'); ?>
								</h3>
								<div class="table-actions" style="display:none;">
									<button id="copy-results" class="mi-integracion-api-button secondary">
										<span class="dashicons dashicons-clipboard"></span>
										<?php _e('Copiar', 'mi-integracion-api'); ?>
									</button>
								</div>
							</div>
							<div class="mi-integracion-api-table-responsive">
								<table class="mi-integracion-api-table" id="mi-endpoint-result-table" style="display:none;">
									<thead>
										<tr>
											<th><?php _e('Campo', 'mi-integracion-api'); ?></th>
											<th><?php _e('Valor', 'mi-integracion-api'); ?></th>
										</tr>
									</thead>
									<tbody></tbody>
								</table>
							</div>
						</div>
					</div>
				
				<div id="tab-docs" class="unified-tab-content" style="display:none;">
					<div class="mi-integracion-api-card api-docs">
						<div class="api-docs-header">
							<span class="dashicons dashicons-book"></span>
							<h3><?php _e( 'Descripción de endpoints disponibles', 'mi-integracion-api' ); ?></h3>
						</div>
						
						<div class="endpoints-list">
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> NuevoClienteWS</h4>
								<p><?php _e( 'Da de alta un nuevo cliente o modifica uno existente en Verial.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>Id</code> - <?php _e('(Opcional) ID del cliente para modificar', 'mi-integracion-api'); ?></li>
										<li><code>Tipo</code> - <?php _e('(Requerido) Tipo de cliente (1=Particular, 2=Empresa)', 'mi-integracion-api'); ?></li>
										<li><code>NIF</code> - <?php _e('(Requerido) NIF/CIF del cliente', 'mi-integracion-api'); ?></li>
										<li><code>Nombre</code> - <?php _e('(Requerido) Nombre del cliente', 'mi-integracion-api'); ?></li>
										<li><code>Direccion</code> - <?php _e('(Requerido) Dirección del cliente', 'mi-integracion-api'); ?></li>
										<li><code>Email</code> - <?php _e('(Opcional) Correo electrónico', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> NuevoDocClienteWS</h4>
								<p><?php _e( 'Da de alta o modifica un documento de cliente (pedido, factura, albarán, etc.).', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>Id</code> - <?php _e('(Opcional) ID del documento para modificar', 'mi-integracion-api'); ?></li>
										<li><code>Tipo</code> - <?php _e('(Requerido) Tipo de documento (1=Factura, 5=Pedido, 3=Albarán)', 'mi-integracion-api'); ?></li>
										<li><code>ID_Cliente</code> - <?php _e('(Requerido) ID del cliente', 'mi-integracion-api'); ?></li>
										<li><code>Contenido</code> - <?php _e('(Requerido) Array con las líneas del documento', 'mi-integracion-api'); ?></li>
										<li><code>Pagos</code> - <?php _e('(Opcional) Array con los pagos del documento', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> NuevaDireccionEnvioWS</h4>
								<p><?php _e( 'Da de alta una nueva dirección de envío en un cliente ya existente.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>ID_Cliente</code> - <?php _e('(Requerido) ID del cliente', 'mi-integracion-api'); ?></li>
										<li><code>Nombre</code> - <?php _e('(Requerido) Nombre del destinatario', 'mi-integracion-api'); ?></li>
										<li><code>Direccion</code> - <?php _e('(Requerido) Dirección de envío', 'mi-integracion-api'); ?></li>
										<li><code>CPostal</code> - <?php _e('(Requerido) Código postal', 'mi-integracion-api'); ?></li>
										<li><code>Telefono</code> - <?php _e('(Opcional) Teléfono de contacto', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> EstadoPedidosWS</h4>
								<p><?php _e( 'Consulta el estado de los pedidos. Permite consultar múltiples pedidos de una sola vez.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>Pedidos</code> - <?php _e('(Requerido) Array con los pedidos a consultar', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> NuevaMascotaWS</h4>
								<p><?php _e( 'Da de alta o modifica los datos de un registro de mascota.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>ID_Cliente</code> - <?php _e('(Requerido) ID del cliente propietario', 'mi-integracion-api'); ?></li>
										<li><code>Nombre</code> - <?php _e('(Requerido) Nombre de la mascota', 'mi-integracion-api'); ?></li>
										<li><code>TipoAnimal</code> - <?php _e('(Requerido) Tipo de animal', 'mi-integracion-api'); ?></li>
										<li><code>Raza</code> - <?php _e('(Opcional) Raza del animal', 'mi-integracion-api'); ?></li>
										<li><code>FechaNacimiento</code> - <?php _e('(Opcional) Fecha de nacimiento', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> BorrarMascotaWS</h4>
								<p><?php _e( 'Borra un registro de mascota del sistema.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>ID_Cliente</code> - <?php _e('(Requerido) ID del cliente propietario', 'mi-integracion-api'); ?></li>
										<li><code>Id</code> - <?php _e('(Requerido) ID de la mascota a eliminar', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> UpdateDocClienteWS</h4>
								<p><?php _e( 'Permite modificar ciertos datos de un documento de cliente ya existente.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>Id</code> - <?php _e('(Requerido) ID del documento a modificar', 'mi-integracion-api'); ?></li>
										<li><code>Aux1</code> - <?php _e('(Opcional) Campo auxiliar 1', 'mi-integracion-api'); ?></li>
										<li><code>Aux2</code> - <?php _e('(Opcional) Campo auxiliar 2', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> NuevoPagoWS</h4>
								<p><?php _e( 'Añade pagos a pedidos ya creados.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>ID_DocCli</code> - <?php _e('(Requerido) ID del pedido', 'mi-integracion-api'); ?></li>
										<li><code>ID_MetodoPago</code> - <?php _e('(Requerido) ID del método de pago', 'mi-integracion-api'); ?></li>
										<li><code>Fecha</code> - <?php _e('(Requerido) Fecha del pago', 'mi-integracion-api'); ?></li>
										<li><code>Importe</code> - <?php _e('(Requerido) Importe del pago', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> NuevaProvinciaWS</h4>
								<p><?php _e( 'Da de alta una nueva provincia en el sistema Verial.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>Nombre</code> - <?php _e('(Requerido) Nombre de la provincia', 'mi-integracion-api'); ?></li>
										<li><code>ID_Pais</code> - <?php _e('(Requerido) ID del país al que pertenece', 'mi-integracion-api'); ?></li>
										<li><code>CodigoNUTS</code> - <?php _e('(Opcional) Código NUTS de la provincia', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
							
							<div class="endpoint-doc-item">
								<h4><span class="endpoint-tag">POST</span> NuevaLocalidadWS</h4>
								<p><?php _e( 'Da de alta una nueva localidad en el sistema Verial.', 'mi-integracion-api' ); ?></p>
								<div class="endpoint-params">
									<strong><?php echo esc_html(self::PARAMETERS_LABEL); ?></strong>
									<ul>
										<li><code>sesionwcf</code> - <?php _e('(Requerido) Número de sesión', 'mi-integracion-api'); ?></li>
										<li><code>Nombre</code> - <?php _e('(Requerido) Nombre de la localidad', 'mi-integracion-api'); ?></li>
										<li><code>ID_Pais</code> - <?php _e('(Requerido) ID del país al que pertenece', 'mi-integracion-api'); ?></li>
										<li><code>ID_Provincia</code> - <?php _e('(Requerido) ID de la provincia', 'mi-integracion-api'); ?></li>
										<li><code>CodigoNUTS</code> - <?php _e('(Opcional) Código NUTS de la localidad', 'mi-integracion-api'); ?></li>
										<li><code>CodigoMunicipioINE</code> - <?php _e('(Opcional) Código de municipio INE', 'mi-integracion-api'); ?></li>
									</ul>
								</div>
							</div>
						</div>
					</div>
					
					<div class="api-docs-footer">
						<div class="api-docs-info">
							<p><?php _e('Para obtener información técnica más detallada sobre la API, consulta la documentación completa:', 'mi-integracion-api'); ?></p>
							<a href="#" class="mi-integracion-api-button secondary">
								<span class="dashicons dashicons-media-document"></span>
								<?php _e('Ver documentación técnica completa', 'mi-integracion-api'); ?>
							</a>
						</div>
					</div>
					</div>
				</div>
			</div>
		</div>
		
		<!-- Plantilla para el indicador de carga -->
		<script type="text/template" id="loading-indicator-template">
			<div class="endpoints-loading">
				<div class="endpoints-loading-spinner"></div>
				<div class="endpoints-loading-text"><?php _e('Consultando endpoint...', 'mi-integracion-api'); ?></div>
			</div>
		</script>
		
		<!-- Script inicializador para esta página -->
		<script type="text/javascript">
		jQuery(document).ready(function($){
			// Select normal sin dependencias externas
			
			// Manejo de tabs
			$('.unified-tab-link').on('click', function(e) {
				e.preventDefault();
				var tabId = $(this).data('tab');
				
				// Activar tab
				$('.unified-tab-link').removeClass('active');
				$(this).addClass('active');
				
				// Mostrar contenido del tab
				$('.unified-tab-content').hide();
				$('#' + tabId).fadeIn(300);
			});
			
			// Manejo del formulario
			$('#mi-endpoint-form').on('submit', function(e) {
				e.preventDefault();
				
				var endpoint = $('#mi_endpoint_select').val();
				var param = $('#mi_endpoint_param').val();
				
				// Mostrar indicador de carga
				$('#mi-endpoint-feedback').html($('#loading-indicator-template').html());
				
				// Ocultar tabla de resultados y encabezado
				$('#mi-endpoint-result-table').hide();
				$('.results-title, .table-actions').hide();
				
				// Llamada AJAX
				$.post(miIntegracionApiEndpoints.ajaxurl, {
					action: 'mi_test_endpoint',
					endpoint: endpoint,
					param: param,
					nonce: miIntegracionApiEndpoints.nonce
				}, function(response) {
					if(response.success && response.data) {
						// Limpiar feedback y mostrar toast
						$('#mi-endpoint-feedback').html('');
						if(window.verialToast) {
							window.verialToast.show({
								type: 'success',
								message: miIntegracionApiEndpoints.success
							});
						}
						
						// Mostrar resultados en tabla
						if(response.data.table_html) {
							$('#mi-endpoint-result-table').html(response.data.table_html).fadeIn(300);
							$('.results-title, .table-actions').fadeIn(300);
							
							// Formatear JSON en las celdas
							$('#mi-endpoint-result-table td').each(function() {
								var $cell = $(this);
								var text = $cell.text();
								
								try {
									// Detectar si el contenido parece ser JSON
									if ((text.startsWith('{') && text.endsWith('}')) ||
										(text.startsWith('[') && text.endsWith(']'))) {
										var json = JSON.parse(text);
										$cell.html('<pre class="json-field">' + JSON.stringify(json, null, 2) + '</pre>');
									}
								} catch(e) {
									// No es JSON, dejar como texto
								}
							});
						} else {
							// Sin resultados
							$('#mi-endpoint-result-table').hide();
							$('#mi-endpoint-feedback').html('<div class="notice notice-info">' + miIntegracionApiEndpoints.resultsCount.replace('{count}', '0') + '</div>');
						}
					} else {
						// Error en la respuesta
						var errorMsg = response.data && response.data.message
							? response.data.message
							: miIntegracionApiEndpoints.error;
							
						$('#mi-endpoint-feedback').html('<div class="notice notice-error">' + errorMsg + '</div>');
						
						if(window.verialToast) {
							window.verialToast.show({
								type: 'error',
								message: errorMsg
							});
						}
						
						$('#mi-endpoint-result-table').hide();
						$('.results-title, .table-actions').hide();
					}
				}).fail(function() {
					// Error de red
					$('#mi-endpoint-feedback').html('<div class="notice notice-error">' + miIntegracionApiEndpoints.error + '</div>');
					
					if(window.verialToast) {
						window.verialToast.show({
							type: 'error',
							message: miIntegracionApiEndpoints.error
						});
					}
					
					$('#mi-endpoint-result-table').hide();
					$('.results-title, .table-actions').hide();
				});
			});
			
			// Copiar resultados al portapapeles
			$('#copy-results').on('click', function() {
				var tableHtml = $('#mi-endpoint-result-table').clone();
				
				// Eliminar clases y estilos para tener solo el contenido
				tableHtml.find('*').removeAttr('style').removeAttr('class');
				
				// Crear un elemento temporal
				var $temp = $('<div>').css({
					position: 'absolute',
					left: '-9999px',
					top: 0
				}).appendTo('body').html(tableHtml.prop('outerHTML'));
				
				// Seleccionar el contenido
				var range = document.createRange();
				var selection = window.getSelection();
				range.selectNodeContents($temp[0]);
				selection.removeAllRanges();
				selection.addRange(range);
				
				// Copiar al portapapeles
				try {
					document.execCommand('copy');
					if(window.verialToast) {
						window.verialToast.show({
							type: 'success',
							message: miIntegracionApiEndpoints.copied
						});
					}
				} catch (e) {
					if(window.verialToast) {
						window.verialToast.show({
							type: 'error',
							message: miIntegracionApiEndpoints.error
						});
					}
				}
				
				// Limpiar
				$temp.remove();
				selection.removeAllRanges();
			});
		});
		</script>
		
		<?php
		// Cargar Font Awesome para iconos
		wp_enqueue_style(
			'font-awesome',
			'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css',
			array(),
			'6.0.0'
		);
		
		// Cargar design-system primero (variables CSS)
		wp_enqueue_style(
			'mi-integracion-api-design-system',
			MiIntegracionApi_PLUGIN_URL . 'assets/css/design-system.css',
			array(),
			constant('MiIntegracionApi_VERSION')
		);
		
		// Cargar CSS específico del dashboard
		wp_enqueue_style(
			'mi-integracion-api-dashboard',
			MiIntegracionApi_PLUGIN_URL . 'assets/css/dashboard.css',
			array('mi-integracion-api-design-system'),
			constant('MiIntegracionApi_VERSION')
		);
		
		// Cargar CSS del sidebar unificado
		wp_enqueue_style(
			'mi-integracion-api-unified-sidebar',
			MiIntegracionApi_PLUGIN_URL . 'assets/css/unified-sidebar.css',
			array('mi-integracion-api-dashboard'),
			constant('MiIntegracionApi_VERSION')
		);
		
		// Cargar CSS específico de endpoints (con estilos del botón de ayuda)
		wp_enqueue_style(
			'mi-integracion-api-endpoints',
			MiIntegracionApi_PLUGIN_URL . 'assets/css/endpoints.css',
			array('mi-integracion-api-unified-sidebar'),
			constant('MiIntegracionApi_VERSION')
		);
		
		// Cargar JavaScript del sidebar unificado
		wp_enqueue_script(
			'mi-integracion-api-unified-sidebar',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/unified-sidebar.js',
			array('jquery'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Cargar JavaScript específico de endpoints
		wp_enqueue_script(
			'mi-integracion-api-endpoints',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/endpoints.js',
			array('jquery', 'mi-integracion-api-unified-sidebar'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Localizar script con datos de WordPress para endpoints
		wp_localize_script('mi-integracion-api-endpoints', 'miIntegracionApiEndpoints', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('mi_endpoint_nonce'),
			'loading' => __('Consultando endpoint... Por favor espera', 'mi-integracion-api'),
			'error' => __('Error al procesar la solicitud', 'mi-integracion-api'),
			'success' => __('Consulta exitosa', 'mi-integracion-api'),
			'copied' => __('Copiado al portapapeles', 'mi-integracion-api'),
			'resultsCount' => __('Se encontraron {count} resultados', 'mi-integracion-api'),
			'paramPlaceholder' => __('Parámetro (opcional)', 'mi-integracion-api'),
			'restUrl' => rest_url('mi-integracion-api/v1/'),
			'debug' => defined('WP_DEBUG') && constant('WP_DEBUG')
		));
	}
}
