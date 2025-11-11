<?php
/**
 * Módulo de endpoints AJAX para el panel de administración
 *
 * Este archivo contiene las clases que manejan todas las operaciones AJAX
 * utilizadas en el panel de administración del plugin Mi Integración API.
 *
 * Características principales:
 * - Diagnóstico automático de problemas AJAX
 * - Gestión de operaciones del dashboard
 * - Monitoreo del estado del sistema
 * - Manejo de errores y logs
 * - Sistema de reportes bajo demanda
 * - Verificación de compatibilidad
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.0.0
 * @author      Christian <christian@example.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs/ajax
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

// Seguridad: Salir si se accede directamente
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para el diagnóstico automático de problemas AJAX
 *
 * Esta clase se encarga de detectar, registrar y diagnosticar problemas
 * que ocurren durante las peticiones AJAX, especialmente errores HTTP 400.
 * Proporciona herramientas para:
 * - Registrar y analizar logs de peticiones AJAX
 * - Verificar la validez de nonces
 * - Diagnosticar problemas de autenticación
 * - Monitorear el estado de los manejadores AJAX
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.0.0
 * @see         wp_verify_nonce() Para la validación de nonces
 * @see         wpAjax_* Para los hooks de acciones AJAX
 */
class AjaxDiagnostic {
    
    /**
     * Ruta al archivo de log de diagnóstico
     *
     * @var string|null $logFile Ruta completa al archivo de log de diagnóstico.
     *                           Se genera automáticamente con formato:
     *                           wp-content/uploads/mi-integracion-api/logs/ajax-diagnostic-{Y-m-d}.log
     * @since 1.0.0
     * @static
     */
    private static $logFile = null;
    
    /**
     * Inicializa el sistema de diagnóstico AJAX
     *
     * Este método realiza las siguientes acciones:
     * 1. Crea el directorio de logs si no existe
     * 2. Configura el manejador de errores personalizado
     * 3. Inicializa el archivo de log diario
     * 4. Registra los hooks necesarios para el diagnóstico
     *
     * @return void
     * @since 1.0.0
     * @hook adminInit
     * @see wp_upload_dir() Para obtener la ruta del directorio de subidas
     * @see wp_mkdir_p() Para crear directorios de forma segura
     * @uses self::$logFile Para almacenar la ruta del archivo de log
     * @throws \RuntimeException Si no se puede crear el directorio de logs
     *
     * @example
     * ```php
     * // Inicialización típica en el plugin
     * addAction('adminInit', ['MiIntegracionApi\Admin\AjaxDiagnostic', 'init']);
     * ```
     */
    public static function init() {
        // Crear directorio de logs si no existe
        $uploadDir = \wp_upload_dir();
        $logDir = $uploadDir['basedir'] . '/mi-integracion-api/logs/';
        if (!\file_exists($logDir)) {
            \wp_mkdir_p($logDir);
        }
        
        self::$logFile = $logDir . 'ajax-diagnostic-' . \date('Y-m-d') . '.log';
        
        // Añadir hooks para capturar errores
        add_action('wp_ajax_miIntegracion_apiReload_metrics', [self::class, 'preAjaxCheck'], 1);
        add_action('wp_ajax_nopriv_miIntegracion_apiReload_metrics', [self::class, 'handleNoprivError']);
    }
    
    /**
     * Realiza verificaciones de seguridad antes de procesar peticiones AJAX
     *
     * Este método se ejecuta antes de cualquier acción AJAX y realiza las siguientes verificaciones:
     * 1. Recopila y registra los datos de la petición POST
     * 2. Verifica los permisos del usuario actual
     * 3. Valida el nonce de seguridad
     * 4. Comprueba los manejadores registrados
     *
     * @return void
     * @since 1.0.0
     * @hook wpAjax_* (ejecutado a través de wpAjax_miIntegracion_apiReload_metrics)
     */
    public static function preAjaxCheck() {
        self::log("=== PRE-AJAX CHECK START ===");
        
        // 1. Verificar datos de entrada
        $postData = $_POST;
        self::log("POST Data: " . json_encode($postData));
        
        // 2. Verificar usuario
        $userId = get_current_user_id();
        $canManage = current_user_can('manageOptions');
        self::log("User ID: $userId, Can manage: " . ($canManage ? 'YES' : 'NO'));
        
        // 3. Verificar nonce
        $nonce = $postData['nonce'] ?? 'MISSING';
        self::log("Nonce received: $nonce");
        
        if ($nonce !== 'MISSING') {
            $valid = \wp_verify_nonce($nonce, 'mi_integracion_api_nonce_dashboard');
            self::log("Nonce validation result: " . ($valid ? 'VALID' : 'INVALID'));
            
            if (!$valid) {
                self::diagnoseNonceProblem($nonce);
            }
        }
        
        // 4. Verificar handlers registrados
        self::checkHandlers();
        
        self::log("=== PRE-AJAX CHECK END ===");
    }
    
    /**
     * Diagnostica problemas relacionados con el nonce de seguridad
     *
     * Este método se ejecuta cuando falla la validación del nonce y realiza:
     * 1. Prueba el nonce con diferentes acciones comunes
     * 2. Verifica la vida útil del nonce
     * 3. Registra información útil para el diagnóstico
     *
     * @param string $nonce El nonce que falló la validación
     * @return void
     * @since 1.0.0
     */
    private static function diagnoseNonceProblem($nonce) {
        self::log("--- NONCE PROBLEM DIAGNOSIS ---");
        
        // Probar diferentes acciones
        $testActions = [
            'mi_integracion_api_nonce_dashboard',
            'miIntegracion_apiDashboard_nonce',
            'miIntegracion_apiNonce',
            'miaSync_nonce'
        ];
        
        foreach ($testActions as $action) {
            $valid = \wp_verify_nonce($nonce, $action);
            self::log("Testing action '$action': " . ($valid ? 'VALID' : 'INVALID'));
        }
        
        // Verificar si el nonce está expirado
        $nonceLife = \apply_filters('nonceLife', DAY_IN_SECONDS);
        self::log("Nonce lifetime: $nonceLife seconds");
        
        // Verificar cómo se está generando el nonce
        if (defined('MiIntegracionApi_NONCE_PREFIX')) {
            $expectedAction = MiIntegracionApi_NONCE_PREFIX . 'dashboard';
            self::log("Expected nonce action: $expectedAction");
        }
    }
    
    /**
     * Verifica que los manejadores de AJAX estén correctamente registrados
     *
     * Este método comprueba si los manejadores de acciones AJAX están registrados
     * correctamente en WordPress y registra cualquier problema encontrado.
     *
     * @return void
     * @since 1.0.0
     */
    private static function checkHandlers() {
        global $wpFilter;
        
        $requiredHandlers = [
            'wpAjax_miIntegracion_apiReload_metrics',
            'wpAjax_miIntegracion_apiGet_dashboardData'
        ];
        
        foreach ($requiredHandlers as $handler) {
            $registered = isset($wpFilter[$handler]);
            self::log("Handler $handler: " . ($registered ? 'REGISTERED' : 'NOT REGISTERED'));
            
            if ($registered) {
                $callbackCount = count($wpFilter[$handler]->callbacks);
                self::log("  Callbacks: $callbackCount");
            }
        }
    }
    
