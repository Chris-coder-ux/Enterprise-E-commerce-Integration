<?php

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

use Exception;
use MiIntegracionApi\Core\MemoryManager;
use MiIntegracionApi\Core\Sync_Manager;
use MiIntegracionApi\Core\SyncLock;
use MiIntegracionApi\Helpers\BatchSizeHelper;
use MiIntegracionApi\Logging\Core\LoggerBasic;
use WP_Query;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vista del dashboard principal del plugin
 * Esta clase se encarga de renderizar la interfaz principal del dashboard
 * de administración, incluyendo la navegación, métricas del sistema,
 * estado de sincronización y controles de gestión.
 * @package MiIntegracionApi\Admin
 * @since 1.0.0
 * @author Christian
 */
class DashboardPageView {
	/**
	 * Renderiza el dashboard principal
	 * Método público que actúa como punto de entrada para renderizar
	 * la vista del dashboard. Delega en el método render() para mantener
	 * compatibilidad con versiones anteriores.
	 * @return void
	 * @since 1.0.0
	 */
	public static function render_dashboard(): void {
		self::render();
	}
	
	/**
	 * Renderiza la vista principal del dashboard
	 * Método principal que genera todo el HTML del dashboard incluyendo:
	 * - Navegación lateral con enlaces a diferentes secciones
	 * - Estado general del sistema con indicadores de salud
	 * - Métricas de sincronización y progreso
	 * - Controles de gestión y herramientas de diagnóstico
	 * @return void
	 * @since 1.0.0
	 */
	public static function render(): void {
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
						<li class="unified-nav-item active">
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
						<li class="unified-nav-item">
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
							<button class="unified-action-btn" data-action="sync" title="Sincronizar Ahora">
								<i class="fas fa-sync-alt"></i>
								<span>Sincronizar</span>
							</button>
							<button class="unified-action-btn" data-action="refresh" title="Actualizar Datos">
								<i class="fas fa-refresh"></i>
								<span>Actualizar</span>
							</button>
							<button class="unified-action-btn" data-action="export" title="Exportar Datos">
								<i class="fas fa-download"></i>
								<span>Exportar</span>
							</button>
							<button class="unified-action-btn" data-action="settings" title="Configuración">
								<i class="fas fa-cog"></i>
								<span>Config</span>
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
				
				<?php self::renderbanner(); ?>

			<!-- Estado general del sistema -->
			<div class="mi-system-health-overview">
				<h2><?php esc_html_e( 'Estado General del Sistema', 'mi-integracion-api' ); ?></h2>
				<div class="mi-health-grid">
					<?php
					$system_health = self::get_system_health();
					?>
					<div class="mi-health-card <?php echo esc_attr($system_health['overall_status']); ?>">
						<div class="health-icon">
							<?php if ($system_health['overall_status'] === 'critical'): ?>
								<span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
							<?php elseif ($system_health['overall_status'] === 'warning'): ?>
								<span class="dashicons dashicons-warning" style="color: #ffb900;"></span>
							<?php elseif ($system_health['overall_status'] === 'attention'): ?>
								<span class="dashicons dashicons-info" style="color: #00a0d2;"></span>
							<?php else: ?>
								<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							<?php endif; ?>
						</div>
						<div class="health-info">
							<h3><?php echo esc_html(ucfirst($system_health['overall_status'])); ?></h3>
							<p><?php echo esc_html($system_health['overall_message']); ?></p>
						</div>
					</div>
					
					<div class="mi-health-card">
						<h3><?php esc_html_e( 'Última Verificación', 'mi-integracion-api' ); ?></h3>
						<div class="health-value"><?php echo esc_html($system_health['last_check']); ?></div>
						<p><?php esc_html_e( 'Diagnóstico automático ejecutado', 'mi-integracion-api' ); ?></p>
					</div>
					
					<div class="mi-health-card">
						<h3><?php esc_html_e( 'Problemas Detectados', 'mi-integracion-api' ); ?></h3>
						<div class="health-value"><?php echo esc_html($system_health['issues_count']); ?></div>
						<p><?php esc_html_e( 'Requieren atención', 'mi-integracion-api' ); ?></p>
					</div>
				</div>
			</div>

						<!-- ✅ NUEVO: Dashboard de Sincronización en Dos Fases -->
						<div id="sync-two-phase-dashboard" class="sync-dashboard mi-integracion-api-card">
							<div class="sync-header">
								<h2 style="margin-top: 0;">🔄 <?php esc_html_e('Sincronización en Dos Fases', 'mi-integracion-api'); ?></h2>
								<p class="sync-description"><?php esc_html_e('Proceso optimizado: Fase 1 (Imágenes) → Fase 2 (Productos)', 'mi-integracion-api'); ?></p>
							</div>

							<?php
							// Obtener estado actual de sincronización
							$coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) constant('MIA_USE_CORE_SYNC') : (bool) get_option('mia_use_core_sync', true);
							if (function_exists('apply_filters')) {
								$coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting);
							}
							$sync_manager = Sync_Manager::get_instance();
							$sync_response = $sync_manager->getSyncStatus();
							$sync_status = $sync_response->getData();
							$in_progress = ($sync_status['current_sync']['in_progress'] ?? false);
							
							// Obtener estado de Fase 1 (imágenes)
							$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
							$phase1_info = $sync_info['phase1_images'] ?? [];
							$phase1_in_progress = $phase1_info['in_progress'] ?? false;
							$phase1_completed = $phase1_info['completed'] ?? false;
							$phase1_products_processed = $phase1_info['products_processed'] ?? 0;
							$phase1_total_products = $phase1_info['total_products'] ?? 0;
							$phase1_images_processed = $phase1_info['images_processed'] ?? 0;
							$phase1_duplicates = $phase1_info['duplicates_skipped'] ?? 0;
							$phase1_errors = $phase1_info['errors'] ?? 0;
							
							// Obtener batch size configurado
							$current_batch_size = BatchSizeHelper::getBatchSize('productos');
							$min_size = BatchSizeHelper::BATCH_SIZE_LIMITS['productos']['min'];
							$max_size = BatchSizeHelper::BATCH_SIZE_LIMITS['productos']['max'];
							$options = [1, 5, 10, 20, 50, 100, 200];
							
							// Determinar estado de Fase 1
							$phase1_status = 'pending';
							if ($phase1_completed) {
								$phase1_status = 'completed';
							} elseif ($phase1_in_progress) {
								$phase1_status = 'running';
							}
							
							// Determinar estado de Fase 2
							$phase2_status = 'pending';
							$phase2_total_items = $sync_status['current_sync']['total_items'] ?? 0;
							$phase2_items_synced = $sync_status['current_sync']['items_synced'] ?? 0;
							
							if ($in_progress && !$phase1_in_progress) {
								$phase2_status = 'running';
							} elseif ($phase2_total_items > 0 && $phase2_items_synced > 0 && !$in_progress && $phase2_items_synced >= $phase2_total_items) {
								// ✅ CORRECCIÓN: Solo marcar como completada si realmente se completó
								// Requisitos: total_items > 0, items_synced > 0, items_synced >= total_items, y no está en progreso
								$phase2_status = 'completed';
							} else {
								// ✅ CORRECCIÓN: Si hay valores inconsistentes (ej: items_synced > 0 pero total_items = 0),
								// o si no cumple los requisitos, marcar como pending
								$phase2_status = 'pending';
							}
							
							// ✅ CORRECCIÓN: Solo mostrar valores si hay sincronización activa o completada
							// Si está pendiente, resetear todos los valores a 0
							if ($phase1_status === 'pending') {
								$phase1_products_processed = 0;
								$phase1_total_products = 0;
								$phase1_images_processed = 0;
								$phase1_duplicates = 0;
								$phase1_errors = 0;
							}
							
							// ✅ CORRECCIÓN: Solo mostrar valores de Fase 2 si hay sincronización activa o completada
							// Si está pendiente, resetear todos los valores a 0
							$phase2_errors = 0;
							$phase2_created = 0;
							$phase2_updated = 0;
							if ($phase2_status === 'pending') {
								$phase2_total = 0;
								$phase2_processed = 0;
							} else {
								// Usar los valores ya obtenidos arriba
								$phase2_total = $phase2_total_items;
								$phase2_processed = $phase2_items_synced;
								$phase2_errors = $sync_status['current_sync']['errors'] ?? 0;
								// TODO: Obtener valores de created y updated del estado de sincronización cuando estén disponibles
								// Por ahora se mantienen en 0 y se actualizarán dinámicamente vía JavaScript
							}
							
							// ✅ ELIMINADO: Cálculo de porcentajes - ya no se usan barras de progreso
							?>

							<!-- FASE 1: IMÁGENES -->
							<div class="sync-phase phase-1" id="phase1-container">
								<div class="phase-header">
									<h3>
										<span class="phase-icon">🖼️</span>
										<?php esc_html_e('Fase 1: Sincronización de Imágenes', 'mi-integracion-api'); ?>
										<span class="phase-status phase-status-<?php echo esc_attr($phase1_status); ?>" id="phase1-status">
											<?php
											echo match($phase1_status) {
												'running' => '🔄 ' . esc_html__('En Progreso', 'mi-integracion-api'),
												'completed' => '✅ ' . esc_html__('Completado', 'mi-integracion-api'),
												'error' => '❌ ' . esc_html__('Error', 'mi-integracion-api'),
												'cancelled' => '🚫 ' . esc_html__('Cancelado', 'mi-integracion-api'),
												'paused' => '⏸ ' . esc_html__('Pausado', 'mi-integracion-api'),
												default => '⏳ ' . esc_html__('Pendiente', 'mi-integracion-api')
											};
											?>
										</span>
									</h3>
									<div class="phase-controls">
										<button id="start-phase1" class="button button-primary" <?php echo ($phase1_in_progress || $in_progress) ? 'disabled' : ''; ?>>
											<?php esc_html_e('Iniciar Fase 1', 'mi-integracion-api'); ?>
										</button>
										<button id="cancel-phase1" class="button button-secondary" disabled style="display: none;">
											❌ <?php esc_html_e('Cancelar', 'mi-integracion-api'); ?>
										</button>
									</div>
								</div>

								<div class="phase-stats">
									<div class="stat-card">
										<div class="stat-value" id="phase1-products"><?php echo esc_html($phase1_products_processed); ?></div>
										<div class="stat-label"><?php esc_html_e('Productos Procesados', 'mi-integracion-api'); ?></div>
									</div>
									<div class="stat-card">
										<div class="stat-value" id="phase1-images"><?php echo esc_html($phase1_images_processed); ?></div>
										<div class="stat-label"><?php esc_html_e('Imágenes Guardadas', 'mi-integracion-api'); ?></div>
									</div>
									<div class="stat-card">
										<div class="stat-value" id="phase1-duplicates"><?php echo esc_html($phase1_duplicates); ?></div>
										<div class="stat-label"><?php esc_html_e('Duplicados Saltados', 'mi-integracion-api'); ?></div>
									</div>
									<div class="stat-card">
										<div class="stat-value" id="phase1-errors"><?php echo esc_html($phase1_errors); ?></div>
										<div class="stat-label"><?php esc_html_e('Errores', 'mi-integracion-api'); ?></div>
									</div>
								</div>

								<div class="phase-timer">
									<span class="timer-label"><?php esc_html_e('Tiempo transcurrido:', 'mi-integracion-api'); ?></span>
									<span class="timer-value" id="phase1-timer">00:00:00</span>
									<span class="timer-label"><?php esc_html_e('Velocidad:', 'mi-integracion-api'); ?></span>
									<span class="timer-value" id="phase1-speed">0 <?php esc_html_e('productos/seg', 'mi-integracion-api'); ?></span>
								</div>
							</div>

							<!-- SEPARADOR DE FASES -->
							<div class="phase-separator">
								<div class="separator-line"></div>
								<div class="separator-icon">↓</div>
								<div class="separator-text"><?php esc_html_e('Después de completar Fase 1', 'mi-integracion-api'); ?></div>
							</div>

							<!-- ✅ CONSOLA DE SINCRONIZACIÓN EN TIEMPO REAL -->
							<div id="mia-sync-console" class="mia-sync-console" style="display: block;">
								<div class="mia-console-header">
									<div class="mia-console-title">
										<span class="dashicons dashicons-admin-generic"></span>
										<span><?php esc_html_e('Consola de Sincronización en Tiempo Real', 'mi-integracion-api'); ?></span>
									</div>
									<div class="mia-console-controls">
										<button id="mia-console-clear" class="button button-small" title="<?php esc_attr_e('Limpiar consola', 'mi-integracion-api'); ?>">
											<span class="dashicons dashicons-trash"></span>
										</button>
										<button id="mia-console-toggle" class="button button-small" title="<?php esc_attr_e('Minimizar/Maximizar', 'mi-integracion-api'); ?>">
											<span class="dashicons dashicons-arrow-up-alt2"></span>
										</button>
									</div>
								</div>
								<div class="mia-console-body">
									<div id="mia-console-content" class="mia-console-content">
										<div class="mia-console-line mia-console-info">
											<span class="mia-console-time"><?php echo esc_html(current_time('H:i:s')); ?></span>
											<span class="mia-console-label">[INFO]</span>
											<span class="mia-console-message"><?php esc_html_e('Consola de sincronización iniciada. Esperando actividad...', 'mi-integracion-api'); ?></span>
										</div>
									</div>
								</div>
								<div class="mia-console-footer">
									<div class="mia-phase-indicators">
										<div id="mia-phase1-indicator" class="mia-phase-indicator" data-phase="1" data-status="<?php echo esc_attr($phase1_status); ?>">
											<span class="mia-phase-icon">🖼️</span>
											<span class="mia-phase-label"><?php esc_html_e('Fase 1: Imágenes', 'mi-integracion-api'); ?></span>
											<span class="mia-phase-status" data-status="<?php echo esc_attr($phase1_status); ?>">
												<?php echo esc_html(ucfirst($phase1_status)); ?>
											</span>
										</div>
										<div id="mia-phase2-indicator" class="mia-phase-indicator" data-phase="2" data-status="<?php echo esc_attr($phase2_status); ?>">
											<span class="mia-phase-icon">📦</span>
											<span class="mia-phase-label"><?php esc_html_e('Fase 2: Productos', 'mi-integracion-api'); ?></span>
											<span class="mia-phase-status" data-status="<?php echo esc_attr($phase2_status); ?>">
												<?php echo esc_html(ucfirst($phase2_status)); ?>
											</span>
										</div>
									</div>
								</div>
							</div>

							<!-- FASE 2: PRODUCTOS -->
							<div class="sync-phase phase-2" id="phase2-container">
								<div class="phase-header">
									<h3>
										<span class="phase-icon">📦</span>
										<?php esc_html_e('Fase 2: Sincronización de Productos', 'mi-integracion-api'); ?>
										<span class="phase-status phase-status-<?php echo esc_attr($phase2_status); ?>" id="phase2-status">
											<?php
											echo match($phase2_status) {
												'running' => '🔄 ' . esc_html__('En Progreso', 'mi-integracion-api'),
												'completed' => '✅ ' . esc_html__('Completado', 'mi-integracion-api'),
												'error' => '❌ ' . esc_html__('Error', 'mi-integracion-api'),
												'paused' => '⏸ ' . esc_html__('Pausado', 'mi-integracion-api'),
												default => '⏳ ' . esc_html__('Pendiente', 'mi-integracion-api')
											};
											?>
										</span>
									</h3>
									<div class="phase-controls">
										<button id="start-phase2" class="button button-primary">
											🚀 <?php esc_html_e('Iniciar Fase 2', 'mi-integracion-api'); ?>
										</button>
										<button id="pause-phase2" class="button button-secondary" disabled style="display: none;">
											⏸ <?php esc_html_e('Pausar', 'mi-integracion-api'); ?>
										</button>
										<button id="mi-cancel-sync" class="button button-secondary" <?php echo $in_progress ? '' : 'disabled'; ?> style="display: <?php echo $in_progress ? 'inline-block' : 'none'; ?>;">
											<?php esc_html_e('Cancelar Sincronización', 'mi-integracion-api'); ?>
										</button>
									</div>
								</div>

								<div class="phase-stats">
									<div class="stat-card">
										<div class="stat-value" id="phase2-products"><?php echo esc_html($phase2_processed); ?></div>
										<div class="stat-label"><?php esc_html_e('Productos Sincronizados', 'mi-integracion-api'); ?></div>
									</div>
									<div class="stat-card">
										<div class="stat-value" id="phase2-created"><?php echo esc_html($phase2_created); ?></div>
										<div class="stat-label"><?php esc_html_e('Creados', 'mi-integracion-api'); ?></div>
									</div>
									<div class="stat-card">
										<div class="stat-value" id="phase2-updated"><?php echo esc_html($phase2_updated); ?></div>
										<div class="stat-label"><?php esc_html_e('Actualizados', 'mi-integracion-api'); ?></div>
									</div>
									<div class="stat-card">
										<div class="stat-value" id="phase2-errors"><?php echo esc_html($phase2_errors); ?></div>
										<div class="stat-label"><?php esc_html_e('Errores', 'mi-integracion-api'); ?></div>
									</div>
								</div>

								<div class="phase-timer">
									<span class="timer-label"><?php esc_html_e('Tiempo transcurrido:', 'mi-integracion-api'); ?></span>
									<span class="timer-value" id="phase2-timer">00:00:00</span>
									<span class="timer-label"><?php esc_html_e('Velocidad:', 'mi-integracion-api'); ?></span>
									<span class="timer-value" id="phase2-speed">0 <?php esc_html_e('productos/seg', 'mi-integracion-api'); ?></span>
								</div>
							</div>

							<!-- PANEL DE CONFIGURACIÓN -->
							<div class="config-panel">
								<h4>⚙️ <?php esc_html_e('Configuración Avanzada', 'mi-integracion-api'); ?></h4>
								<div class="config-options">
									<div class="config-item">
										<label for="batch-size"><?php esc_html_e('Tamaño de Lote:', 'mi-integracion-api'); ?></label>
										<select id="batch-size" name="batch-size" <?php echo ($phase1_in_progress || $in_progress) ? 'disabled' : ''; ?>>
											<?php foreach ($options as $option): ?>
												<?php if ($option >= $min_size && $option <= $max_size): ?>
													<option value="<?php echo esc_attr($option); ?>" <?php selected($current_batch_size, $option); ?>>
														<?php echo esc_html($option); ?>
													</option>
												<?php endif; ?>
											<?php endforeach; ?>
										</select>
									</div>
									<div class="config-item">
										<label for="throttle-delay"><?php esc_html_e('Delay Throttling (ms):', 'mi-integracion-api'); ?></label>
										<?php
										$throttle_delay = (float)get_option('mia_images_sync_throttle_delay', 0.01);
										$throttle_delay_ms = round($throttle_delay * 1000, 0);
										?>
										<input type="number" id="throttle-delay" name="throttle-delay" value="<?php echo esc_attr($throttle_delay_ms); ?>" min="0" max="5000" step="10" <?php echo ($phase1_in_progress || $in_progress) ? 'disabled' : ''; ?>>
									</div>
									<div class="config-item">
										<label>
											<?php
											$auto_retry_enabled = (bool)get_option('mia_sync_auto_retry', true);
											?>
											<input type="checkbox" id="auto-retry" name="auto-retry" <?php checked($auto_retry_enabled, true); ?> <?php echo ($phase1_in_progress || $in_progress) ? 'disabled' : ''; ?>>
											<?php esc_html_e('Reintento Automático', 'mi-integracion-api'); ?>
										</label>
									</div>
								</div>
							</div>
						</div>

			<div class="mi-integracion-api-dashboard">
				<div class="mi-integracion-api-stats-grid">
					<?php
					$memory_status = self::get_memory_status();
					$retry_status = self::get_retry_status();
					$sync_status = self::getSyncStatus();
					?>
					
					<!-- Estado de memoria -->
					<div class="mi-integracion-api-stat-card memory <?php echo esc_attr($memory_status['status']); ?>" data-card-type="memory">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#e3f2fd;"><span class="dashicons dashicons-performance"></span></span>
							<?php esc_html_e( 'Estado de Memoria', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value"><?php echo esc_html($memory_status['usage_percentage']); ?>%</div>
						<div class="mi-integracion-api-stat-desc"><?php echo esc_html($memory_status['status_message']); ?></div>
					</div>

					<!-- Estado de reintentos -->
					<div class="mi-integracion-api-stat-card retries <?php echo esc_attr($retry_status['status']); ?>" data-card-type="retries">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#fff3e0;"><span class="dashicons dashicons-update"></span></span>
							<?php esc_html_e( 'Sistema de Reintentos', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value"><?php echo esc_html($retry_status['success_rate']); ?>%</div>
						<div class="mi-integracion-api-stat-desc"><?php echo esc_html($retry_status['status_message']); ?></div>
					</div>

					<!-- Estado de sincronización -->
					<div class="mi-integracion-api-stat-card sync <?php echo esc_attr($sync_status['status']); ?>" data-card-type="sync">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#e8f5e9;"><span class="dashicons dashicons-update"></span></span>
							<?php esc_html_e( 'Sincronización', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value"><?php echo esc_html($sync_status['status_text']); ?></div>
						<div class="mi-integracion-api-stat-desc"><?php echo esc_html($sync_status['progress_message']); ?></div>
					</div>

					<div class="mi-integracion-api-stat-card products" data-card-type="products">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#e8f5e9;"><span class="dashicons dashicons-products"></span></span>
							<?php esc_html_e( 'Productos sincronizados', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value"><?php echo self::getSyncedProductsCount(); ?></div>
						<div class="mi-integracion-api-stat-desc"><?php esc_html_e( 'Total sincronizados', 'mi-integracion-api' ); ?></div>
					</div>
					<div class="mi-integracion-api-stat-card orders" data-card-type="orders">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#ffebee;"><span class="dashicons dashicons-cart"></span></span>
							<?php esc_html_e( 'Errores recientes', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value mi-integracion-api-stat-error"><?php echo intval( get_option( 'mia_last_sync_errors', 0 ) ); ?></div>
						<div class="mi-integracion-api-stat-desc"><?php esc_html_e( 'Errores en la última sync', 'mi-integracion-api' ); ?></div>
					</div>
					<div class="mi-integracion-api-stat-card last-sync" data-card-type="last-sync">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#e3f2fd;"><span class="dashicons dashicons-clock"></span></span>
							<?php esc_html_e( 'Última sincronización', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value">
							<?php
							$last_sync_time = get_option( 'mia_last_sync_time' );
							echo $last_sync_time
								? esc_html( date_i18n( 'd/m/Y H:i', $last_sync_time ) )
								: esc_html__( 'Nunca', 'mi-integracion-api' );
							?>
						</div>
						<div class="mi-integracion-api-stat-desc"><?php esc_html_e( 'Fecha y hora', 'mi-integracion-api' ); ?></div>
					</div>
				</div>
			</div>
			<!-- Recomendaciones y diagnóstico automático -->
			<div class="mi-diagnostic-recommendations">
				<h2><?php esc_html_e( 'Recomendaciones del Sistema', 'mi-integracion-api' ); ?></h2>
				<div class="mi-recommendations-grid">
					<?php
					$recommendations = self::getsystemrecommendations();
					if (!empty($recommendations)): ?>
						<?php foreach ($recommendations as $recommendation): ?>
							<div class="mi-recommendation-item <?php echo esc_attr($recommendation['priority']); ?>">
								<div class="recommendation-icon">
									<?php if ($recommendation['priority'] === 'critical'): ?>
										<span class="dashicons dashicons-warning" style="color: #dc3232;"></span>
									<?php elseif ($recommendation['priority'] === 'high'): ?>
										<span class="dashicons dashicons-warning" style="color: #ffb900;"></span>
									<?php elseif ($recommendation['priority'] === 'medium'): ?>
										<span class="dashicons dashicons-info" style="color: #00a0d2;"></span>
									<?php else: ?>
										<span class="dashicons dashicons-lightbulb" style="color: #46b450;"></span>
									<?php endif; ?>
								</div>
								<div class="recommendation-content">
									<h4><?php echo esc_html($recommendation['title']); ?></h4>
									<p><?php echo esc_html($recommendation['description']); ?></p>
									<?php if (!empty($recommendation['actions'])): ?>
										<div class="recommendation-actions">
											<?php foreach ($recommendation['actions'] as $action): ?>
												<a href="<?php echo esc_url($action['url']); ?>" class="button button-small">
													<?php echo esc_html($action['text']); ?>
												</a>
											<?php endforeach; ?>
										</div>
									<?php endif; ?>
								</div>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<div class="mi-no-recommendations">
							<span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
							<p><?php esc_html_e( 'El sistema está funcionando correctamente. No hay recomendaciones en este momento.', 'mi-integracion-api' ); ?></p>
						</div>
					<?php endif; ?>
				</div>
			</div>
		</div> <!-- Cierre del contenido principal -->
	</div>
		<?php
		// Punto de extensión para añadir contenido adicional al dashboard
		do_action('mi_integracion_api_after_main_dashboard');
		
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
		
		// Cargar JavaScript del sidebar unificado
		wp_enqueue_script(
			'mi-integracion-api-unified-sidebar',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/unified-sidebar.js',
			array('jquery'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar constants.js primero (no tiene dependencias)
		wp_register_script(
			'mi-integracion-api-constants',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/constants.js',
			array(),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar messages.js (depende de constants, opcional)
		wp_register_script(
			'mi-integracion-api-messages',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/messages.js',
			array('mi-integracion-api-constants'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar dashboard-config.js (depende de constants y messages)
		wp_register_script(
			'mi-integracion-api-dashboard-config',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/config/dashboard-config.js',
			array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-messages'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar DomUtils.js (depende de dashboard-config y constants)
		wp_register_script(
			'mi-integracion-api-dom-utils',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/utils/DomUtils.js',
			array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar PollingManager.js (depende de constants y dashboard-config)
		wp_register_script(
			'mi-integracion-api-polling-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/PollingManager.js',
			array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar SyncStateManager.js (depende de polling-manager)
		wp_register_script(
			'mi-integracion-api-sync-state-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/SyncStateManager.js',
			array('jquery', 'mi-integracion-api-polling-manager', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar NonceManager.js (depende de jquery)
		wp_register_script(
			'mi-integracion-api-nonce-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/managers/NonceManager.js',
			array('jquery'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar ToastManager.js (depende de jquery y dashboard-config)
		wp_register_script(
			'mi-integracion-api-toast-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ToastManager.js',
			array('jquery', 'mi-integracion-api-dashboard-config'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar ProgressBar.js (depende de jquery y dom-utils)
		wp_register_script(
			'mi-integracion-api-progress-bar',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ProgressBar.js',
			array('jquery', 'mi-integracion-api-dom-utils'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar ConsoleManager.js (depende de jquery)
		wp_register_script(
			'mi-integracion-api-console-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/ConsoleManager.js',
			array('jquery'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar SyncProgress.js (depende de múltiples módulos)
		wp_register_script(
			'mi-integracion-api-sync-progress',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/sync/SyncProgress.js',
			array(
				'jquery',
				'mi-integracion-api-constants',
				'mi-integracion-api-dashboard-config',
				'mi-integracion-api-dom-utils',
				'mi-integracion-api-polling-manager',
				'mi-integracion-api-sync-state-manager',
				'mi-integracion-api-console-manager',
				'mi-integracion-api-error-handler',
				'mi-integracion-api-ajax-manager'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar Phase1Manager.js (depende de múltiples módulos)
		wp_register_script(
			'mi-integracion-api-phase1-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/sync/Phase1Manager.js',
			array(
				'jquery',
				'mi-integracion-api-constants',
				'mi-integracion-api-dashboard-config',
				'mi-integracion-api-dom-utils',
				'mi-integracion-api-polling-manager',
				'mi-integracion-api-sync-state-manager',
				'mi-integracion-api-console-manager',
				'mi-integracion-api-error-handler'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar Phase2Manager.js (depende de múltiples módulos)
		wp_register_script(
			'mi-integracion-api-phase2-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/sync/Phase2Manager.js',
			array(
				'jquery',
				'mi-integracion-api-constants',
				'mi-integracion-api-dashboard-config',
				'mi-integracion-api-dom-utils',
				'mi-integracion-api-polling-manager',
				'mi-integracion-api-sync-state-manager',
				'mi-integracion-api-error-handler',
				'mi-integracion-api-sync-progress'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar SyncController.js (depende de múltiples módulos)
		wp_register_script(
			'mi-integracion-api-sync-controller',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/sync/SyncController.js',
			array(
				'jquery',
				'mi-integracion-api-constants',
				'mi-integracion-api-dashboard-config',
				'mi-integracion-api-dom-utils',
				'mi-integracion-api-phase1-manager',
				'mi-integracion-api-sync-state-manager',
				'mi-integracion-api-error-handler'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar SyncDashboard.js (depende de múltiples módulos)
		wp_register_script(
			'mi-integracion-api-sync-dashboard',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/SyncDashboard.js',
			array(
				'jquery',
				'mi-integracion-api-constants',
				'mi-integracion-api-dashboard-config',
				'mi-integracion-api-ajax-manager',
				'mi-integracion-api-polling-manager',
				'mi-integracion-api-console-manager'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar UnifiedDashboard.js (depende de múltiples módulos)
		wp_register_script(
			'mi-integracion-api-unified-dashboard',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/components/UnifiedDashboard.js',
			array(
				'jquery',
				'mi-integracion-api-constants',
				'mi-integracion-api-ajax-manager',
				'mi-integracion-api-error-handler',
				'mi-integracion-api-polling-manager'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar ResponsiveLayout.js
		wp_register_script(
			'mi-integracion-api-responsive-layout',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/ui/ResponsiveLayout.js',
			array('jquery'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar CardManager.js (depende de SELECTORS y ToastManager)
		wp_register_script(
			'mi-integracion-api-card-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/ui/CardManager.js',
			array(
				'jquery',
				'mi-integracion-api-constants',
				'mi-integracion-api-toast-manager'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar UnifiedDashboardController.js (depende de múltiples módulos)
		wp_register_script(
			'mi-integracion-api-unified-dashboard-controller',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/controllers/UnifiedDashboardController.js',
			array(
				'jquery',
				'mi-integracion-api-unified-dashboard',
				'mi-integracion-api-sync-dashboard',
				'mi-integracion-api-responsive-layout',
				'mi-integracion-api-event-manager'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar ErrorHandler.js como dependencia
		wp_register_script(
			'mi-integracion-api-error-handler',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/ErrorHandler.js',
			array('jquery', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar AjaxManager.js como dependencia (depende de ErrorHandler)
		wp_register_script(
			'mi-integracion-api-ajax-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/AjaxManager.js',
			array('jquery', 'mi-integracion-api-error-handler', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Registrar EventManager.js como dependencia (depende de ErrorHandler y AjaxManager)
		wp_register_script(
			'mi-integracion-api-event-manager',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/core/EventManager.js',
			array('jquery', 'mi-integracion-api-error-handler', 'mi-integracion-api-ajax-manager', 'mi-integracion-api-constants', 'mi-integracion-api-dashboard-config'),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		// Cargar JavaScript del dashboard para funcionalidad de sincronización
		// ✅ CORRECCIÓN: Agregar console-manager como dependencia para asegurar que se cargue antes
		wp_enqueue_script(
			'mi-integracion-api-dashboard',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard/dashboard.js',
			array(
				'jquery',
				'mi-integracion-api-unified-sidebar',
				'mi-integracion-api-constants',
				'mi-integracion-api-dashboard-config',
				'mi-integracion-api-error-handler',
				'mi-integracion-api-ajax-manager',
				'mi-integracion-api-event-manager',
				'mi-integracion-api-polling-manager',
				'mi-integracion-api-sync-state-manager',
				'mi-integracion-api-console-manager'
			),
			constant('MiIntegracionApi_VERSION'),
			true
		);
		
		wp_enqueue_script('mi-integracion-api-constants');
		wp_enqueue_script('mi-integracion-api-messages');
		wp_enqueue_script('mi-integracion-api-dashboard-config');
		wp_enqueue_script('mi-integracion-api-dom-utils');
		wp_enqueue_script('mi-integracion-api-polling-manager');
		wp_enqueue_script('mi-integracion-api-sync-state-manager');
		wp_enqueue_script('mi-integracion-api-nonce-manager');
		wp_enqueue_script('mi-integracion-api-toast-manager');
		wp_enqueue_script('mi-integracion-api-progress-bar');
		wp_enqueue_script('mi-integracion-api-console-manager');
		wp_enqueue_script('mi-integracion-api-sync-progress');
		wp_enqueue_script('mi-integracion-api-phase1-manager');
		wp_enqueue_script('mi-integracion-api-phase2-manager');
		wp_enqueue_script('mi-integracion-api-sync-controller');
		wp_enqueue_script('mi-integracion-api-sync-dashboard');
		wp_enqueue_script('mi-integracion-api-unified-dashboard');
		wp_enqueue_script('mi-integracion-api-responsive-layout');
		wp_enqueue_script('mi-integracion-api-card-manager');
		wp_enqueue_script('mi-integracion-api-unified-dashboard-controller');
		
		// Localizar script con datos de WordPress para el dashboard
		wp_localize_script('mi-integracion-api-dashboard', 'miIntegracionApiDashboard', array(
			'ajaxurl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('mi_integracion_api_nonce_dashboard'),
			'memory_nonce' => wp_create_nonce('mia_memory_nonce'),
			'restUrl' => rest_url('mi-integracion-api/v1/'),
			'confirmSync' => __('¿Iniciar sincronización de productos? Esta acción puede tomar varios minutos.', 'mi-integracion-api'),
			'confirmCancel' => __('¿Seguro que deseas cancelar la sincronización?', 'mi-integracion-api'),
			// ✅ NUEVO: Mensajes de advertencia para Fase 2
			'warningPhase2WithoutPhase1' => __('⚠️ Advertencia: La Fase 1 (sincronización de imágenes) no se ha completado. Se recomienda completar la Fase 1 primero para obtener mejores resultados. ¿Deseas continuar de todos modos?', 'mi-integracion-api'),
			'warningPhase2InProgress' => __('Ya hay una sincronización en progreso. ¿Deseas continuar de todos modos?', 'mi-integracion-api'),
			'debug' => defined('WP_DEBUG') && constant('WP_DEBUG'),
			// Configuración de polling desde Sync_Manager
			'pollingConfig' => apply_filters('mia_polling_config',
				Sync_Manager::get_instance()->getPollingConfiguration()
			),
			// Configuración de timeouts desde Sync_Manager
			'timeoutConfig' => Sync_Manager::get_instance()->getTimeoutConfiguration(),
			// Configuración de límites desde Sync_Manager
			'limitsConfig' => Sync_Manager::get_instance()->getLimitsConfiguration(),
		));
	}

	/**
	 * Redondea un número de forma segura
	 * @param float|int|null $num Número a redondear
	 * @param int $precision Precisión decimal
	 * @return float Número redondeado
	 */
	private static function safe_round(float|int|null $num, int $precision = 0): float
	{
		// Si el número es nulo o no es numérico, devolver 0.0
		if ($num === null || !is_numeric($num)) {
			return 0.0;
		}

		// Convertir a float y redondear directamente
		return round((float)$num, $precision);
	}

	/**
	 * Obtiene el estado básico de memoria como fallback
	 * @return array Estado básico de memoria
	 */
	private static function get_basic_memory_status(): array
	{
		$current_usage = self::safe_round(memory_get_usage(true) / 1024 / 1024, 2);
		$memory_limit = ini_get('memory_limit');
		$limit_mb = self::convertToMB($memory_limit);
		$usage_percentage = $limit_mb > 0 ? self::safe_round(($current_usage / $limit_mb) * 100, 2) : 0;
		
		$status = 'healthy';
		if ($usage_percentage > 80) $status = 'critical';
		elseif ($usage_percentage > 60) $status = 'warning';
		
		return [
			'status' => $status,
			'usage_percentage' => $usage_percentage,
			'status_message' => self::getmemorystatusmessage($status, $usage_percentage)
		];
	}

	/**
	 * Obtiene el estado general de salud del sistema
	 * Evalúa diferentes aspectos del sistema para determinar su estado general:
	 * - Uso de memoria y límites
	 * - Estado de los reintentos de sincronización
	 * - Errores recientes en logs
	 * - Estado de la conexión con la API
	 * @return array {
	 *     Estado del sistema con las siguientes claves:
	 *     @type string $overall_status Estado general ('healthy', 'warning', 'critical')
	 *     @type array  $issues         Lista de problemas detectados
	 *     @type array  $memory         Estado de la memoria
	 *     @type array  $retry          Estado de los reintentos
	 *     @type array  $api            Estado de la API
	 * }
	 * @since 1.0.0
	 */
	private static function get_system_health(): array
	{
		$issues = [];
		$overall_status = 'healthy';
		
		// Verificar memoria
		$memory_status = self::get_memory_status();
		if ($memory_status['status'] === 'critical') {
			$issues[] = 'Memoria crítica';
			$overall_status = 'critical';
		} elseif ($memory_status['status'] === 'warning') {
			$issues[] = 'Memoria alta';
			$overall_status = 'warning';
		}
		
		// Verificar reintentos
		$retry_status = self::get_retry_status();
		if ($retry_status['status'] === 'critical') {
			$issues[] = 'Sistema de reintentos crítico';
			$overall_status = 'critical';
		} elseif ($retry_status['status'] === 'warning') {
			$issues[] = 'Sistema de reintentos con problemas';
			$overall_status = 'warning';
		}
		
		// Verificar sincronización
		$sync_status = self::getSyncStatus();
		if ($sync_status['status'] === 'critical') {
			$issues[] = 'Sincronización crítica';
			$overall_status = 'critical';
		} elseif ($sync_status['status'] === 'warning') {
			$issues[] = 'Sincronización con problemas';
			$overall_status = 'warning';
		}
		
		// Determinar mensaje general
		$overall_message = match($overall_status) {
			'critical' => 'El sistema tiene problemas críticos que requieren atención inmediata.',
			'warning' => 'El sistema tiene algunos problemas que requieren monitoreo.',
			default => 'El sistema está funcionando correctamente.'
		};
		
		return [
			'overall_status' => $overall_status,
			'overall_message' => $overall_message,
			'last_check' => current_time('mysql'),
			'issues_count' => count($issues),
			'issues' => $issues
		];
	}

	/**
	 * Obtiene el estado de la memoria del sistema
	 * Analiza el uso actual de memoria, límites configurados y genera
	 * recomendaciones basadas en el estado del sistema.
	 * @return array {
	 *     Estado de memoria con las siguientes claves:
	 *     @type string $status        Estado ('healthy', 'warning', 'critical')
	 *     @type int    $current_mb    Memoria actual en MB
	 *     @type int    $limit_mb      Límite de memoria en MB
	 *     @type float  $percentage    Porcentaje de uso de memoria
	 *     @type string $message       Mensaje descriptivo del estado
	 *     @type array  $recommendations Recomendaciones para optimizar memoria
	 * }
	 * @since 1.0.0
	 */
	public static function get_memory_status(): array
	{
		// Verificar si la clase existe
		$class_exists = class_exists('\\MiIntegracionApi\\Core\\MemoryManager');
		
		if (!$class_exists) {
			// Fallback: Obtener datos básicos de memoria
			return self::get_basic_memory_status();
		}
		
		try {
			$memory_manager = MemoryManager::getInstance();
			$stats = $memory_manager->getAdvancedMemoryStats();
			
			return [
				'status' => $stats['status'],
				'usage_percentage' => $stats['usage_percentage'],
				'status_message' => self::getmemorystatusmessage($stats['status'], $stats['usage_percentage'])
			];
		} catch (Exception) {
			// Fallback en caso de error
			return self::get_basic_memory_status();
		}
	}
	
	/**
	 * Convierte límite de memoria a MB
	 */
	private static function convertToMB($memory_limit): float
	{
		$memory_limit = trim($memory_limit);
		$last = strtolower($memory_limit[strlen($memory_limit) - 1]);
		$value = (float) $memory_limit;
		
		switch ($last) {
			case 'g':
				$value *= 1024;
				break;
			case 'm':
				// Ya está en MB
				break;
			case 'k':
				$value /= 1024;
				break;
		}
		
		return $value;
	}

	/**
	 * Obtiene el estado del sistema de reintentos
	 * @return array Estado de reintentos
	 */
	public static function get_retry_status(): array
	{
		// Obtener estadísticas de reintentos desde las opciones
		$total_attempts = get_option('mia_retry_total_attempts', 0);
		$successful_attempts = get_option('mia_retry_successful_attempts', 0);
		$failed_attempts = get_option('mia_retry_failed_attempts', 0);
		
		if ($total_attempts === 0) {
			// Si no hay datos de reintentos, simular datos realistas basados en el estado del sistema
			$recent_errors = get_option('mia_last_sync_errors', 0);
			$recent_syncs = get_option('mia_last_sync_count', 0);
			
			// Calcular success rate basado en errores y sincronizaciones recientes
			if ($recent_syncs > 0) {
				// Si hay sincronizaciones recientes, calcular basado en la proporción de errores
				$error_rate = ($recent_errors / $recent_syncs) * 100;
				$success_rate = max(0, 100 - $error_rate);
			} elseif ($recent_errors > 0) {
				// Si solo hay errores sin sincronizaciones, penalizar fuertemente
				$success_rate = max(0, 100 - ($recent_errors * 10));
			} else {
				// Sin datos recientes, asumir sistema saludable
				$success_rate = 100;
			}
			
			$status = $success_rate < 60 ? 'critical' : ($success_rate < 80 ? 'warning' : 'healthy');
			
			$message = $recent_syncs > 0
				? "Basado en {$recent_syncs} sincronizaciones recientes con {$recent_errors} errores"
				: ($recent_errors > 0 ? "Basado en errores recientes: $recent_errors" : 'Sistema funcionando correctamente');
			
			return [
				'status' => $status,
				'success_rate' => $success_rate,
				'status_message' => $message,
				'recent_syncs' => $recent_syncs,
				'recent_errors' => $recent_errors
			];
		}
		
		// Validar consistencia de datos
		$calculated_failed = $total_attempts - $successful_attempts;
		if ($failed_attempts !== $calculated_failed) {
			// Log de inconsistencia de datos
			error_log("Mi Integración API: Inconsistencia en datos de reintentos. Esperado: $calculated_failed, Actual: $failed_attempts");
		}
		
		$precision = get_option('mia_dashboard_percentage_precision', 1);
		$success_rate = self::safe_round(($successful_attempts / $total_attempts) * 100, $precision);
		
		// ✅ ELIMINADO HARDCODEO: Thresholds configurables
		$thresholds = [
			'healthy' => get_option('mia_retry_success_healthy_threshold', 95),
			'attention' => get_option('mia_retry_success_attention_threshold', 80),
			'warning' => get_option('mia_retry_success_warning_threshold', 60)
		];
		
		$status = match(true) {
			$success_rate >= $thresholds['healthy'] => 'healthy',
			$success_rate >= $thresholds['attention'] => 'attention',
			$success_rate >= $thresholds['warning'] => 'warning',
			default => 'critical'
		};
		
		return [
			'status' => $status,
			'success_rate' => $success_rate,
			'status_message' => self::getretrystatusmessage($status, $success_rate),
			'failed_attempts' => $failed_attempts,
			'total_attempts' => $total_attempts,
			'successful_attempts' => $successful_attempts
		];
	}

	/**
	 * Obtiene el número de productos sincronizados desde la base de datos
	 * @return int Número de productos sincronizados
	 */
	public static function getSyncedProductsCount(): int
	{
		// Verificaciones previas - early returns
		if (!class_exists('WC_Product') || !class_exists('WP_Query')) {
			return 0;
		}

		try {
			// OPTIMIZACIÓN: Usar caché para evitar consultas costosas repetidas
			$cache_key = 'mia_synced_products_count';
			$cached_count = get_transient($cache_key);
			
			if ($cached_count !== false) {
				return (int) $cached_count;
			}

			// Intentar contar productos con metadata de Verial
			$count_with_verial = self::countProductsWithVerialMetadata();
			
			// Si hay productos con metadata de Verial, devolver ese conteo
			if ($count_with_verial > 0) {
				// Guardar en caché por 5 minutos
				set_transient($cache_key, $count_with_verial, 300);
				return $count_with_verial;
			}

			// Fallback: contar todos los productos
			$total_count = self::countAllProducts();
			
			// Guardar en caché por 5 minutos
			set_transient($cache_key, $total_count, 300);
			return $total_count;

		} catch (Exception) {
			return 0;
		}
	}

	/**
	 * Limpia el caché del conteo de productos sincronizados
	 * @return void
	 */
	public static function clearSyncedProductsCountCache(): void
	{
		delete_transient('mia_synced_products_count');
	}

	/**
	 * Contar productos con metadata de Verial
	 * @return int Número de productos con metadata de Verial
	 */
	private static function countProductsWithVerialMetadata(): int
	{
		// OPTIMIZACIÓN: Usar consulta SQL directa para mejor rendimiento
		global $wpdb;
		
		$count = $wpdb->get_var($wpdb->prepare("
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
			WHERE p.post_type = %s
			AND p.post_status = %s
			AND pm.meta_key = %s
		", 'product', 'publish', '_verial_last_sync'));
		
		return (int) $count;
	}

	/**
	 * Contar todos los productos
	 * @return int Número total de productos
	 */
	private static function countAllProducts(): int
	{
		// OPTIMIZACIÓN: Usar consulta SQL directa para mejor rendimiento
		global $wpdb;
		
		$count = $wpdb->get_var($wpdb->prepare("
			SELECT COUNT(*)
			FROM {$wpdb->posts}
			WHERE post_type = %s
			AND post_status = %s
		", 'product', 'publish'));
		
		return (int) $count;
	}

	/**
	 * Obtiene el estado de la sincronización
	 * @return array Estado de sincronización
	 */
	public static function getSyncStatus(): array
	{
		// Verificar si la clase existe
		$class_exists = class_exists('\\MiIntegracionApi\\Core\\Sync_Manager');
		
		if (!$class_exists) {
			// Fallback: Obtener datos básicos de sincronización
			$last_sync_count = get_option('mia_last_sync_count', 0);
			$last_sync_errors = get_option('mia_last_sync_errors', 0);
			$last_sync_time = get_option('mia_last_sync_timestamp', 0);
			
			$status = 'completed';
			$status_text = 'Completada';
			
			if ($last_sync_errors > 0) {
				$status = $last_sync_errors > 10 ? 'critical' : 'warning';
				$status_text = 'Con errores';
			}
			
			$progress_message = "Completada: $last_sync_count elementos procesados";
			if ($last_sync_errors > 0) {
				$progress_message .= ", $last_sync_errors errores";
			}
			
			// Añadir información temporal si está disponible
			if ($last_sync_time > 0) {
				$formatted_time = date_i18n('d/m/Y H:i', $last_sync_time);
				$progress_message .= " (Última sincronización: $formatted_time)";
			}
			
			return [
				'status' => $status,
				'status_text' => $status_text,
				'progress_message' => $progress_message,
				'last_sync_time' => $last_sync_time
			];
		}
		
		try {
			$sync_manager = Sync_Manager::get_instance();
			$sync_response = $sync_manager->getSyncStatus();
			
			// CORRECCIÓN: Usar el objeto SyncResponse correctamente
			$sync_status = $sync_response->getData();
			$in_progress = $sync_status['current_sync']['in_progress'] ?? false;
			$status = $in_progress ? 'running' : 'completed';
			$progress = 0;
			$total_items = $sync_status['current_sync']['total_items'] ?? 0;
			$processed_items = $sync_status['current_sync']['items_synced'] ?? 0;
			
			// Calcular progreso si hay elementos totales
			if ($total_items > 0) {
				$progress = min(100, self::safe_round(($processed_items / $total_items) * 100, 1));
			}
			
			$status_text = match($status) {
				'running' => 'En progreso',
				'completed' => 'Completada',
				default => 'Desconocido'
			};
			
			$progress_message = match($status) {
				'running' => "Procesando: $processed_items/$total_items ($progress%)",
				'completed' => "Completada: $total_items elementos procesados",
				default => 'Estado desconocido'
			};
			
			// Determinar estado crítico
			$critical_status = match(true) {
				$status === 'running' && $progress > 0 && $progress < 100 => 'attention',
				default => 'healthy'
			};
			
			return [
				'status' => $critical_status,
				'status_text' => $status_text,
				'progress_message' => $progress_message
			];
		} catch (Exception $e) {
			return [
				'status' => 'error',
				'status_text' => 'Error',
				'progress_message' => 'Error al obtener estado de sincronización'
			];
		}
	}

	/**
	 * Obtiene recomendaciones del sistema basadas en el estado actual
	 * @return array Lista de recomendaciones
	 */
	private static function getsystemrecommendations(): array
	{
		$recommendations = [];
		
		// Verificar memoria
		$memory_status = self::get_memory_status();
		if ($memory_status['status'] === 'critical') {
			$recommendations[] = [
				'priority' => 'critical',
				'title' => 'Memoria Crítica',
				'description' => 'El uso de memoria ha alcanzado niveles críticos. Esto puede causar fallos en el sistema.',
				'actions' => [
					[
						'text' => 'Ver Dashboard de Memoria',
						'url' => admin_url('admin.php?page=mi-integracion-api-memory-monitoring')
					],
					[
						'text' => 'Limpiar Memoria',
						'url' => '#'
					]
				]
			];
		} elseif ($memory_status['status'] === 'warning') {
			$recommendations[] = [
				'priority' => 'high',
				'title' => 'Memoria Alta',
				'description' => 'El uso de memoria está por encima del umbral recomendado. Monitorear de cerca.',
				'actions' => [
					[
						'text' => 'Ver Dashboard de Memoria',
						'url' => admin_url('admin.php?page=mi-integracion-api-memory-monitoring')
					]
				]
			];
		}
		
		// Verificar reintentos
		$retry_status = self::get_retry_status();
		if ($retry_status['status'] === 'critical') {
			$recommendations[] = [
				'priority' => 'critical',
				'title' => 'Sistema de Reintentos Crítico',
				'description' => 'La tasa de éxito de reintentos es muy baja. Revisar configuración y logs.',
				'actions' => [
					[
						'text' => 'Ver Configuración de Reintentos',
						'url' => admin_url('admin.php?page=mi-integracion-api-retry-settings')
					],
					[
						'text' => 'Ver Logs',
						'url' => admin_url('admin.php?page=mi-integracion-api-logs')
					]
				]
			];
		}
		
		// Verificar sincronización
		$sync_status = self::getSyncStatus();
		if ($sync_status['status'] === 'critical') {
			$recommendations[] = [
				'priority' => 'critical',
				'title' => 'Sincronización Fallida',
				'description' => 'La sincronización ha fallado. Revisar logs y estado del sistema.',
				'actions' => [
					[
						'text' => 'Ver Logs',
						'url' => admin_url('admin.php?page=mi-integracion-api-logs')
					],
					[
						'text' => 'Reintentar Sincronización',
						'url' => '#'
					]
				]
			];
		}
		
		// Recomendaciones generales si no hay problemas críticos
		if (empty($recommendations)) {
			$recommendations[] = [
				'priority' => 'low',
				'title' => 'Sistema Saludable',
				'description' => 'El sistema está funcionando correctamente. Continuar monitoreando para mantener el rendimiento.',
				'actions' => []
			];
		}
		
		return $recommendations;
	}

	/**
	 * Obtiene mensaje de estado de memoria
	 */
	private static function getmemorystatusmessage(string $status, float $percentage): string
	{
		return match($status) {
			'critical' => "Crítico ($percentage%) - Acción inmediata requerida",
			'warning' => "Alto ($percentage%) - Monitorear de cerca",
			default => "Saludable ($percentage%) - Funcionando correctamente"
		};
	}

	/**
	 * Obtiene mensaje de estado de reintentos
	 */
	private static function getretrystatusmessage(string $status, float $success_rate): string
	{
		return match($status) {
			'critical' => "Crítico ($success_rate%) - Revisar configuración",
			'warning' => "Bajo ($success_rate%) - Monitorear de cerca",
			'attention' => "Moderado ($success_rate%) - Considerar ajustes",
			default => "Excelente ($success_rate%) - Funcionando correctamente"
		};
	}

	/**
	 * Renderiza el banner principal del plugin
	 * @return void
	 */
	private static function renderbanner(): void {
		?>
		<div class="mi-integracion-api-banner">
			<div class="banner-content">
				<div class="banner-text">
					<h1>Sincronización Automática</h1>
					<p>Conecta tu tienda WooCommerce con múltiples plataformas de e-commerce. Mantén tu inventario y productos sincronizados en tiempo real.</p>
					
					<div class="logo">
						<div class="woo">WOOCOMMERCE</div>
						<div class="sync">SYNC</div>
						<div class="shop">ONLINE SHOP</div>
					</div>
				</div>
				
				<div class="banner-visual">
					<div class="product-box box-1">
						<div class="product-tittle">Verial</div>
						<div class="product-label">Producto</div>
						<div class="product-stock">Stock: 25</div>
					</div>
					
					<div class="sync-animation">
						<div class="sync-circle"></div>
					</div>
					
					<div class="arrows">
						<div class="arrow">➔</div>
						<div class="arrow">➔</div>
					</div>
					
					<div class="product-box box-2">
						<div class="product-tittle">WooCommerce</div>
						<div class="product-label">Producto</div>
						<div class="product-stock">Stock: 25</div>
					</div>
					
					<div class="database">
						<div class="db-line"></div>
						<div class="db-line"></div>
						<div class="db-line"></div>
					</div>
				</div>
				
				<!-- Icono de Ayuda -->
				<div class="banner-help">
					<a href="<?php echo esc_url(MiIntegracionApi_PLUGIN_URL . 'docs/manual-usuario/index.html'); ?>"
					   target="_blank"
					   class="help-link"
					   title="<?php esc_attr_e('Abrir Manual de Usuario', 'mi-integracion-api'); ?>">
						<i class="fas fa-question-circle"></i>
						<span><?php esc_html_e('Ayuda', 'mi-integracion-api'); ?></span>
					</a>
				</div>
			</div>
		</div>
		<?php
	}
}
