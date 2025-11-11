/**
 * Dashboard Unificado Completo
 *
 * Gestiona el dashboard completo con diagnóstico automático, métricas del sistema,
 * análisis de salud, y todas las funcionalidades de diagnóstico y monitoreo.
 *
 * @module components/UnifiedDashboard
 * @namespace UnifiedDashboard
 * @since 1.0.0
 * @author Christian
 */

/* global jQuery, AjaxManager, ErrorHandler, pollingManager, SELECTORS, syncInterval */

/**
 * Dashboard unificado con diagnóstico automático
 *
 * @class UnifiedDashboard
 * @description Gestión completa del dashboard y todas sus funcionalidades
 * @namespace UnifiedDashboard
 *
 * @example
 * // Inicializar dashboard (uso directo)
 * UnifiedDashboard.init();
 *
 * // Ejecutar diagnóstico del sistema (uso directo)
 * UnifiedDashboard.runSystemDiagnostic();
 *
 * // También disponible como window.UnifiedDashboard
 * window.UnifiedDashboard.init();
 *
 * @since 1.0.0
 * @author Christian
 */
const UnifiedDashboard = {
  // SIMPLIFICADO: Configuración básica - PHP maneja lógica adaptativa
  config: {
    refreshInterval: 60000, // 60 segundos (base)
    diagnosticTimeout: 30000, // 30 segundos
    debugMode: false // Control de logs verbosos
  },

  // Estado del sistema
  systemState: {
    lastCheck: null,
    overallHealth: 'unknown',
    lastHealth: 'unknown',
    issues: [],
    recommendations: []
  },

  /**
   * Inicializar el dashboard unificado
   * @returns {void}
   * @example
   * UnifiedDashboard.init();
   */
  init() {
    this.bindEvents();
  },

  /**
   * Activar/desactivar modo debug
   * @returns {void}
   * @example
   * UnifiedDashboard.toggleDebugMode();
   */
  toggleDebugMode() {
    this.config.debugMode = !this.config.debugMode;
    const $btn = jQuery('#toggle-debug-mode');
    if (this.config.debugMode) {
      $btn.removeClass('button-secondary').addClass('button-primary');
      $btn.html('<span class="dashicons dashicons-admin-tools"></span> Debug: ON');
      // Modo debug activado
    } else {
      $btn.removeClass('button-primary').addClass('button-secondary');
      $btn.html('<span class="dashicons dashicons-admin-tools"></span> Debug: OFF');
      // Modo debug desactivado
    }
  },

  /**
   * Vincular eventos del dashboard
   * @returns {void}
   * @private
   */
  bindEvents() {
    // Botón de diagnóstico completo
    jQuery(document).on('click', '#run-system-diagnostic', (e) => {
      e.preventDefault();
      this.runSystemDiagnostic();
    });

    // Botón de refrescar estado
    jQuery(document).on('click', '#refresh-system-status', (e) => {
      e.preventDefault();
      this.refreshSystemStatus();
    });

    // Botón de exportar reporte
    jQuery(document).on('click', '#export-system-report', (e) => {
      e.preventDefault();
      this.exportSystemReport();
    });

    // Botón de cargar métricas adicionales
    jQuery(document).on('click', '#load-additional-metrics', (e) => {
      e.preventDefault();
      this.loadAdditionalMetrics();
    });

    // Botón de verificar estado de cron jobs
    jQuery(document).on('click', '#check-cron-status', (e) => {
      e.preventDefault();
      this.checkCronStatus();
    });

    // Botón de toggle debug mode
    jQuery(document).on('click', '#toggle-debug-mode', (e) => {
      e.preventDefault();
      this.toggleDebugMode();
    });

    // CORRECCIÓN DE OPTIMIZACIÓN: Botones para funcionalidades bajo demanda
    jQuery(document).on('click', '#execute-system-diagnostic', (e) => {
      e.preventDefault();
      this.executeSystemDiagnostic();
    });

    jQuery(document).on('click', '#initialize-assets', (e) => {
      e.preventDefault();
      this.initializeAssets();
    });

    jQuery(document).on('click', '#initialize-ajax', (e) => {
      e.preventDefault();
      this.initializeAjax();
    });

    jQuery(document).on('click', '#initialize-settings', (e) => {
      e.preventDefault();
      this.initializeSettings();
    });

    jQuery(document).on('click', '#initialize-cleanup', (e) => {
      e.preventDefault();
      this.initializeCleanup();
    });

    jQuery(document).on('click', '#load-textdomain', (e) => {
      e.preventDefault();
      this.loadTextdomain();
    });

    // CORRECCIÓN DE OPTIMIZACIÓN: Botones para compatibilidad bajo demanda
    jQuery(document).on('click', '#initialize-compatibility-reports', (e) => {
      e.preventDefault();
      this.initializeCompatibilityReports();
    });

    jQuery(document).on('click', '#initialize-theme-compatibility', (e) => {
      e.preventDefault();
      this.initializeThemeCompatibility();
    });

    jQuery(document).on('click', '#initialize-woocommerce-plugin-compatibility', (e) => {
      e.preventDefault();
      this.initializeWooCommercePluginCompatibility();
    });

    jQuery(document).on('click', '#initialize-general-compatibility', (e) => {
      e.preventDefault();
      this.initializeGeneralCompatibility();
    });

    jQuery(document).on('click', '#execute-complete-compatibility-check', (e) => {
      e.preventDefault();
      this.executeCompleteCompatibilityCheck();
    });

    // CORRECCIÓN DE OPTIMIZACIÓN: Botones para hooks adicionales bajo demanda
    jQuery(document).on('click', '#initialize-sync-hooks', (e) => {
      e.preventDefault();
      this.initializeSyncHooks();
    });

    jQuery(document).on('click', '#initialize-ajax-lazy-loading', (e) => {
      e.preventDefault();
      this.initializeAjaxLazyLoading();
    });

    jQuery(document).on('click', '#execute-batch-size-debug', (e) => {
      e.preventDefault();
      this.executeBatchSizeDebug();
    });
  },

  // Ejecutar diagnóstico completo del sistema
  runSystemDiagnostic() {
    const $btn = jQuery('#run-system-diagnostic');
    const $results = jQuery('#diagnostic-results');

    // Mostrar estado de carga
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Ejecutando...');
    $results.show().html('<div class="diagnostic-loading"><span class="dashicons dashicons-update-alt"></span> Ejecutando diagnóstico completo del sistema...</div>');

    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_run_system_diagnostic', {},
        (response) => {
          if (response.success) {
            this.displayDiagnosticResults(response.data);
            this.updateSystemHealth(response.data);
          } else {
            this.showError('Error en diagnóstico: ' + ((response.data && response.data.message) || 'Error desconocido'));
          }
        },
        (xhr, status, error) => {
          this.showError('Error de conexión: ' + error);
        }
      );
    }
  },

  // Refrescar estado del sistema (SOLO MANUAL - NO AUTOMÁTICO)
  refreshSystemStatus() {
    // CONTROL DE ESTADO GLOBAL: Verificar si hay sincronización en progreso
    if (this.isSyncInProgress()) {
      return;
    }

    const $btn = jQuery('#refresh-system-status');

    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_system_status', {}, $btn,
        (response) => {
          if (response.success) {
            this.updateSystemStatus(response.data);
            this.showSuccess('Estado del sistema actualizado correctamente');
          } else {
            this.showError('Error al refrescar: ' + ((response.data && response.data.message) || 'Error desconocido'));
          }
        },
        (xhr, status, error) => {
          this.showError('Error de conexión: ' + error);
        }
      );
    }
  },

  // Exportar reporte del sistema
  exportSystemReport() {
    const $btn = jQuery('#export-system-report');

    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_export_system_report', {}, $btn,
        (response) => {
          if (response.success) {
            this.showSuccess('Reporte exportado correctamente');

            // Crear enlace de descarga
            if (response.data) {
              // Extraer propiedades usando destructuring con valores por defecto
              const { download_url = null, filename = 'reporte.json' } = response.data;

              if (download_url) {
                const $downloadLink = jQuery('<a>')
                  .attr('href', download_url)
                  .attr('download', filename)
                  .addClass('button button-primary')
                  .html('<span class="dashicons dashicons-download"></span> Descargar Reporte');

                $btn.after($downloadLink);

                // Auto-descargar después de 1 segundo
                setTimeout(() => {
                  $downloadLink[0].click();
                  $downloadLink.remove();
                }, 1000);
              }
            }
          } else {
            this.showError('Error al exportar: ' + ((response.data && response.data.message) || 'Error desconocido'));
          }
        },
        (xhr, status, error) => {
          this.showError('Error de conexión: ' + error);
        }
      );
    }
  },

  // Mostrar resultados del diagnóstico
  displayDiagnosticResults(data) {
    const $results = jQuery('#diagnostic-results');

    let html = '<div class="diagnostic-summary">';
    html += '<h3>Resultados del Diagnóstico del Sistema</h3>';
    html += `<p><strong>Ejecutado:</strong> ${new Date(data.timestamp).toLocaleString()}</p>`;

    // Resumen de salud del sistema
    html += '<div class="health-summary">';
    if (data && typeof data === 'object' && 'system_health' in data && data.system_health) {
      // Extraer propiedades usando destructuring con valores por defecto
      const systemHealth = data.system_health;
      const {
        overall_status = 'unknown',
        status_message = null,
        components = null,
        issues_count = 0,
        issues = []
      } = systemHealth;

      html += `<h4>Estado General: <span class="health-status ${overall_status}">${this.getHealthStatusText(overall_status)}</span></h4>`;

      if (status_message) {
        html += `<p><strong>Estado:</strong> ${status_message}</p>`;
      }

      if (issues_count > 0) {
        html += `<p><strong>Problemas detectados:</strong> ${issues_count}</p>`;
        if (issues.length > 0) {
          html += '<ul>';
          issues.forEach(issue => {
            html += `<li>${issue}</li>`;
          });
          html += '</ul>';
        }
      }

      if (components) {
        html += '<div class="health-components">';
        Object.entries(components).forEach(([component, status]) => {
          if (status && status.status) {
            html += `<p><strong>${this.getComponentName(component)}:</strong> <span class="status-${status.status}">${this.getComponentStatusText(status.status)}</span></p>`;
          }
        });
        html += '</div>';
      }
    } else {
      html += '<h4>Estado General: <span class="health-status unknown">Desconocido</span></h4>';
      html += '<p>No se pudo obtener el estado del sistema</p>';
    }
    html += '</div>';

    // Análisis de memoria
    if (data && typeof data === 'object' && 'memory_analysis' in data && data.memory_analysis) {
      // Extraer propiedades usando destructuring con valores por defecto
      const memoryAnalysis = data.memory_analysis;
      const {
        status = 'unknown',
        usage_percentage = null,
        recommendations = []
      } = memoryAnalysis;

      html += '<div class="analysis-section">';
      html += '<h4>Análisis de Memoria</h4>';
      html += `<p><strong>Estado:</strong> <span class="status-${status}">${this.getMemoryStatusText(status)}</span></p>`;

      if (usage_percentage !== null && usage_percentage !== undefined) {
        html += `<p><strong>Uso:</strong> ${usage_percentage}%</p>`;
      }

      if (recommendations && recommendations.length > 0) {
        html += '<ul class="recommendations">';
        recommendations.forEach(rec => {
          html += `<li>${rec}</li>`;
        });
        html += '</ul>';
      }
      html += '</div>';
    }

    // Análisis de reintentos
    if (data && typeof data === 'object' && 'retry_analysis' in data && data.retry_analysis) {
      // Extraer propiedades usando destructuring con valores por defecto
      const retryAnalysis = data.retry_analysis;
      const {
        status = 'unknown',
        success_rate = null,
        recommendations = []
      } = retryAnalysis;

      html += '<div class="analysis-section">';
      html += '<h4>Análisis de Reintentos</h4>';
      html += `<p><strong>Estado:</strong> <span class="status-${status}">${this.getRetryStatusText(status)}</span></p>`;

      if (success_rate !== null && success_rate !== undefined) {
        html += `<p><strong>Tasa de Éxito:</strong> ${success_rate}%</p>`;
      }

      if (recommendations && recommendations.length > 0) {
        html += '<ul class="recommendations">';
        recommendations.forEach(rec => {
          html += `<li>${rec}</li>`;
        });
        html += '</ul>';
      }
      html += '</div>';
    }

    // Análisis de sincronización
    if (data && typeof data === 'object' && 'sync_analysis' in data && data.sync_analysis) {
      // Extraer propiedades usando destructuring con valores por defecto
      const syncAnalysis = data.sync_analysis;
      const {
        status = 'unknown',
        progress = null,
        total_items = null
      } = syncAnalysis;

      html += '<div class="analysis-section">';
      html += '<h4>Análisis de Sincronización</h4>';
      html += `<p><strong>Estado:</strong> <span class="status-${status}">${this.getSyncStatusText(status)}</span></p>`;

      if (progress !== null && progress !== undefined) {
        html += `<p><strong>Progreso:</strong> ${progress}%</p>`;
      }

      if (total_items !== null && total_items !== undefined) {
        html += `<p><strong>Total de elementos:</strong> ${total_items}</p>`;
      }
      html += '</div>';
    }

    // Recomendaciones
    if (data && typeof data === 'object' && 'recommendations' in data && data.recommendations && data.recommendations.length > 0) {
      // Extraer propiedades usando destructuring con valores por defecto
      const recommendations = data.recommendations;

      html += '<div class="recommendations-section">';
      html += '<h4>Recomendaciones del Sistema</h4>';
      html += '<div class="recommendations-grid">';
      recommendations.forEach(rec => {
        const {
          priority = 'medium',
          title = 'Recomendación',
          description = 'Sin descripción',
          action = null
        } = rec;

        html += '<div class="recommendation-item ' + priority + '">';
        html += '<div class="recommendation-header">';
        html += '<span class="priority-badge ' + priority + '">' + this.getPriorityText(priority) + '</span>';
        html += '<h5>' + title + '</h5>';
        html += '</div>';
        html += '<p>' + description + '</p>';
        if (action) {
          html += `<p class="action-required"><strong>Acción requerida:</strong> ${action}</p>`;
        }
        html += '</div>';
      });
      html += '</div>';
      html += '</div>';
    }

    html += '</div>';

    $results.html(html);
  },

  // Actualizar estado del sistema
  updateSystemStatus(data) {
    this.systemState.lastCheck = data.timestamp;
    if (data && typeof data === 'object' && 'overall_health' in data && data.overall_health) {
      // Extraer propiedades usando destructuring con valores por defecto
      const overallHealth = data.overall_health;
      const {
        status = 'unknown',
        components = {}
      } = overallHealth;

      if (status) {
        this.systemState.overallHealth = status;
        // Los issues ahora vienen de los componentes individuales
        this.systemState.issues = [];
        if (components && typeof components === 'object') {
          Object.entries(components).forEach(([component, componentStatus]) => {
            if (componentStatus && componentStatus.status && componentStatus.status !== 'healthy') {
              this.systemState.issues.push(`${this.getComponentName(component)}: ${this.getComponentStatusText(componentStatus.status)}`);
            }
          });
        }
      }
    } else {
      this.systemState.overallHealth = 'unknown';
      this.systemState.issues = [];
    }

    // Actualizar indicadores visuales si existen
    this.updateHealthIndicators(data);
  },

  // Actualizar indicadores de salud
  updateHealthIndicators(data) {
    // Actualizar tarjetas de estado si existen
    if (data.memory) {
      this.updateMemoryCard(data.memory);
    }
    if (data.retry) {
      this.updateRetryCard(data.retry);
    }
    if (data.sync) {
      this.updateSyncCard(data.sync);
    }
  },

  // Actualizar tarjeta de memoria
  updateMemoryCard(memoryData) {
    const $card = jQuery(SELECTORS.STAT_CARD_MEMORY);
    if ($card.length && memoryData) {
      // Extraer propiedades usando destructuring con valores por defecto
      const {
        usage_percentage = '0',
        status = 'unknown'
      } = memoryData;

      // Validar estado de memoria - solo aceptar estados válidos
      const validMemoryStates = ['healthy', 'warning', 'critical'];
      const validatedStatus = validMemoryStates.includes(status) ? status : 'unknown';

      $card.find(SELECTORS.STAT_VALUE).text(usage_percentage + '%');
      $card.removeClass('healthy warning critical').addClass(validatedStatus);

      // Log de advertencia si se recibió un estado inválido
      if (!validMemoryStates.includes(status)) {
        // eslint-disable-next-line no-console
        console.warn('Estado de memoria inválido recibido:', status, 'Usando estado por defecto: unknown');
      }
    }
  },

  // Actualizar tarjeta de reintentos
  updateRetryCard(retryData) {
    const $card = jQuery(SELECTORS.STAT_CARD_RETRIES);
    if ($card.length && retryData) {
      // Extraer propiedades usando destructuring con valores por defecto
      const {
        success_rate = '0',
        status = 'unknown'
      } = retryData;

      $card.find(SELECTORS.STAT_VALUE).text(success_rate + '%');
      $card.removeClass('healthy warning critical').addClass(status);
    }
  },

  // Actualizar tarjeta de sincronización
  updateSyncCard(syncData) {
    const $card = jQuery(SELECTORS.STAT_CARD_SYNC);
    if ($card.length && syncData) {
      // Extraer propiedades usando destructuring con valores por defecto
      const {
        status = 'unknown'
      } = syncData;

      $card.find(SELECTORS.STAT_VALUE).text(this.getSyncStatusText(status));
      $card.removeClass('healthy warning critical').addClass(status);
    }
  },

  // Actualizar salud del sistema
  updateSystemHealth(data) {
    if (data && typeof data === 'object' && 'system_health' in data && data.system_health) {
      // Extraer propiedades usando destructuring con valores por defecto
      const systemHealth = data.system_health;
      const {
        status = 'unknown',
        components = {}
      } = systemHealth;

      if (status) {
        this.systemState.overallHealth = status;
        // Los issues ahora vienen de los componentes individuales
        this.systemState.issues = [];
        if (components && typeof components === 'object') {
          Object.entries(components).forEach(([component, componentStatus]) => {
            if (componentStatus && componentStatus.status && componentStatus.status !== 'healthy') {
              this.systemState.issues.push(`${this.getComponentName(component)}: ${this.getComponentStatusText(componentStatus.status)}`);
            }
          });
        }
      }
    } else {
      this.systemState.overallHealth = 'unknown';
      this.systemState.issues = [];
    }

    // Extraer recomendaciones usando destructuring
    const { recommendations = [] } = data || {};
    this.systemState.recommendations = recommendations;

    // Actualizar indicadores visuales
    this.updateHealthIndicators(data);
  },

  // Obtener métricas completas del sistema usando MemoryManager expandido
  getCompleteSystemMetrics() {
    return new Promise((resolve, reject) => {
      if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
        AjaxManager.call('mia_get_complete_system_metrics', {},
          (response) => {
            if (response.success) {
              resolve(response.data);
            } else {
              reject(new Error((response.data && response.data.message) || 'Error al obtener métricas'));
            }
          },
          (xhr, status, error) => {
            reject(new Error('Error de conexión: ' + error));
          }
        );
      } else {
        reject(new Error('AjaxManager no está disponible'));
      }
    });
  },

  // Mostrar métricas adicionales en el dashboard
  displayAdditionalMetrics(metrics) {
    // Crear sección de métricas adicionales si no existe
    let $additionalMetrics = jQuery('#additional-system-metrics');
    if ($additionalMetrics.length === 0) {
      $additionalMetrics = jQuery('<div id="additional-system-metrics" class="mi-additional-metrics"></div>');
      jQuery('.mi-diagnostic-actions').after($additionalMetrics);
    }

    let html = '<h2>Métricas Detalladas del Sistema</h2>';
    html += '<div class="metrics-grid">';

    // Métricas de base de datos
    if (metrics && typeof metrics === 'object' && 'database' in metrics && metrics.database) {
      // Extraer propiedades usando destructuring con valores por defecto
      const database = metrics.database;
      const {
        total_posts = '0',
        total_products = '0',
        total_orders = '0',
        table_sizes = {}
      } = database;

      html += '<div class="metric-section database">';
      html += '<h3>Base de Datos</h3>';
      html += `<p><strong>Total Posts:</strong> ${total_posts}</p>`;
      html += `<p><strong>Productos:</strong> ${total_products}</p>`;
      html += `<p><strong>Pedidos:</strong> ${total_orders}</p>`;

      if (table_sizes && typeof table_sizes === 'object' && Object.keys(table_sizes).length > 0) {
        html += '<p><strong>Tamaños de Tablas:</strong></p><ul>';
        Object.entries(table_sizes).forEach(([table, size]) => {
          html += `<li>${table}: ${size} MB</li>`;
        });
        html += '</ul>';
      }
      html += '</div>';
    }

    // Métricas del sistema de archivos
    if (metrics && typeof metrics === 'object' && 'filesystem' in metrics && metrics.filesystem) {
      // Extraer propiedades usando destructuring con valores por defecto
      const filesystem = metrics.filesystem;
      const {
        disk_total_gb = '0',
        disk_used_gb = '0',
        disk_usage_percentage = '0',
        disk_free_gb = '0',
        directory_sizes = {}
      } = filesystem;

      html += '<div class="metric-section filesystem">';
      html += '<h3>Sistema de Archivos</h3>';
      html += `<p><strong>Disco Total:</strong> ${disk_total_gb} GB</p>`;
      html += `<p><strong>Disco Usado:</strong> ${disk_used_gb} GB (${disk_usage_percentage}%)</p>`;
      html += `<p><strong>Disco Libre:</strong> ${disk_free_gb} GB</p>`;

      if (directory_sizes && typeof directory_sizes === 'object' && Object.keys(directory_sizes).length > 0) {
        html += '<p><strong>Tamaños de Directorios:</strong></p><ul>';
        Object.entries(directory_sizes).forEach(([dir, info]) => {
          if (info && info.exists) {
            // Extraer propiedades usando destructuring con valores por defecto
            const { size_mb = '0' } = info;
            html += `<li>${dir}: ${size_mb} MB</li>`;
          }
        });
        html += '</ul>';
      }
      html += '</div>';
    }

    // Métricas de rendimiento
    if (metrics && typeof metrics === 'object' && 'performance' in metrics && metrics.performance) {
      // Extraer propiedades usando destructuring con valores por defecto
      const performance = metrics.performance;
      const {
        php_version = 'Unknown',
        wordpress_version = 'Unknown',
        plugin_version = 'Unknown',
        memory_limit = 'Unknown',
        scheduled_tasks = '0',
        transients_count = '0'
      } = performance;

      html += '<div class="metric-section performance">';
      html += '<h3>Rendimiento del Sistema</h3>';
      html += `<p><strong>PHP:</strong> ${php_version}</p>`;
      html += `<p><strong>WordPress:</strong> ${wordpress_version}</p>`;
      html += `<p><strong>Plugin:</strong> ${plugin_version}</p>`;
      html += `<p><strong>Límite de Memoria:</strong> ${memory_limit}</p>`;
      html += `<p><strong>Tareas Programadas:</strong> ${scheduled_tasks}</p>`;
      html += `<p><strong>Transients:</strong> ${transients_count}</p>`;
      html += '</div>';
    }

    html += '</div>';

    $additionalMetrics.html(html);
  },

  // Mostrar estado de cron jobs en el dashboard
  displayCronStatus(cronJobs) {
    // Crear sección de estado de cron jobs si no existe
    let $cronStatus = jQuery('#cron-jobs-status');
    if ($cronStatus.length === 0) {
      $cronStatus = jQuery('<div id="cron-jobs-status" class="mi-cron-status"></div>');
      jQuery('.mi-diagnostic-actions').after($cronStatus);
    }

    let html = '<h2>Estado de Cron Jobs del Plugin</h2>';
    html += '<table class="wp-list-table widefat fixed striped">';
    html += '<thead><tr>';
    html += '<th>Hook</th>';
    html += '<th>Descripción</th>';
    html += '<th>Estado</th>';
    html += '<th>Próxima Ejecución</th>';
    html += '<th>Timestamp</th>';
    html += '</tr></thead>';
    html += '<tbody>';

    Object.entries(cronJobs).forEach(([hook, data]) => {
      // Extraer propiedades usando destructuring con valores por defecto
      const {
        scheduled = false,
        next_run = null,
        timestamp = null,
        description = 'Sin descripción'
      } = data;

      const statusClass = scheduled ? 'status-ok' : 'status-error';
      const statusText = scheduled ? 'Programado' : 'No Programado';
      const nextRun = scheduled ? next_run : 'N/A';
      const timestampFormatted = timestamp ? new Date(timestamp * 1000).toLocaleString() : 'N/A';

      html += '<tr>';
      html += `<td><code>${hook}</code></td>`;
      html += `<td>${description}</td>`;
      html += `<td><span class="${statusClass}">${statusText}</span></td>`;
      html += `<td>${nextRun}</td>`;
      html += `<td>${timestampFormatted}</td>`;
      html += '</tr>';
    });

    html += '</tbody></table>';
    html += '</div>';

    $cronStatus.html(html);
  },

  // CORRECCIÓN DE OPTIMIZACIÓN: Métodos para funcionalidades bajo demanda

  // Ejecutar diagnóstico del sistema
  executeSystemDiagnostic() {
    const $btn = jQuery('#execute-system-diagnostic');
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Ejecutando...');

    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_execute_system_diagnostic', {}, (response) => {
        if (response.success) {
          this.displaySystemDiagnostic(response.data.diagnostic);
        }
        $btn.prop('disabled', false).html('Ejecutar Diagnóstico');
      }, (xhr, status, error) => {
        $btn.prop('disabled', false).html('Ejecutar Diagnóstico');
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showConnectionError === 'function') {
          ErrorHandler.showConnectionError(xhr);
        }
        // eslint-disable-next-line no-console
        console.error(`Error en diagnóstico del sistema [${status}]:`, error);
      });
    }
  },

  // Inicializar sistema de assets
  initializeAssets() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_assets', {});
    }
  },

  // Inicializar sistema de AJAX
  initializeAjax() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_ajax', {});
    }
  },

  // Inicializar sistema de configuración
  initializeSettings() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_settings', {});
    }
  },

  // Inicializar sistema de limpieza
  initializeCleanup() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_cleanup', {});
    }
  },

  // Cargar textdomain del plugin
  loadTextdomain() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_load_textdomain', {});
    }
  },

  // Mostrar diagnóstico del sistema
  displaySystemDiagnostic(diagnostic) {
    let $diagnosticArea = jQuery('#system-diagnostic-results');
    if ($diagnosticArea.length === 0) {
      $diagnosticArea = jQuery('<div id="system-diagnostic-results" class="mi-system-diagnostic"></div>');
      jQuery('.mi-diagnostic-actions').after($diagnosticArea);
    }

    let html = '<h2>Diagnóstico del Sistema</h2>';
    html += '<div class="diagnostic-grid">';

    // Información general
    html += '<div class="diagnostic-section general">';
    html += '<h3>Información General</h3>';
    html += `<p><strong>Timestamp:</strong> ${diagnostic.timestamp}</p>`;

    // Extraer propiedades usando destructuring con valores por defecto
    const {
      memory_usage = 0,
      peak_memory = 0
    } = diagnostic;

    html += `<p><strong>Memoria Actual:</strong> ${this.formatBytes(memory_usage)}</p>`;
    html += `<p><strong>Memoria Pico:</strong> ${this.formatBytes(peak_memory)}</p>`;
    html += '</div>';

    // Estado de hooks
    html += '<div class="diagnostic-section hooks">';
    html += '<h3>Estado de Hooks</h3>';

    // Extraer propiedades usando destructuring con valores por defecto
    const { hooks_status = {} } = diagnostic;

    Object.entries(hooks_status).forEach(([hook, count]) => {
      const statusClass = count > 0 ? 'status-ok' : 'status-error';
      const statusText = count > 0 ? `${count} acciones` : 'Sin acciones';
      html += `<p><strong>${hook}:</strong> <span class="${statusClass}">${statusText}</span></p>`;
    });
    html += '</div>';

    // Estado del plugin
    html += '<div class="diagnostic-section plugin">';
    html += '<h3>Estado del Plugin</h3>';

    // Extraer propiedades usando destructuring con valores por defecto
    const { plugin_status = {} } = diagnostic;
    const {
      initialized = false,
      woocommerce_ready = false,
      logger_available = false
    } = plugin_status;

    html += `<p><strong>Inicializado:</strong> <span class="${initialized ? 'status-ok' : 'status-error'}">${initialized ? 'Sí' : 'No'}</span></p>`;
    html += `<p><strong>WooCommerce:</strong> <span class="${woocommerce_ready ? 'status-ok' : 'status-error'}">${woocommerce_ready ? 'Listo' : 'No disponible'}</span></p>`;
    html += `<p><strong>Logger:</strong> <span class="${logger_available ? 'status-ok' : 'status-error'}">${logger_available ? 'Disponible' : 'No disponible'}</span></p>`;
    html += '</div>';

    // Clases del core
    html += '<div class="diagnostic-section core">';
    html += '<h3>Clases del Core</h3>';

    // Extraer propiedades usando destructuring con valores por defecto
    const { core_classes_available = {} } = plugin_status;

    Object.entries(core_classes_available).forEach(([class_name, available]) => {
      const statusClass = available ? 'status-ok' : 'status-error';
      const statusText = available ? 'Disponible' : 'No disponible';
      html += `<p><strong>${class_name}:</strong> <span class="${statusClass}">${statusText}</span></p>`;
    });
    html += '</div>';

    html += '</div>';

    $diagnosticArea.html(html);
  },

  // Formatear bytes en formato legible
  formatBytes(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return Number.parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
  },

  // Inicializar reportes de compatibilidad
  initializeCompatibilityReports() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_compatibility_reports', {},
        (response) => {
          if (response.success) {
            this.displayCompatibilityResult(response.data, 'reports');
          }
        }
      );
    }
  },

  // Inicializar compatibilidad con temas
  initializeThemeCompatibility() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_theme_compatibility', {},
        (response) => {
          if (response.success) {
            this.displayCompatibilityResult(response.data, 'themes');
          }
        }
      );
    }
  },

  // Inicializar compatibilidad con plugins de WooCommerce
  initializeWooCommercePluginCompatibility() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_woocommerce_plugin_compatibility', {},
        (response) => {
          if (response.success) {
            this.displayCompatibilityResult(response.data, 'woocommerce_plugins');
          }
        }
      );
    }
  },

  // Inicializar compatibilidad general
  initializeGeneralCompatibility() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_general_compatibility', {},
        (response) => {
          if (response.success) {
            this.displayCompatibilityResult(response.data, 'general');
          }
        }
      );
    }
  },

  // Ejecutar verificación completa de compatibilidad
  executeCompleteCompatibilityCheck() {
    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_execute_complete_compatibility_check', {},
        (response) => {
          if (response.success) {
            this.displayCompleteCompatibilityResult(response.data);
          }
        }
      );
    }
  },

  // Mostrar resultado de compatibilidad individual
  displayCompatibilityResult(data, type) {
    let $resultArea = jQuery(`#compatibility-${type}-results`);
    if ($resultArea.length === 0) {
      $resultArea = jQuery(`<div id="compatibility-${type}-results" class="mi-compatibility-result"></div>`);
      jQuery('.mi-diagnostic-actions').after($resultArea);
    }

    let html = `<h3>Resultado de Compatibilidad: ${this.getCompatibilityTypeName(type)}</h3>`;
    html += '<div class="compatibility-details">';

    if (data && typeof data === 'object' && 'success' in data && data.success) {
      html += '<div class="status-success">✅ Inicialización exitosa</div>';

      // Extraer componentes usando destructuring
      const { components = [] } = data;
      if (components && components.length > 0) {
        html += '<p><strong>Componentes inicializados:</strong></p><ul>';
        components.forEach(component => {
          html += `<li>${component}</li>`;
        });
        html += '</ul>';
      }
    } else {
      html += '<div class="status-error">❌ Error en la inicialización</div>';

      // Extraer errores usando destructuring
      const { errors = [] } = data || {};
      if (errors && errors.length > 0) {
        html += '<p><strong>Errores:</strong></p><ul>';
        errors.forEach(error => {
          html += `<li>${error}</li>`;
        });
        html += '</ul>';
      }
    }

    // Extraer advertencias usando destructuring
    const { warnings = [] } = data || {};
    if (warnings && warnings.length > 0) {
      html += '<p><strong>Advertencias:</strong></p><ul>';
      warnings.forEach(warning => {
        html += `<li>⚠️ ${warning}</li>`;
      });
      html += '</ul>';
    }

    html += '</div>';

    $resultArea.html(html);
  },

  // Mostrar resultado completo de compatibilidad
  displayCompleteCompatibilityResult(data) {
    let $resultArea = jQuery('#complete-compatibility-results');
    if ($resultArea.length === 0) {
      $resultArea = jQuery('<div id="complete-compatibility-results" class="mi-complete-compatibility-result"></div>');
      jQuery('.mi-diagnostic-actions').after($resultArea);
    }

    let html = '<h3>Resultado Completo de Compatibilidad</h3>';
    html += '<div class="compatibility-summary">';

    // Resumen general
    html += '<div class="summary-overview">';
    html += `<p><strong>Timestamp:</strong> ${data.timestamp}</p>`;

    // Extraer propiedades usando destructuring
    const {
      overall_success = false,
      summary = {}
    } = data;

    const {
      total_checks = '0',
      successful_checks = '0',
      failed_checks = '0',
      warnings = '0'
    } = summary;

    html += `<p><strong>Estado General:</strong> <span class="${overall_success ? 'status-success' : 'status-error'}">${overall_success ? '✅ Exitoso' : '❌ Con errores'}</span></p>`;
    html += `<p><strong>Total de verificaciones:</strong> ${total_checks}</p>`;
    html += `<p><strong>Verificaciones exitosas:</strong> <span class="status-success">${successful_checks}</span></p>`;
    html += `<p><strong>Verificaciones fallidas:</strong> <span class="status-error">${failed_checks}</span></p>`;
    html += `<p><strong>Advertencias:</strong> <span class="status-warning">${warnings}</span></p>`;
    html += '</div>';

    // Detalles por componente
    html += '<div class="component-details">';
    html += '<h4>Detalles por Componente</h4>';

    Object.entries(data.components).forEach(([component_type, result]) => {
      const statusClass = result.success ? 'status-success' : 'status-error';
      const statusText = result.success ? '✅ Exitoso' : '❌ Fallido';

      html += `<div class="component-item ${statusClass}">`;
      html += `<h5>${this.getCompatibilityTypeName(component_type)}</h5>`;
      html += `<p><strong>Estado:</strong> <span class="${statusClass}">${statusText}</span></p>`;

      if (result.components && result.components.length > 0) {
        html += '<p><strong>Componentes:</strong></p><ul>';
        result.components.forEach(comp => {
          html += `<li>${comp}</li>`;
        });
        html += '</ul>';
      }

      if (result.errors && result.errors.length > 0) {
        html += '<p><strong>Errores:</strong></p><ul>';
        result.errors.forEach(error => {
          html += `<li>❌ ${error}</li>`;
        });
        html += '</ul>';
      }

      if (result.warnings && result.warnings.length > 0) {
        html += '<p><strong>Advertencias:</strong></p><ul>';
        result.warnings.forEach(warning => {
          html += `<li>⚠️ ${warning}</li>`;
        });
        html += '</ul>';
      }

      html += '</div>';
    });

    html += '</div>';
    html += '</div>';

    $resultArea.html(html);
  },

  // Obtener nombre legible del tipo de compatibilidad
  getCompatibilityTypeName(type) {
    const names = {
      'reports': 'Reportes de Compatibilidad',
      'themes': 'Compatibilidad con Temas',
      'woocommerce_plugins': 'Compatibilidad con Plugins WooCommerce',
      'general': 'Compatibilidad General'
    };
    return names[type] || type;
  },

  // Inicializar hooks de sincronización
  initializeSyncHooks() {
    const $btn = jQuery('#initialize-sync-hooks');
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Inicializando...');

    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_sync_hooks', {}, (response) => {
        if (response.success) {
          this.displayHookResult(response.data, 'sync_hooks');
        }
        $btn.prop('disabled', false).html('Inicializar Hooks');
      }, (xhr, status, error) => {
        $btn.prop('disabled', false).html('Inicializar Hooks');
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showConnectionError === 'function') {
          ErrorHandler.showConnectionError(xhr);
        }
        // eslint-disable-next-line no-console
        console.error(`Error inicializando hooks [${status}]:`, error);
      });
    }
  },

  // Inicializar carga perezosa AJAX
  initializeAjaxLazyLoading() {
    const $btn = jQuery('#initialize-ajax-lazy-loading');
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Inicializando...');

    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_initialize_ajax_lazy_loading', {}, (response) => {
        if (response.success) {
          this.displayHookResult(response.data, 'ajax_lazy_loading');
        }
        $btn.prop('disabled', false).html('Inicializar Carga Perezosa AJAX');
      }, (xhr, status, error) => {
        $btn.prop('disabled', false).html('Inicializar Carga Perezosa AJAX');
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showConnectionError === 'function') {
          ErrorHandler.showConnectionError(xhr);
        }
        // eslint-disable-next-line no-console
        console.error(`Error inicializando carga perezosa AJAX [${status}]:`, error);
      });
    }
  },

  // Ejecutar debug de batch size
  executeBatchSizeDebug() {
    const $btn = jQuery('#execute-batch-size-debug');
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Ejecutando...');

    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_execute_batch_size_debug', {}, (response) => {
        if (response.success) {
          this.displayBatchSizeDebugResult(response.data);
        }
        $btn.prop('disabled', false).html('Ejecutar Debug de Batch Size');
      }, (xhr, status, error) => {
        $btn.prop('disabled', false).html('Ejecutar Debug de Batch Size');
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showConnectionError === 'function') {
          ErrorHandler.showConnectionError(xhr);
        }
        // eslint-disable-next-line no-console
        console.error(`Error ejecutando debug de batch size [${status}]:`, error);
      });
    }
  },

  // Mostrar resultado de hooks adicionales
  displayHookResult(data, type) {
    let $resultArea = jQuery(`#hook-${type}-results`);
    if ($resultArea.length === 0) {
      $resultArea = jQuery(`<div id="hook-${type}-results" class="mi-hook-result"></div>`);
      jQuery('.mi-diagnostic-actions').after($resultArea);
    }

    let html = `<h3>Resultado de Hooks: ${this.getHookTypeName(type)}</h3>`;
    html += '<div class="hook-details">';

    if (data.success) {
      html += '<div class="status-success">✅ Inicialización exitosa</div>';
      if (data.components && data.components.length > 0) {
        html += '<p><strong>Componentes inicializados:</strong></p><ul>';
        data.components.forEach(component => {
          html += `<li>${component}</li>`;
        });
        html += '</ul>';
      }
    } else {
      html += '<div class="status-error">❌ Error en la inicialización</div>';
      if (data.errors && data.errors.length > 0) {
        html += '<p><strong>Errores:</strong></p><ul>';
        data.errors.forEach(error => {
          html += `<li>${error}</li>`;
        });
        html += '</ul>';
      }
    }

    if (data.warnings && data.warnings.length > 0) {
      html += '<p><strong>Advertencias:</strong></p><ul>';
      data.warnings.forEach(warning => {
        html += `<li>⚠️ ${warning}</li>`;
      });
      html += '</ul>';
    }

    html += '</div>';

    $resultArea.html(html);
  },

  // Mostrar resultado de debug de batch size
  displayBatchSizeDebugResult(data) {
    let $resultArea = jQuery('#batch-size-debug-results');
    if ($resultArea.length === 0) {
      $resultArea = jQuery('<div id="batch-size-debug-results" class="mi-batch-size-debug-result"></div>');
      jQuery('.mi-diagnostic-actions').after($resultArea);
    }

    let html = '<h3>Resultado de Debug de Batch Size</h3>';
    html += '<div class="batch-size-debug-details">';

    if (data.success) {
      html += '<div class="status-success">✅ Debug ejecutado exitosamente</div>';
      if (data.components && data.components.length > 0) {
        html += '<p><strong>Componentes procesados:</strong></p><ul>';
        data.components.forEach(component => {
          html += `<li>${component}</li>`;
        });
        html += '</ul>';
      }

      // Extraer propiedades usando destructuring con valores por defecto
      const { debug_info = {} } = data;

      if (debug_info && Object.keys(debug_info).length > 0) {
        html += '<p><strong>Información de Debug:</strong></p><div class="debug-info-grid">';
        Object.entries(debug_info).forEach(([key, value]) => {
          html += `<div class="debug-info-item"><strong>${key}:</strong> ${value}</div>`;
        });
        html += '</div>';
      }
    } else {
      html += '<div class="status-error">❌ Error en la ejecución del debug</div>';
      if (data.errors && data.errors.length > 0) {
        html += '<p><strong>Errores:</strong></p><ul>';
        data.errors.forEach(error => {
          html += `<li>${error}</li>`;
        });
        html += '</ul>';
      }
    }

    if (data.warnings && data.warnings.length > 0) {
      html += '<p><strong>Advertencias:</strong></p><ul>';
      data.warnings.forEach(warning => {
        html += `<li>⚠️ ${warning}</li>`;
      });
      html += '</ul>';
    }

    html += '</div>';

    $resultArea.html(html);
  },

  // Obtener nombre legible del tipo de hook
  getHookTypeName(type) {
    const names = {
      'sync_hooks': 'Hooks de Sincronización',
      'sync_diagnostic_ajax': 'Diagnóstico AJAX',
      'ajax_lazy_loading': 'Carga Perezosa AJAX'
    };
    return names[type] || type;
  },

  // Cargar métricas adicionales del sistema
  loadAdditionalMetrics() {
    const $btn = jQuery('#load-additional-metrics');

    // Mostrar estado de carga
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Cargando...');

    this.getCompleteSystemMetrics()
      .then((metrics) => {
        this.displayAdditionalMetrics(metrics);
        this.showSuccess('Métricas adicionales cargadas correctamente');

        // Cambiar botón a "Refrescar"
        $btn.prop('disabled', false)
          .html('<span class="dashicons dashicons-update"></span> Refrescar Métricas')
          .attr('id', 'refresh-additional-metrics');

        // Cambiar manejador del evento
        jQuery(document).off('click', '#load-additional-metrics');
        jQuery(document).on('click', '#refresh-additional-metrics', (e) => {
          e.preventDefault();
          this.loadAdditionalMetrics();
        });
      })
      .catch((error) => {
        this.showError('Error al cargar métricas: ' + error.message);
        $btn.prop('disabled', false)
          .html('<span class="dashicons dashicons-update-alt"></span> Cargar Métricas Adicionales');
      });
  },

  // Verificar estado de cron jobs del plugin
  checkCronStatus() {
    const $btn = jQuery('#check-cron-status');
    $btn.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Verificando...');

    if (typeof AjaxManager !== 'undefined' && AjaxManager && typeof AjaxManager.call === 'function') {
      AjaxManager.call('mia_check_cron_status', {}, (response) => {
        if (response.success) {
          // Extraer propiedades usando destructuring con valores por defecto
          const { cron_jobs = {} } = response.data || {};
          this.displayCronStatus(cron_jobs);
        }
        $btn.prop('disabled', false).html('Verificar Estado de Cron Jobs');
      }, (xhr, status, error) => {
        $btn.prop('disabled', false).html('Verificar Estado de Cron Jobs');
        if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showConnectionError === 'function') {
          ErrorHandler.showConnectionError(xhr);
        }
        // eslint-disable-next-line no-console
        console.error(`Error verificando estado de cron jobs [${status}]:`, error);
      });
    }
  },

  // Obtener texto de estado de salud
  getHealthStatusText(status) {
    const statusMap = {
      'healthy': 'Saludable',
      'attention': 'Atención',
      'warning': 'Advertencia',
      'critical': 'Crítico',
      'error': 'Error'
    };
    return statusMap[status] || status;
  },

  // Obtener nombre del componente
  getComponentName(component) {
    const componentMap = {
      'memory': 'Memoria',
      'retry': 'Reintentos',
      'sync': 'Sincronización',
      'api': 'API'
    };
    return componentMap[component] || component;
  },

  // Obtener texto de estado del componente
  getComponentStatusText(status) {
    const statusMap = {
      'healthy': 'Saludable',
      'attention': 'Atención',
      'warning': 'Advertencia',
      'critical': 'Crítico',
      'error': 'Error',
      'unavailable': 'No disponible'
    };
    return statusMap[status] || status;
  },

  // Obtener texto de estado de memoria
  getMemoryStatusText(status) {
    const statusMap = {
      'healthy': 'Saludable',
      'attention': 'Atención',
      'warning': 'Advertencia',
      'critical': 'Crítico',
      'error': 'Error',
      'unavailable': 'No disponible'
    };
    return statusMap[status] || status;
  },

  // Obtener texto de estado de reintentos
  getRetryStatusText(status) {
    const statusMap = {
      'excellent': 'Excelente',
      'good': 'Bueno',
      'fair': 'Regular',
      'poor': 'Pobre',
      'no_data': 'Sin datos'
    };
    return statusMap[status] || status;
  },

  // Obtener texto de estado de sincronización
  getSyncStatusText(status) {
    const statusMap = {
      'running': 'En progreso',
      'syncing': 'Sincronizando',
      'completed': 'Completada',
      'failed': 'Falló',
      'paused': 'Pausada',
      'unknown': 'Desconocido',
      'error': 'Error'
    };
    return statusMap[status] || status;
  },

  // Obtener texto de prioridad
  getPriorityText(priority) {
    const priorityMap = {
      'critical': 'Crítico',
      'high': 'Alto',
      'medium': 'Medio',
      'low': 'Bajo'
    };
    return priorityMap[priority] || priority;
  },

  // Mostrar mensaje de éxito
  showSuccess(message) {
    this.showMessage(message, 'success');
  },

  // Mostrar mensaje de error
  showError(message) {
    this.showMessage(message, 'error');
  },

  // Mostrar mensaje
  showMessage(message, type = 'info') {
    if (typeof jQuery === 'undefined' || !jQuery.fn || !jQuery.fn.prependTo) {
      // Fallback si jQuery no está disponible
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showCriticalError === 'function') {
        ErrorHandler.showCriticalError(message, 'FALLBACK');
      }
      return;
    }

    try {
      const $message = jQuery('<div class="notice notice-' + type + ' is-dismissible"></div>');
      $message.html('<p>' + message + '</p>');

      // Verificar que prependTo existe antes de usarlo
      if (typeof $message.prependTo === 'function') {
        $message.prependTo('.wrap');
      } else {
        // Fallback: usar appendTo o insertBefore
        const $wrap = jQuery('.wrap');
        if ($wrap.length > 0) {
          $wrap.prepend($message);
        } else {
          // Último recurso: insertar al principio del body
          jQuery('body').prepend($message);
        }
      }

      // Auto-ocultar después de 5 segundos
      setTimeout(() => {
        if ($message && typeof $message.fadeOut === 'function') {
          $message.fadeOut(function() {
            $message.remove();
          });
        } else {
          $message.remove();
        }
      }, 5000);
    } catch (error) {
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.logError === 'function') {
        ErrorHandler.logError('Error al mostrar mensaje', 'MESSAGE_DISPLAY');
      }
      if (typeof ErrorHandler !== 'undefined' && ErrorHandler && typeof ErrorHandler.showCriticalError === 'function') {
        ErrorHandler.showCriticalError(message, 'MESSAGE_DISPLAY');
      }
    }
  },

  // Verificar si hay una sincronización en progreso
  isSyncInProgress() {
    // Verificar si hay polling activo de sincronización
    if (typeof pollingManager !== 'undefined' && pollingManager && typeof pollingManager.isPollingActive === 'function') {
      if (pollingManager.isPollingActive('syncProgress')) {
        return true;
      }
    }

    // Verificar si hay un intervalo de sincronización activo
    if (typeof window !== 'undefined' && window.syncInterval !== undefined && window.syncInterval !== null) {
      return true;
    }

    // Verificar elementos del DOM una sola vez
    const $syncBtn = jQuery('#mi-batch-sync-products');
    const $feedback = jQuery('#mi-sync-feedback');

    return (
      // Verificar si el botón de sincronización está deshabilitado (indicador de progreso)
      ($syncBtn.length && $syncBtn.is(':disabled')) ||
      // Verificar si hay feedback de progreso visible
      ($feedback.length && $feedback.hasClass('in-progress'))
    );
  }
};