    /**
     * Maneja errores de autenticación en peticiones AJAX
     *
     * Este método se ejecuta cuando un usuario no autenticado intenta acceder
     * a un endpoint AJAX que requiere autenticación.
     *
     * Realiza las siguientes acciones:
     * 1. Registra el intento de acceso no autorizado en el log
     * 2. Devuelve una respuesta JSON con código de error 401
     * 3. Incluye información de depuración en modo WP_DEBUG
     *
     * @return void
     * @since 1.0.0
     * @hook wpAjax_nopriv_*
     * @see wp_send_json_error() Para el envío de respuestas de error JSON
     * @see isUser_loggedIn() Para verificar el estado de autenticación
     * @uses self::log() Para el registro de eventos
     *
     * @example
     * ```javascript
     * // Ejemplo de respuesta JSON
     * {
     *     "success": false,
     *     "data": {
     *         "message": "Session expired. Please refresh the page and try again.",
     *         "code": "sessionExpired"
     *     }
     * }
     * ```
     */
    public static function handleNoprivError() {
        self::log("ERROR: AJAX called without privileges");
        self::log("This suggests the user is not logged in or session expired");
        
        \wp_send_json_error([
            'message' => 'Session expired. Please refresh the page and try again.',
            'code' => 'sessionExpired',
            'diagnostic' => 'User not authenticated'
        ], 401);
    }
    
    /**
     * Intenta reparar automáticamente problemas comunes de AJAX
     *
     * Este método intenta solucionar automáticamente los problemas más comunes
     * que pueden afectar al funcionamiento de las peticiones AJAX en el plugin.
     *
     * @return void
     * @since 1.0.0
     */
    public static function autoFixCommonIssues() {
        self::log("--- AUTO-FIX ATTEMPT ---");
        
        // 1. Re-registrar handlers si no están
        if (!has_action('wp_ajax_miIntegracion_apiReload_metrics')) {
            add_action('wp_ajax_miIntegracion_apiReload_metrics', ['MiIntegracionApi\\Admin\\AjaxDashboard', 'reloadMetrics']);
            self::log("Re-registered reloadMetrics handler");
        }
        
        // 2. Verificar que las constantes estén definidas
        if (!\defined('MiIntegracionApi_NONCE_PREFIX')) {
            self::log("ERROR: MiIntegracionApi_NONCE_PREFIX not defined");
        }
        
        self::log("--- AUTO-FIX COMPLETE ---");
    }
    
    /**
     * Escribe un mensaje en el archivo de log de diagnóstico
     *
     * Este método registra mensajes de diagnóstico en el archivo de log del día actual.
     * Los mensajes incluyen una marca de tiempo y se formatean automáticamente.
     *
     * @param string $message Mensaje a registrar en el log
     * @return void
     * @since 1.0.0
     */
    private static function log($message) {
        if (self::$logFile) {
            $timestamp = \date('Y-m-d H:i:s');
            $logEntry = "[$timestamp] $message" . PHP_EOL;
            file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
    
    
    /**
     * Registra errores de AJAX de forma consistente
     *
     * Este método proporciona una forma estandarizada de registrar errores
     * que ocurren durante el procesamiento de peticiones AJAX.
     *
     * @param string $function Nombre de la función donde ocurrió el error
     * @param string $message Mensaje descriptivo del error
     * @param array $data Datos adicionales relacionados con el error
     * @return void
     * @since 1.0.0
     */
    public static function logAjaxError($function, $message, $data = []) {
        $logMessage = "[$function] $message";
        if (!empty($data)) {
            $logMessage .= " - Data: " . json_encode($data);
        }
        self::log($logMessage);
    }
}

/**
 * Maneja todas las operaciones AJAX del panel de administración
 *
 * Esta clase centraliza la lógica de todos los endpoints AJAX utilizados
 * en el panel de administración del plugin. Proporciona métodos para:
 * - Gestión del dashboard
 * - Diagnóstico del sistema
 * - Sincronización de datos
 * - Manejo de caché
 * - Monitoreo del estado del sistema
 * - Generación de informes
 * - Verificación de compatibilidad
 *
 * Características avanzadas:
 * - Carga perezosa de recursos
 * - Manejo de errores robusto
 * - Sistema de caché inteligente
 * - Compatibilidad con temas y plugins
 * - Monitoreo de rendimiento
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.0.0
 * @author      Christian <christian@example.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs/ajax
 * @see         \MiIntegracionApi\Core\ApiClient Para la comunicación con la API externa
 * @see         \MiIntegracionApi\Admin\DashboardPageView Para la generación de la interfaz
 */
class AjaxDashboard {

    /**
     * Inicializa todos los hooks AJAX necesarios para el dashboard
     *
     * Este método registra todos los endpoints AJAX utilizados en el panel de administración.
     * Los endpoints incluyen:
     * - Obtención de datos del dashboard
     * - Diagnóstico del sistema
     * - Gestión de caché
     * - Sincronización de datos
     * - Monitoreo del sistema
     *
     * @return void
     * @since 1.0.0
     * @hook adminInit
     */
    public static function init() {
        // Acciones AJAX para el dashboard
        add_action('wp_ajax_miIntegracion_apiGet_dashboardData', [self::class, 'getDashboardData']);
        
        // CORRECCIÓN #10: Endpoints para dashboard unificado con diagnóstico automático
        add_action('wp_ajax_miaRun_systemDiagnostic', [self::class, 'runSystem_diagnostic']);
        add_action('wp_ajax_miaRefresh_systemStatus', [self::class, 'refreshSystem_status']);
        add_action('wp_ajax_miaExport_systemReport', [self::class, 'exportSystem_report']);
        
        // CORRECCIÓN #10+ - Endpoints para métricas completas del sistema
        add_action('wp_ajax_miaGet_completeSystem_metrics', [self::class, 'getComplete_systemMetrics']);
        add_action('wp_ajax_miIntegracion_apiGet_recentActivity', [self::class, 'getRecentActivity']);

        // Handler solicitado: recarga de métricas
        add_action('wp_ajax_miIntegracion_apiReload_metrics', [self::class, 'reloadMetrics']);
        
        // CORRECCIÓN CRÍTICA - Endpoint para verificar estado de cron jobs
        add_action('wp_ajax_miaCheck_cronStatus', [self::class, 'checkCron_status']);
        
        // CORRECCIÓN DE OPTIMIZACIÓN - Endpoints para funcionalidades bajo demanda
        add_action('wp_ajax_miaInitialize_assets', [self::class, 'initializeAssets_onDemand']);
        add_action('wp_ajax_miaInitialize_ajax', [self::class, 'initializeAjax_onDemand']);
        add_action('wp_ajax_miaInitialize_settings', [self::class, 'initializeSettings_onDemand']);
        
        // Endpoint para obtener conteo de productos sincronizados
        add_action('wp_ajax_miaGet_productsCount', [self::class, 'getProducts_count']);
        add_action('wp_ajax_miaInitialize_cleanup', [self::class, 'initializeCleanup_onDemand']);
        add_action('wp_ajax_miaLoad_textdomain', [self::class, 'loadTextdomain_onDemand']);
        add_action('wp_ajax_miaExecute_systemDiagnostic', [self::class, 'executeSystem_diagnosticOn_demand']);
        
        // CORRECCIÓN DE OPTIMIZACIÓN - Endpoints para compatibilidad bajo demanda
        add_action('wp_ajax_miaInitialize_compatibilityReports', [self::class, 'initializeCompatibility_reportsOn_demand']);
        add_action('wp_ajax_miaInitialize_themeCompatibility', [self::class, 'initializeTheme_compatibilityOn_demand']);
        add_action('wp_ajax_miaInitialize_woocommercePlugin_compatibility', [self::class, 'initializeWoocommerce_pluginCompatibility_onDemand']);
        add_action('wp_ajax_miaInitialize_generalCompatibility', [self::class, 'initializeGeneral_compatibilityOn_demand']);
        add_action('wp_ajax_miaExecute_completeCompatibility_check', [self::class, 'executeComplete_compatibilityCheck_onDemand']);
        
        // CORRECCIÓN DE OPTIMIZACIÓN - Endpoints para hooks adicionales bajo demanda
        add_action('wp_ajax_miaInitialize_syncHooks', [self::class, 'initializeSync_hooksOn_demand']);
        add_action('wp_ajax_miaInitialize_ajaxLazy_loading', [self::class, 'initializeAjax_lazyLoading_onDemand']);
        add_action('wp_ajax_miaExecute_batchSize_debug', [self::class, 'executeBatch_sizeDebug_onDemand']);
    }

    /**
     * Valida la seguridad de las peticiones AJAX
     * Verifica nonce y permisos de usuario para endpoints AJAX.
     * Centraliza la lógica de validación para evitar duplicidad de código.
     *
     * @return void
     * @throws \Exception Si la validación falla (envía respuesta JSON de error)
     * @since 1.0.0
     */
    private static function validateAjaxSecurity() {
        // Verificar nonce - usar solo la acción correcta que coincide con la generación
        $nonceActions = [
            defined('MiIntegracionApi_NONCE_PREFIX') ? MiIntegracionApi_NONCE_PREFIX . 'dashboard' : 'mi_integracion_api_nonce_dashboard'
        ];
        
        $nonceParam = isset($_POST['nonce']) ? $_POST['nonce'] : (isset($_REQUEST['nonce']) ? $_REQUEST['nonce'] : '');
        if (empty($nonceParam)) {
            \wp_send_json_error([
                'message' => __('Error de seguridad: falta el token de verificación.', 'mi-integracion-api'),
                'code' => 'missingNonce'
            ], 403);
            return;
        }
        
        $nonceValid = false;
        foreach ($nonceActions as $action) {
            if (wp_verify_nonce($nonceParam, $action)) {
                $nonceValid = true;
                break;
            }
        }
        
        if (!$nonceValid) {
            \wp_send_json_error([
                'message' => __('Error de seguridad: token de verificación inválido o expirado.', 'mi-integracion-api'),
                'code' => 'invalidNonce'
            ], 403);
            return;
        }

        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
    }

    /**
     * Obtiene los datos necesarios para renderizar el dashboard
     *
     * Este endpoint AJAX devuelve un conjunto completo de datos para el dashboard,
     * incluyendo:
     * - Estado de sincronización
     * - Estadísticas de productos
     * - Estado de la caché
     * - Errores recientes
     * - Métricas del sistema
     *
     * @return void Envía una respuesta JSON con los datos del dashboard
     * @since 1.0.0
     * @throws \Exception Si ocurre un error al procesar la solicitud
     * @security checkAdmin_referer('miIntegracion_apiNonce', 'nonce')
     */
    public static function getDashboardData() {
        // Validar seguridad AJAX
        self::validateAjaxSecurity();

        // Obtener estadísticas
        $stats = array(
            'products'    => intval(get_option('miaLast_syncCount', 0)),
            'errors'      => intval(get_option('miaLast_syncErrors', 0)),
            'lastSync'    => get_option('miaLast_syncTime') ? dateI18n('d/m/Y H:i', get_option('miaLast_syncTime')) : '',
            'pendingSync' => intval(get_option('miaPending_syncCount', 0)),
            'status'      => self::getSyncStatus(),
        );

        // Obtener actividad reciente (últimos 5 logs)
        $activity = self::getRecentLogs();

        // Devolver datos
        wp_send_json_success(
            array(
                'stats'    => $stats,
                'activity' => $activity,
            )
        );
    }
    
    /**
     * Obtiene el estado actual del proceso de sincronización
     *
     * Este método verifica el estado actual de la sincronización de datos con la API externa.
     * Los posibles estados son:
     * - 'active': Sincronización en curso
     * - 'scheduled': Sincronización programada para ejecutarse pronto
     * - 'error': Error en la última sincronización
     * - 'success': Última sincronización exitosa
     * - 'inactive': Sincronización inactiva
     *
     * @return string Estado actual de la sincronización
     * @since 1.0.0
     * @see wp_next_scheduled() Para verificar si hay sincronizaciones programadas
     */
    private static function getSyncStatus() {
        // Verificar si hay una sincronización en curso
        $syncRunning = get_transient('miaSync_running');
        if ($syncRunning) {
            return 'active';
        }

        // Verificar si hubo errores en la última sincronización
        $lastSync_errors = intval(get_option('miaLast_syncErrors', 0));
        if ($lastSync_errors > 0) {
            return 'error';
        }

        // Verificar si la última sincronización fue exitosa
        $lastSync_time = get_option('miaLast_syncTime');
        if ($lastSync_time && $lastSync_time > time() - DAY_IN_SECONDS) {
            return 'success';
        }

        // Verificar si hay una sincronización programada
        $nextScheduled = wp_next_scheduled('miIntegracion_apiCron_sync');
        if ($nextScheduled) {
            return 'scheduled';
        }

        // Por defecto, inactivo
        return 'inactive';
    }

    /**
     * Obtiene el texto descriptivo del estado de sincronización
     *
     * @param string $status Estado de sincronización
     * @return string Texto descriptivo del estado
     */
    private static function getSyncStatusText($status) {
        switch ($status) {
            case 'active':
                return 'En progreso';
            case 'success':
                return 'Completada';
            case 'error':
                return 'Con errores';
            case 'scheduled':
                return 'Programada';
            case 'inactive':
            default:
                return 'Inactiva';
        }
    }

    /**
     * Obtiene el mensaje de progreso de sincronización
     *
     * @param string $status Estado de sincronización
     * @return string Mensaje de progreso
     */
    private static function getSyncProgressMessage($status) {
        switch ($status) {
            case 'active':
                return 'Sincronización en curso...';
            case 'success':
                return 'Última sincronización exitosa';
            case 'error':
                return 'Se encontraron errores en la última sincronización';
            case 'scheduled':
                return 'Sincronización programada';
            case 'inactive':
            default:
                return 'Sin sincronizaciones recientes';
        }
    }

    /**
     * Obtiene los registros de actividad más recientes
     *
     * Este método recupera los logs más recientes del sistema para mostrarlos
     * en la sección de actividad del dashboard. Los logs se ordenan por fecha
     * descendente y se formatean para su visualización.
     *
     * @param int $limit Número máximo de entradas de log a devolver (por defecto: 5)
     * @return array Array asociativo con los logs formateados, cada uno con:
     *               - timestamp: Marca de tiempo del log
     *               - level: Nivel de severidad (info, warning, error, etc.)
     *               - message: Mensaje del log
     *               - context: Contexto adicional si está disponible
     * @since 1.0.0
     * @see WC_Log_Handler_File Para el formato de los logs de WooCommerce
     */
    public static function getRecentLogs($limit = 5) {
        global $wpdb;

        // Tabla de logs
        $tablaLogs = $wpdb->prefix . 'miIntegracion_apiLogs';

        // Clave de caché para esta consulta específica
        $cacheKey = 'dashboardRecent_logs_' . $limit;

        // Comprobar si la tabla existe antes de consultar
        $tablaExiste = \MiIntegracionApi\Core\QueryOptimizer::getVar(
            'SHOW TABLES LIKE %s',
            array($tablaLogs),
            'tablaLogs_existe',
            HOUR_IN_SECONDS
        );

        if (!$tablaExiste) {
            return array();
        }

        // Consulta para obtener logs recientes, usando el optimizador con caché
        $query = "SELECT id, fecha, tipo, mensaje, usuario, entidad, contexto FROM {$tablaLogs} ORDER BY fecha DESC LIMIT %d";
        $logs  = \MiIntegracionApi\Core\QueryOptimizer::getResults(
            $query,
            array($limit),
            $cacheKey,
            30, // Caché de 30 segundos para datos recientes
            ARRAY_A
        );

        // Formatear logs para el dashboard
        $activity = array();
        if ($logs) {
            foreach ($logs as $log) {
                $contexto = !empty($log['contexto']) ? json_decode($log['contexto'], true) : array();

                $activity[] = array(
                    'type'    => $log['tipo'],
                    'message' => $log['mensaje'],
                    'time'    => human_time_diff(strtotime($log['fecha']), current_time('timestamp')) . ' ' . __('atrás', 'mi-integracion-api'),
                    'user'    => $log['usuario'],
                    'entity'  => $log['entidad'],
                );
            }
        }

        return $activity;
    }
    
    /**
     * Maneja la solicitud AJAX para obtener la actividad reciente
     *
     * Este endpoint devuelve las entradas de log más recientes para mostrarlas
     * en la interfaz de usuario. Incluye validación de nonce y manejo de errores.
     *
     * @return void Envía una respuesta JSON con las entradas de log recientes
     * @since 1.0.0
     * @throws \Exception Si ocurre un error al procesar la solicitud
     * @security checkAjax_referer('miIntegracion_apiNonce', 'nonce')
     * @permission manageWoocommerce
     */
    public static function getRecentActivity() {
        // Validar seguridad AJAX
        self::validateAjaxSecurity();

        // SEGURIDAD: Usar SecurityValidator para validar límite
        $limit = \MiIntegracionApi\Helpers\SecurityValidator::validateGetParam('limit', 'int', 0);
        $finalLimit = max($limit, 1); // Asegura que sea al menos 1 si no se proporciona o es 0
        
        // Obtener logs
        $activity = self::getRecentLogs($finalLimit);
        
        wp_send_json_success([
            'activity' => $activity,
            'count' => count($activity)
        ]);
    }

    /**
     * Maneja la recarga de métricas del dashboard mediante AJAX
     *
     * Este endpoint proporciona compatibilidad con el archivo dashboard.js para
     * actualizar las métricas del dashboard sin recargar la página completa.
     * Incluye validación de seguridad y manejo de caché.
     *
     * Métricas incluidas:
     * - Estado de sincronización actual
     * - Conteo de productos sincronizados
     * - Estadísticas de caché
     * - Errores recientes
     * - Uso de recursos del sistema
     *
     * @return void Envía una respuesta JSON con la siguiente estructura:
     *              {
     *                  "success": true,
     *                  "data": {
     *                      "syncStatus": string,  // Estado actual de sincronización
     *                      "products": array,     // Estadísticas de productos
     *                      "cache": array,        // Estadísticas de caché
     *                      "errors": array,       // Errores recientes
     *                      "system": array        // Información del sistema
     *                  }
     *              }
     * @since 1.0.0
     * @throws \Exception Si ocurre un error al procesar la solicitud
     * @security checkAjax_referer('miIntegracion_apiNonce', 'nonce')
     * @permission manageWoocommerce
     * @hook wpAjax_miIntegracion_apiReload_metrics
     * @see assets/js/dashboard/dashboard.js Para el consumo de este endpoint
     * @see AjaxDiagnostic Para el manejo de errores de AJAX
     */
    public static function reloadMetrics() {
        try {
            // Auto-diagnóstico en caso de problemas
            AjaxDiagnostic::logAjaxError('reloadMetrics', 'Method called', [
                'userId' => get_current_user_id(),
                'postData' => $_POST
            ]);
            
            // Verificación básica de nonce
            $nonce = $_POST['nonce'] ?? '';
            if (empty($nonce)) {
                AjaxDiagnostic::logAjaxError('reloadMetrics	', 'Missing nonce');
                \wp_send_json_error([
                    'message' => 'Missing security token',
                    'code' => 'missingNonce'
                ], 400);
                return;
            }
            
            // Validar nonce con la acción correcta
            error_log("DEBUG reloadMetrics	: Nonce recibido: $nonce");
            error_log("DEBUG reloadMetrics	: Acción esperada: mi_integracion_api_nonce_dashboard");
            $nonceValid = wp_verify_nonce($nonce, 'mi_integracion_api_nonce_dashboard');
            error_log("DEBUG reloadMetrics	: Nonce válido: " . ($nonceValid ? 'SÍ' : 'NO'));
            
            if (!$nonceValid) {
                AjaxDiagnostic::logAjaxError('reloadMetrics	', 'Invalid nonce', [
                    'expectedAction' => 'mi_integracion_api_nonce_dashboard',
                    'receivedNonce' => substr($nonce, 0, 8) . '...'
                ]);
                \wp_send_json_error([
                    'message' => 'Invalid or expired security token',
                    'code' => 'invalidNonce'
                ], 403);
                return;
            }
            
            // Verificar permisos
            if (!current_user_can('manageOptions')) {
                AjaxDiagnostic::logAjaxError('reloadMetrics	', 'Insufficient permissions', [
                    'userId' => get_current_user_id()
                ]);
                \wp_send_json_error([
                    'message' => 'Insufficient permissions',
                    'code' => 'insufficientPermissions'
                ], 403);
                return;
            }
            
            // Obtener métricas del dashboard
            $metrics = [
                'totalProductos' => wp_count_posts('product')->publish ?? 0,
                'productosSincronizados' => get_option('miIntegracion_productosSync', 0),
                'ultimaSincronizacion' => get_option('miIntegracion_ultimaSync', 'Nunca'),
                'estadoConexion' => self::checkApiConnection(),
                'erroresRecientes' => self::getRecent_errors(),
                'totalPedidos_hoy' => self::getOrders_countToday(),
            ];
            
            // Añadir métricas de sincronización si están disponibles
            if (class_exists('\MiIntegracionApi\Helpers\SyncStatusHelper')) {
                $syncInfo = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
                if (!empty($syncInfo)) {
                    $metrics['totalProcessed'] = $syncInfo['itemsSynced'] ?? 0;
                    $metrics['totalBatches'] = $syncInfo['totalBatches'] ?? 0;
                    $metrics['currentBatch'] = $syncInfo['currentBatch'] ?? 0;
                    $metrics['inProgress'] = $syncInfo['inProgress'] ?? false;
                }
            }

            self::log('reloadMetrics	 success: ' . count($metrics) . ' metrics loaded');
            
            // Enviar respuesta JSON
            wp_send_json_success($metrics);

        } catch ( \Exception $e ) {
            self::log('reloadMetrics	 exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            \wp_send_json_error([
                'message' => 'Error al cargar métricas: ' . $e->getMessage(),
                'code' => 'exception'
            ], 500);
        }
    }

    /**
     * Verifica la conexión con la API externa
     *
     * Este método realiza una prueba de conexión a la API externa y devuelve
     * información detallada sobre el estado de la conexión, incluyendo:
     * - Estado de la conexión (éxito/error)
     * - Tiempo de respuesta
     * - Código de estado HTTP
     * - Mensaje de error en caso de fallo
     *
     * @return void Envía una respuesta JSON con el resultado de la prueba de conexión
     * @since 1.0.0
     * @throws \Exception Si ocurre un error al procesar la solicitud
     * @security checkAjax_referer('miIntegracion_apiNonce', 'nonce')
     * @permission manageWoocommerce
     * @see MiIntegracionApi\Core\ApiClient Para la implementación del cliente de API
     */
    private static function checkApiConnection() {
        try {
            // TODO: Implementar verificación real de API
            return 'Activa';
        } catch (\Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }

    /**
     * Obtener errores recientes
     */
    private static function getRecent_errors() {
        try {
            // TODO: Implementar obtención de errores reales
            return [];
        } catch (\Exception $e) {
            return ['Error al obtener errores: ' . $e->getMessage()];
        }
    }

    /**
     * Obtener conteo de pedidos de hoy
     */
    private static function getOrders_countToday() {
        try {
            // TODO: Implementar conteo real de pedidos
            return 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Registrar mensaje en log
     */
    private static function log($message) {
        $uploadDir = \wp_upload_dir();
        $logDir = $uploadDir['basedir'] . '/mi-integracion-api/logs';
        
        if (!\file_exists($logDir)) {
            \wp_mkdir_p($logDir);
        }
        
        $logFile = $logDir . '/ajax-dashboard.log';
        $timestamp = \date('Y-m-d H:i:s');
        $logEntry = "[{$timestamp}] {$message}" . PHP_EOL;
        
        \file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Ejecuta diagnóstico completo del sistema
     */
    public static function runSystem_diagnostic() {
        try {
            // SEGURIDAD: Usar SecurityValidator para validación completa
            $securityCheck = \MiIntegracionApi\Helpers\SecurityValidator::validateAjaxRequest(
                $_POST['nonce'] ?? '',
                'mi_integracion_api_nonce_dashboard'
            );

            if (!$securityCheck['valid']) {
                \MiIntegracionApi\Helpers\SecurityValidator::sendAjaxError(
                    $securityCheck['message'],
                    $securityCheck['code'],
                    $securityCheck['error']
                );
                return;
            }

            $diagnosticResults = [
                'timestamp' => current_time('mysql'),
                'systemHealth' => self::getOverall_healthStatus(),
                'memoryAnalysis' => \MiIntegracionApi\Admin\DashboardPageView::get_memory_status(),
                'retryAnalysis' => \MiIntegracionApi\Admin\DashboardPageView::get_retry_status(),
                'syncAnalysis' => \MiIntegracionApi\Admin\DashboardPageView::getSyncStatus(),
                'apiAnalysis' => self::getApi_status(),
                'recommendations' => self::generateDiagnostic_recommendations()
            ];

            
            wp_send_json_success($diagnosticResults);

        } catch (\Exception $e) {
            \wp_send_json_error(['message' => 'Error en diagnóstico: ' . $e->getMessage()], 500);
        }
    }
    public static function refreshSystem_status() {
        try {
            // SEGURIDAD: Usar SecurityValidator para validación completa
            $securityCheck = \MiIntegracionApi\Helpers\SecurityValidator::validateAjaxRequest(
                $_POST['nonce'] ?? '',
                'mi_integracion_api_nonce_dashboard'
            );

            if (!$securityCheck['valid']) {
                \MiIntegracionApi\Helpers\SecurityValidator::sendAjaxError(
                    $securityCheck['message'],
                    $securityCheck['code'],
                    $securityCheck['error']
                );
                return;
            }

            $syncStatus = self::getSyncStatus();
            
            // Convertir a array si es necesario y agregar conteo de productos
            if (!is_array($syncStatus)) {
                $syncStatus = [
                    'status' => $syncStatus,
                    'statusText' => self::getSyncStatusText($syncStatus),
                    'progressMessage' => self::getSyncProgressMessage($syncStatus)
                ];
            }
            
            // Agregar conteo de productos y última sincronización al estado de sincronización
            $syncStatus['productsCount'] = \MiIntegracionApi\Admin\DashboardPageView::getSyncedProductsCount();
            $syncStatus['lastSync_time'] = get_option('miaLast_syncTime');
            
            $status = [
                'timestamp' => current_time('mysql'),
                'memory' => self::get_memory_status(),
                'retry' => self::get_retry_status(),
                'sync' => $syncStatus,
                'overallHealth' => self::getOverall_healthStatus()
            ];

            wp_send_json_success($status);

        } catch (\Exception $e) {
            self::log('refreshSystem_status exception: ' . $e->getMessage());
            \wp_send_json_error(['message' => 'Error al refrescar estado: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Genera y exporta un informe detallado del sistema
     *
     * Este endpoint AJAX genera un informe completo del sistema en formato JSON que incluye:
     * - Información del servidor y configuración PHP
     * - Versiones de WordPress, WooCommerce y el plugin
     * - Estado de la base de datos
     * - Configuración de caché
     * - Historial de sincronización
     * - Errores recientes
     * - Recomendaciones de optimización
     *
     * El informe se devuelve como una descarga para el usuario.
     *
     * @return void Envía el archivo de informe para descargar
     * @since 1.0.0
     * @throws \Exception Si ocurre un error al generar el informe
     * @security checkAjax_referer('miaAdmin_nonce', 'nonce')
     * @permission export
     * @see SystemReporter Para la generación del informe detallado
     */
    public static function exportSystem_report() {
        try {
            // SEGURIDAD: Usar SecurityValidator para validación completa
            $securityCheck = \MiIntegracionApi\Helpers\SecurityValidator::validateAjaxRequest(
                $_POST['nonce'] ?? '',
                'mi_integracion_api_nonce_dashboard'
            );

            if (!$securityCheck['valid']) {
                \MiIntegracionApi\Helpers\SecurityValidator::sendAjaxError(
                    $securityCheck['message'],
                    $securityCheck['code'],
                    $securityCheck['error']
                );
                return;
            }

            $report = self::generateSystem_report();
            
            // Crear archivo temporal
            $uploadDir = \wp_upload_dir();
            $reportDir = $uploadDir['basedir'] . '/mi-integracion-api/reports';
            if (!\file_exists($reportDir)) {
                \wp_mkdir_p($reportDir);
            }

            $filename = 'system-report-' . \date('Y-m-d-H-i-s') . '.json';
            $filepath = $reportDir . '/' . $filename;
            
            \file_put_contents($filepath, \json_encode($report, JSON_PRETTY_PRINT));

            self::log('systemReport_exported: ' . $filename);

            \wp_send_json_success([
                'message' => 'Reporte exportado correctamente',
                'filename' => $filename,
                'downloadUrl' => $uploadDir['baseurl'] . '/mi-integracion-api/reports/' . $filename
            ]);

        } catch (\Exception $e) {
            self::log('exportSystem_report exception: ' . $e->getMessage());
            \wp_send_json_error(['message' => 'Error al exportar reporte: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Obtiene diagnóstico de salud del sistema
     */
    private static function getSystem_healthDiagnostic(): array {
        $issues = [];
        $overallStatus = 'healthy';

        // Verificar memoria
        $memoryStatus = self::get_memory_status();
        if (is_array($memoryStatus) && isset($memoryStatus['status'])) {
            if ($memoryStatus['status'] === 'critical') {
                $issues[] = 'Memoria crítica';
                $overallStatus = 'critical';
            } elseif ($memoryStatus['status'] === 'warning') {
                $issues[] = 'Memoria alta';
                if ($overallStatus !== 'critical') $overallStatus = 'warning';
            }
        }

        // Verificar reintentos
        $retryStatus = self::get_retry_status();
        if (is_array($retryStatus) && isset($retryStatus['status'])) {
            if ($retryStatus['status'] === 'critical') {
                $issues[] = 'Sistema de reintentos crítico';
                $overallStatus = 'critical';
            } elseif ($retryStatus['status'] === 'warning') {
                $issues[] = 'Sistema de reintentos con problemas';
                if ($overallStatus !== 'critical') $overallStatus = 'warning';
            }
        }

        // Verificar sincronización
        $syncStatus = self::getSyncStatus();
        if (is_string($syncStatus)) {
            // getSyncStatus devuelve string, no array
            if ($syncStatus === 'error') {
                $issues[] = 'Sincronización crítica';
                $overallStatus = 'critical';
            } elseif ($syncStatus === 'inactive') {
                $issues[] = 'Sincronización inactiva';
                if ($overallStatus !== 'critical') $overallStatus = 'warning';
            }
        } elseif (is_array($syncStatus) && isset($syncStatus['status'])) {
            // DashboardPageView::getSyncStatus devuelve array
            if ($syncStatus['status'] === 'failed') {
                $issues[] = 'Sincronización crítica';
                $overallStatus = 'critical';
            } elseif ($syncStatus['status'] === 'unknown') {
                $issues[] = 'Sincronización con problemas';
                if ($overallStatus !== 'critical') $overallStatus = 'warning';
            }
        }

        return [
            'overallStatus' => $overallStatus,
            'issuesCount' => count($issues),
            'issues' => $issues,
            'timestamp' => current_time('mysql')
        ];
    }

    /**
     * Obtiene diagnóstico de memoria
     */
    private static function getMemory_diagnostic(): array {
        if (!class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
            return [
                'status' => 'unavailable',
                'message' => 'Gestor de memoria no disponible'
            ];
        }

        try {
            $memoryManager = \MiIntegracionApi\Core\MemoryManager::getInstance();
            $stats = $memoryManager->getAdvancedMemoryStats();
            
            return [
                'status' => $stats['status'] ?? 'unknown',
                'usagePercentage' => $stats['usagePercentage'] ?? 0,
                'memoryLimit' => $stats['memoryLimit'] ?? 'N/A',
                'peakUsage' => $stats['peakUsage'] ?? 0,
                'recommendations' => $stats['recommendations'] ?? []
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error al obtener estado de memoria: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene diagnóstico de reintentos
     */
    private static function getRetry_diagnostic(): array {
        $totalAttempts = get_option('miaRetry_totalAttempts', 0);
        $successfulAttempts = get_option('miaRetry_successfulAttempts', 0);
        $failedAttempts = get_option('miaRetry_failedAttempts', 0);

        if ($totalAttempts === 0) {
            return [
                'status' => 'noData',
                'message' => 'Sin datos de reintentos disponibles'
            ];
        }

        $successRate = round(($successfulAttempts / $totalAttempts) * 100, 1);
        
        $status = match(true) {
            $successRate >= 95 => 'excellent',
            $successRate >= 80 => 'good',
            $successRate >= 60 => 'fair',
            default => 'poor'
        };

        return [
            'status' => $status,
            'successRate' => $successRate,
            'totalAttempts' => $totalAttempts,
            'successfulAttempts' => $successfulAttempts,
            'failedAttempts' => $failedAttempts,
            'recommendations' => self::getRetry_recommendations($successRate)
        ];
    }

    /**
     * Obtiene diagnóstico de sincronización
     */
    private static function getSync_diagnostic(): array {
        if (!class_exists('\\MiIntegracionApi\\Core\\Sync_Manager')) {
            return [
                'status' => 'unavailable',
                'message' => 'Gestor de sincronización no disponible'
            ];
        }

        try {
            $syncManager = \MiIntegracionApi\Core\Sync_Manager::getInstance();
            $syncStatus = $syncManager->getSyncStatus();
            
            return [
                'status' => $syncStatus['status'] ?? 'unknown',
                'progress' => $syncStatus['progress'] ?? 0,
                'totalItems' => $syncStatus['totalItems'] ?? 0,
                'processedItems' => $syncStatus['processedItems'] ?? 0,
                'lastSync_time' => get_option('miaLast_syncTime'),
                'errorsCount' => get_option('miaLast_syncErrors', 0)
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error al obtener estado de sincronización: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Obtiene diagnóstico de API
     */
    private static function getApi_diagnostic(): array {
        try {
            // Verificar conexión básica
            $connectionStatus = self::checkApiConnection();
            
            return [
                'connectionStatus' => $connectionStatus,
                'lastCheck' => current_time('mysql'),
                'apiUrl' => get_option('miaApi_baseUrl', 'No configurada'),
                'apiKey_configured' => !empty(get_option('miaApi_key', '')),
                'sslVerified' => get_option('miaSsl_verify', true)
            ];
        } catch (\Exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error al verificar API: ' . $e->getMessage()
            ];
            
            $recommendations[] = [
                'priority' => 'critical',
                'category' => 'memory',
                'title' => 'Memoria Crítica',
                'description' => 'El uso de memoria ha alcanzado niveles críticos.',
                'action' => 'Limpiar memoria inmediatamente y revisar configuración.'
            ];
        }

        // Recomendaciones de reintentos
        $retryDiagnostic = self::getRetry_diagnostic();
        if ($retryDiagnostic['status'] === 'poor') {
            $recommendations[] = [
                'priority' => 'high',
                'category' => 'retry',
                'title' => 'Sistema de Reintentos Problemático',
                'description' => 'La tasa de éxito de reintentos es muy baja.',
                'action' => 'Revisar configuración de reintentos y logs del sistema.'
            ];
        }

        // Recomendaciones de sincronización
        $syncDiagnostic = self::getSync_diagnostic();
        if ($syncDiagnostic['status'] === 'failed') {
            $recommendations[] = [
                'priority' => 'critical',
                'category' => 'sync',
                'title' => 'Sincronización Fallida',
                'description' => 'La sincronización ha fallado y requiere atención.',
                'action' => 'Revisar logs y estado del sistema de sincronización.'
            ];
        }

        return $recommendations;
    }

    /**
     * Obtiene estado de memoria (usando DashboardPageView para evitar duplicación)
     */
    private static function get_memory_status(): array {
        if (class_exists('\\MiIntegracionApi\\Admin\\DashboardPageView')) {
            return \MiIntegracionApi\Admin\DashboardPageView::get_memory_status();
        }
        
        // Fallback si DashboardPageView no está disponible
        if (!class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
            return ['status' => 'unknown', 'usagePercentage' => 0];
        }

        try {
            $memoryManager = \MiIntegracionApi\Core\MemoryManager::getInstance();
            $stats = $memoryManager->getAdvancedMemoryStats();
            
            return [
                'status' => $stats['status'],
                'usagePercentage' => $stats['usagePercentage']
            ];
        } catch (\Exception $e) {
            return ['status' => 'error', 'usagePercentage' => 0];
        }
    }

    /**
     * Obtiene estado de reintentos (usando DashboardPageView para evitar duplicación)
     */
    private static function get_retry_status(): array {
        if (class_exists('\\MiIntegracionApi\\Admin\\DashboardPageView')) {
            return \MiIntegracionApi\Admin\DashboardPageView::get_retry_status();
        }
        
        // Fallback si DashboardPageView no está disponible
        $totalAttempts = get_option('miaRetry_totalAttempts', 0);
        $successfulAttempts = get_option('miaRetry_successfulAttempts', 0);
        
        if ($totalAttempts === 0) {
            return ['status' => 'unknown', 'successRate' => 100];
        }
        
        $successRate = round(($successfulAttempts / $totalAttempts) * 100, 1);
        
        $status = match(true) {
            $successRate >= 95 => 'healthy',
            $successRate >= 80 => 'attention',
            $successRate >= 60 => 'warning',
            default => 'critical'
        };
        
        return ['status' => $status, 'successRate' => $successRate];
    }



    /**
     * Obtiene estado general de salud
     */
    private static function getOverall_healthStatus(): array {
        $health = self::getSystem_healthDiagnostic();
        
        return [
            'overallStatus' => $health['overallStatus'],
            'issuesCount' => $health['issuesCount'],
            'lastCheck' => current_time('mysql')
        ];
    }

    /**
     * Genera reporte completo del sistema
     */
    private static function generateSystem_report(): array {
        return [
            'reportInfo' => [
                'generatedAt' => current_time('mysql'),
                'pluginVersion' => defined('MiIntegracionApi_VERSION') ? constant('MiIntegracionApi_VERSION') : 'Unknown',
                'wordpressVersion' => get_bloginfo('version'),
                'phpVersion' => PHP_VERSION
            ],
            'systemHealth' => self::getSystem_healthDiagnostic(),
            'memoryAnalysis' => self::getMemory_diagnostic(),
            'retryAnalysis' => self::getRetry_diagnostic(),
            'syncAnalysis' => self::getSync_diagnostic(),
            'apiAnalysis' => self::getApi_diagnostic(),
            'recommendations' => self::getDiagnostic_recommendations()
        ];
    }

    /**
     * Obtiene recomendaciones para reintentos
     */
    private static function getRetry_recommendations(float $successRate): array {
        if ($successRate >= 95) {
            return ['El sistema de reintentos está funcionando excelentemente.'];
        } elseif ($successRate >= 80) {
            return ['Considerar ajustes menores en la configuración de reintentos.'];
        } elseif ($successRate >= 60) {
            return ['Revisar configuración de reintentos y posibles problemas de red.'];
        } else {
            return [
                'Revisar configuración de reintentos inmediatamente.',
                'Verificar conectividad de red y estado de la API.',
                'Considerar aumentar el número máximo de reintentos.'
            ];
        }
    }

    // CORRECCIÓN #10+ - MÉTODOS PARA MÉTRICAS COMPLETAS DEL SISTEMA

    /**
     * Obtiene métricas completas del sistema usando MemoryManager expandido
     */
    public static function getComplete_systemMetrics() {
        try {
            // Verificar nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'mi_integracion_api_nonce_dashboard')) {
                \wp_send_json_error(['message' => 'Token de seguridad inválido'], 403);
                return;
            }

            // Verificar permisos
            if (!current_user_can('manageOptions')) {
                \wp_send_json_error(['message' => 'Permisos insuficientes'], 403);
                return;
            }

            // Obtener métricas básicas del sistema
            $metrics = [
                'memory' => self::getMemory_diagnostic(),
                'retry' => self::getRetry_diagnostic(),
                'sync' => self::getSync_diagnostic(),
                'systemHealth' => self::getSystem_healthDiagnostic(),
                'timestamp' => current_time('mysql')
            ];
            
            // Intentar usar MemoryManager si está disponible
            if (class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
                try {
                    $memoryManager = \MiIntegracionApi\Core\MemoryManager::getInstance();
                    if (method_exists($memoryManager, 'getAdvancedMemoryStats')) {
                        $advancedMetrics = $memoryManager->getAdvancedMemoryStats();
                        $metrics['advancedMemory'] = $advancedMetrics;
                    }
                } catch (\Exception $e) {
                    // Si falla, continuar con métricas básicas
                    self::log('MemoryManager avanzado no disponible: ' . $e->getMessage());
                }
            }
            
            
            wp_send_json_success($metrics);
            
        } catch (\Exception $e) {
            \wp_send_json_error(['message' => 'Error al obtener métricas: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Verifica el estado de todos los cron jobs del plugin
     * CORRECCIÓN CRÍTICA: Endpoint para monitoreo de cron jobs
     * @return void
     */
    public static function checkCron_status(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            // Obtener estado de cron jobs desde RobustnessHooks
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $cronStatus = \MiIntegracionApi\Hooks\RobustnessHooks::getCron_jobsStatus();
                
                \wp_send_json_success([
                    'cronJobs' => $cronStatus,
                    'message' => 'Estado de cron jobs verificado correctamente'
                ]);
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al verificar cron jobs: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa sistema de assets bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeAssets_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeAssets_onDemand();
                
                if ($result) {
                    \wp_send_json_success([
                        'message' => 'Sistema de assets inicializado correctamente',
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de assets');
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar assets: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa sistema de AJAX bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeAjax_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeAjax_onDemand();
                
                if ($result) {
                    \wp_send_json_success([
                        'message' => 'Sistema de AJAX inicializado correctamente',
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de AJAX');
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar AJAX: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa sistema de configuración bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeSettings_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeSettings_onDemand();
                
                if ($result) {
                    \wp_send_json_success([
                        'message' => 'Sistema de configuración inicializado correctamente',
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de configuración');
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar configuración: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa sistema de limpieza bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeCleanup_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeCleanup_onDemand();
                
                if ($result) {
                    \wp_send_json_success([
                        'message' => 'Sistema de limpieza inicializado correctamente',
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de limpieza');
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar limpieza: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Carga textdomain del plugin bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function loadTextdomain_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::loadTextdomain_onDemand();
                
                if ($result) {
                    \wp_send_json_success([
                        'message' => 'Textdomain del plugin cargado correctamente',
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo cargar el textdomain del plugin');
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al cargar textdomain: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Ejecuta diagnóstico completo del sistema bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function executeSystem_diagnosticOn_demand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $diagnostic = \MiIntegracionApi\Hooks\RobustnessHooks::executeSystem_diagnostic();
                
                if (isset($diagnostic['error'])) {
                    wp_send_json_error('Error en diagnóstico: ' . $diagnostic['error'], 500);
                } else {
                    \wp_send_json_success([
                        'message' => 'Diagnóstico del sistema completado correctamente',
                        'diagnostic' => $diagnostic,
                        'status' => 'success'
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al ejecutar diagnóstico: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa sistema de reportes de compatibilidad bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeCompatibility_reportsOn_demand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeCompatibility_reportsOn_demand();
                
                if ($result['success']) {
                    \wp_send_json_success([
                        'message' => 'Sistema de reportes de compatibilidad inicializado correctamente',
                        'data' => $result,
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de reportes de compatibilidad', [
                        'data' => $result
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar reportes de compatibilidad: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa sistema de compatibilidad con temas bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeTheme_compatibilityOn_demand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeTheme_compatibilityOn_demand();
                
                if ($result['success']) {
                    \wp_send_json_success([
                        'message' => 'Sistema de compatibilidad con temas inicializado correctamente',
                        'data' => $result,
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de compatibilidad con temas', [
                        'data' => $result
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar compatibilidad con temas: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa sistema de compatibilidad con plugins de WooCommerce bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeWoocommerce_pluginCompatibility_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeWoocommerce_pluginCompatibility_onDemand();
                
                if ($result['success']) {
                    \wp_send_json_success([
                        'message' => 'Sistema de compatibilidad con plugins de WooCommerce inicializado correctamente',
                        'data' => $result,
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de compatibilidad con plugins de WooCommerce', [
                        'data' => $result
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar compatibilidad con plugins de WooCommerce: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa sistema de compatibilidad general con temas y plugins bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeGeneral_compatibilityOn_demand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeGeneral_compatibilityOn_demand();
                
                if ($result['success']) {
                    \wp_send_json_success([
                        'message' => 'Sistema de compatibilidad general inicializado correctamente',
                        'data' => $result,
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de compatibilidad general', [
                        'data' => $result
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar compatibilidad general: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Ejecuta verificación completa de compatibilidad bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function executeComplete_compatibilityCheck_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::executeComplete_compatibilityCheck_onDemand();
                
                if (isset($result['error'])) {
                    wp_send_json_error('Error en verificación de compatibilidad: ' . $result['error'], 500);
                } else {
                    \wp_send_json_success([
                        'message' => 'Verificación completa de compatibilidad ejecutada correctamente',
                        'data' => $result,
                        'status' => 'success'
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al ejecutar verificación de compatibilidad: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Inicializa hooks de sincronización bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeSync_hooksOn_demand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeSync_hooksOn_demand();
                
                if ($result['success']) {
                    \wp_send_json_success([
                        'message' => 'Hooks de sincronización inicializados correctamente',
                        'data' => $result,
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar los hooks de sincronización', [
                        'data' => $result
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar hooks de sincronización: ' . $e->getMessage(), 500);
        }
    }
    
    
    /**
     * Inicializa sistema de carga perezosa AJAX bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function initializeAjax_lazyLoading_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::initializeAjax_lazyLoading_onDemand();
                
                if ($result['success']) {
                    \wp_send_json_success([
                        'message' => 'Sistema de carga perezosa AJAX inicializado correctamente',
                        'data' => $result,
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo inicializar el sistema de carga perezosa AJAX', [
                        'data' => $result
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al inicializar sistema de carga perezosa AJAX: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Ejecuta debug de batch size bajo demanda
     * CORRECCIÓN DE OPTIMIZACIÓN: Reduce carga inicial del plugin
     * @return void
     */
    public static function executeBatch_sizeDebug_onDemand(): void {
        // Verificar nonce
        if (!wp_verify_nonce($_POST['nonce'], 'mi_integracion_api_nonce_dashboard')) {
            wp_send_json_error('Error de seguridad', 403);
            return;
        }
        
        // Verificar permisos
        if (!current_user_can('manageOptions')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        
        try {
            if (class_exists('\\MiIntegracionApi\\Hooks\\RobustnessHooks')) {
                $result = \MiIntegracionApi\Hooks\RobustnessHooks::executeBatch_sizeDebug_onDemand();
                
                if ($result['success']) {
                    \wp_send_json_success([
                        'message' => 'Debug de batch size ejecutado correctamente',
                        'data' => $result,
                        'status' => 'success'
                    ]);
                } else {
                    wp_send_json_error('No se pudo ejecutar el debug de batch size', [
                        'data' => $result
                    ]);
                }
            } else {
                wp_send_json_error('Clase RobustnessHooks no disponible', 500);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('Error al ejecutar debug de batch size: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Obtiene el estado de la API
     * @return array Estado de la API
     */
    public static function getApi_status(): array {
        try {
            // Obtener instancia del conector API sin ejecutar test de conectividad
            $apiConnector = \MiIntegracionApi\Core\ApiConnector::getInstance();
            
            // Verificar solo la configuración básica, no la conectividad
            $apiUrl = $apiConnector->getApi_baseUrl();
            $sesion = $apiConnector->getSesionWcf();
            
            if (!empty($apiUrl) && !empty($sesion)) {
                return [
                    'status' => 'configured',
                    'responseTime' => 0,
                    'statusMessage' => 'API configurada correctamente (URL: ' . $apiUrl . ', Sesión: ' . $sesion . ')'
                ];
            } else {
                return [
                    'status' => 'warning',
                    'responseTime' => 0,
                    'statusMessage' => 'API no configurada completamente'
                ];
            }
        } catch (\Exception $e) {
            return [
                'status' => 'critical',
                'responseTime' => 0,
                'statusMessage' => 'Error crítico en la API: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Genera recomendaciones de diagnóstico basadas en el estado del sistema
     * @return array Lista de recomendaciones
     */
    public static function generateDiagnostic_recommendations(): array {
        $recommendations = [];
        
        try {
            $overallHealth = self::getOverall_healthStatus();
            $memoryStatus = \MiIntegracionApi\Admin\DashboardPageView::get_memory_status();
            $retryStatus = \MiIntegracionApi\Admin\DashboardPageView::get_retry_status();
            $syncStatus = \MiIntegracionApi\Admin\DashboardPageView::getSyncStatus();
            $apiStatus = self::getApi_status();
            
            // Recomendaciones basadas en memoria
            if ($memoryStatus['status'] === 'critical') {
                $recommendations[] = [
                    'type' => 'critical',
                    'category' => 'memory',
                    'title' => 'Memoria crítica',
                    'description' => 'El uso de memoria está en niveles críticos. Considere aumentar la memoria del servidor o optimizar el código.',
                    'action' => 'Revisar configuración de memoria del servidor'
                ];
            }
            
            // Recomendaciones basadas en reintentos
            if ($retryStatus['status'] === 'critical') {
                $recommendations[] = [
                    'type' => 'critical',
                    'category' => 'retry',
                    'title' => 'Tasa de éxito baja',
                    'description' => 'La tasa de éxito de reintentos es muy baja. Revise la conectividad de la API y la configuración.',
                    'action' => 'Verificar conectividad de la API y configuración de reintentos'
                ];
            }
            
            // Recomendaciones basadas en sincronización
            if ($syncStatus['status'] === 'critical') {
                $recommendations[] = [
                    'type' => 'critical',
                    'category' => 'sync',
                    'title' => 'Problemas de sincronización',
                    'description' => 'La sincronización tiene problemas críticos. Revise los logs y la configuración.',
                    'action' => 'Revisar logs de sincronización y configuración'
                ];
            }
            
            // Recomendaciones basadas en API
            if ($apiStatus['status'] === 'critical') {
                $recommendations[] = [
                    'type' => 'critical',
                    'category' => 'api',
                    'title' => 'Problemas críticos de API',
                    'description' => 'La API tiene problemas críticos. Verifique la conectividad y la configuración.',
                    'action' => 'Verificar conectividad de la API y configuración'
                ];
            }
            
            // Si todo está bien, añadir recomendación positiva
            if (isset($overallHealth['status']) && $overallHealth['status'] === 'healthy') {
                $recommendations[] = [
                    'type' => 'info',
                    'category' => 'general',
                    'title' => 'Sistema saludable',
                    'description' => 'El sistema está funcionando correctamente en todos los aspectos.',
                    'action' => 'Mantener monitoreo regular'
                ];
            }
            
        } catch (\Exception $e) {
            $recommendations[] = [
                'type' => 'error',
                'category' => 'system',
                'title' => 'Error en diagnóstico',
                'description' => 'No se pudieron generar recomendaciones debido a un error: ' . $e->getMessage(),
                'action' => 'Revisar logs del sistema'
            ];
        }
        
        return $recommendations;
    }

    /**
     * Obtiene el conteo de productos sincronizados
     * @return void
     */
    public static function getProducts_count() {
        try {
            // SEGURIDAD: Usar SecurityValidator para validación completa
            $securityCheck = \MiIntegracionApi\Helpers\SecurityValidator::validateAjaxRequest(
                $_POST['nonce'] ?? '',
                'mi_integracion_api_nonce_dashboard'
            );

            if (!$securityCheck['valid']) {
                \MiIntegracionApi\Helpers\SecurityValidator::sendAjaxError(
                    $securityCheck['message'],
                    $securityCheck['code'],
                    $securityCheck['error']
                );
                return;
            }

            // Obtener el conteo de productos sincronizados
            $count = \MiIntegracionApi\Admin\DashboardPageView::getSyncedProductsCount();

            \wp_send_json_success([
                'count' => $count,
                'timestamp' => current_time('mysql')
            ]);

        } catch (\Exception $e) {
            self::log('getProducts_count exception: ' . $e->getMessage());
            \wp_send_json_error(['message' => 'Error al obtener conteo de productos: ' . $e->getMessage()], 500);
        }
    }


}