/**
 * Exponer UnifiedDashboard globalmente para mantener compatibilidad
 * con el código existente que usa window.UnifiedDashboard
 */
// eslint-disable-next-line no-restricted-globals
if (typeof window !== 'undefined') {
  try {
    // eslint-disable-next-line no-restricted-globals
    window.UnifiedDashboard = UnifiedDashboard;
  } catch (error) {
    try {
      // eslint-disable-next-line no-restricted-globals
      Object.defineProperty(window, 'UnifiedDashboard', {
        value: UnifiedDashboard,
        writable: true,
        enumerable: true,
        configurable: true
      });
    } catch (defineError) {
      // eslint-disable-next-line no-console
      if (typeof console !== 'undefined' && console.warn) {
        // eslint-disable-next-line no-console
        console.warn('No se pudo asignar UnifiedDashboard a window:', defineError, error);
      }
    }
  }
}

// Exponer también directamente para uso sin window.
// Esto permite usar UnifiedDashboard.init() directamente como en la documentación
// eslint-disable-next-line no-restricted-globals
if (typeof globalThis !== 'undefined') {
  // eslint-disable-next-line no-restricted-globals
  globalThis.UnifiedDashboard = UnifiedDashboard;
} else if (typeof global !== 'undefined') {
  global.UnifiedDashboard = UnifiedDashboard;
} else if (typeof window !== 'undefined') {
  // Para navegadores, usar una función que exponga la variable
  (function() {
    // Crear una variable global directa
    // eslint-disable-next-line no-restricted-globals
    const globalScope = (function() { return this; })();
    if (globalScope) {
      globalScope.UnifiedDashboard = UnifiedDashboard;
    }
  })();
}

/* global module */
if (typeof module !== 'undefined' && module.exports) {
  module.exports = { UnifiedDashboard };
}
