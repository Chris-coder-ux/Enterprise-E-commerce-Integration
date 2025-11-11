<?php
/**
 * Vista de página para la gestión de caché
 *
 * Esta clase integra la funcionalidad existente de CacheTTLSettings
 * con el CacheManager y proporciona una interfaz unificada para
 * la gestión de caché del plugin.
 *
 * @package MiIntegracionApi\Admin
 * @since 2.0.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para renderizar la página de gestión de caché
 *
 * Integra la funcionalidad existente de CacheTTLSettings con
 * el CacheManager para proporcionar una interfaz completa de
 * gestión de caché.
 *
 * @package MiIntegracionApi\Admin
 * @since 2.0.0
 */
class CachePageView {

    /**
     * Buffer de registros para la consola en UI
     *
     * @var array<int, array{time:string,type:string,message:string}>
     */
    private static array $consoleLogs = [];

    /**
     * Añade una línea a la consola
     *
     * @param string $type   info|success|warning|error
     * @param string $message Mensaje a mostrar
     * @return void
     */
    private static function add_console_log(string $type, string $message): void {
        $time = date('H:i:s');
        self::$consoleLogs[] = [
            'time' => $time,
            'type' => $type,
            'message' => $message,
        ];
    }

    /**
     * Renderiza la página principal de caché
     *
     * @return void
     * @since 2.0.0
     */
    public static function render_cache(): void {
        // Verificar capacidades del usuario
        if (!current_user_can('manage_options')) {
            wp_die(__('No tienes permisos para acceder a esta página.', 'mi-integracion-api'));
        }

        // Procesar acciones de caché
        self::process_cache_actions();

        // Renderizar la interfaz
        self::render_cache_interface();
    }

    /**
     * Mide el tiempo de respuesta de un endpoint de la API
     *
     * @param string $endpoint El endpoint a medir
     * @param int    $sesionId El ID de sesión para la petición
     * @return array Resultado de la medición con latencia y detalles
     * @since 2.0.0
     */
    public static function measure_endpoint_latency(string $endpoint, int $sesionId = 18): array {
        $api_base_url = 'http://x.verial.org:8000/WcfServiceLibraryVerial/';
        
        // Construir URL con parámetros específicos según el endpoint
        $url = $api_base_url . $endpoint . '?x=' . $sesionId;
        
        // Añadir parámetros específicos según el endpoint
        switch ($endpoint) {
            case 'GetImagenesArticulosWS':
                $url .= '&id_articulo=0&numpixelsladomenor=0';
                break;
            case 'GetCondicionesTarifaWS':
                $url .= '&id_articulo=0&id_cliente=0&id_tarifa=0&fecha=' . date('Y-m-d');
                break;
        }
        
        $start_time = microtime(true);
        
        // Usar wp_remote_get para hacer la petición con más opciones
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
            'user-agent' => 'MiIntegracionApi/2.0',
            'headers' => [
                'Accept' => 'application/json',
                'Connection' => 'close'
            ],
            'redirection' => 5,
            'httpversion' => '1.1'
        ]);
        
        $end_time = microtime(true);
        $latency = $end_time - $start_time;
        
        $result = [
            'success' => false,
            'latency' => null,
            'error' => null,
            'response_code' => null,
            'url' => $url
        ];
        
        if (is_wp_error($response)) {
            $result['error'] = $response->get_error_message();
            return $result;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $result['response_code'] = $response_code;
        
        if ($response_code !== 200) {
            $result['error'] = "HTTP {$response_code}";
            return $result;
        }
        
        // Verificar que la respuesta contenga JSON válido
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            $result['error'] = "Respuesta vacía";
            return $result;
        }
        
        $json_data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $result['error'] = "JSON inválido: " . json_last_error_msg();
            return $result;
        }
        
        // Verificar que la respuesta tenga la estructura esperada
        if (!isset($json_data['InfoError']) || !isset($json_data['InfoError']['Codigo'])) {
            $result['error'] = "Estructura de respuesta inesperada";
            return $result;
        }
        
        $error_code = $json_data['InfoError']['Codigo'];
        if ($error_code !== 0) {
            $result['error'] = "Error de API: Código {$error_code}";
            return $result;
        }
        
        $result['success'] = true;
        $result['latency'] = $latency;
        
        return $result;
    }

    /**
     * Calcula TTL automático basado en la latencia medida
     *
     * @param float $latency Latencia en segundos
     * @param string $endpoint Tipo de endpoint
     * @return int TTL recomendado en segundos
     * @since 2.0.0
     */
    public static function calculate_auto_ttl(float $latency, string $endpoint): int {
        // TTLs base por tipo de endpoint (según importancia para sincronización de productos)
        $base_ttls = [
            'GetArticulosWS' => 3600,           // 1 hora - Datos principales de productos
            'GetImagenesArticulosWS' => 7200,   // 2 horas - Imágenes cambian poco
            'GetCondicionesTarifaWS' => 1800,  // 30 minutos - Precios cambian moderadamente
            'GetCategoriasWS' => 86400,        // 24 horas - Categorías cambian muy poco
            'GetFabricantesWS' => 86400,        // 24 horas - Fabricantes cambian muy poco
            'GetNumArticulosWS' => 21600       // 6 horas - Total de artículos (semi-estático)
        ];
        
        $base_ttl = $base_ttls[$endpoint] ?? 3600;
        
        // Factor de ajuste basado en latencia
        if ($latency < 0.5) {
            // Latencia muy baja: aumentar TTL
            $factor = 2.0;
        } elseif ($latency < 1.0) {
            // Latencia baja: TTL normal
            $factor = 1.0;
        } elseif ($latency < 2.0) {
            // Latencia media: reducir TTL ligeramente
            $factor = 0.8;
        } else {
            // Latencia alta: reducir TTL significativamente
            $factor = 0.5;
        }
        
        $calculated_ttl = (int) ($base_ttl * $factor);
        
        // Límites de seguridad
        $min_ttl = 60;   // Mínimo 1 minuto
        $max_ttl = 86400; // Máximo 24 horas
        
        return max($min_ttl, min($max_ttl, $calculated_ttl));
    }

    /**
     * Configura automáticamente los TTLs basándose en mediciones de latencia
     *
     * @return array Resultado de la configuración automática
     * @since 2.0.0
     */
    public static function auto_configure_ttls(): array {
        // Obtener sesión válida
        $valid_session = self::get_valid_session();
        if ($valid_session === null) {
            return [
                'results' => [],
                'success_count' => 0,
                'total_endpoints' => 0,
                'average_latency' => 0,
                'error' => 'No se pudo obtener una sesión válida'
            ];
        }
        
        $all_endpoints = [
            'GetArticulosWS' => 'Artículos',
            'GetImagenesArticulosWS' => 'Imágenes',
            'GetCondicionesTarifaWS' => 'Precios/Tarifas',
            'GetCategoriasWS' => 'Categorías',
            'GetFabricantesWS' => 'Fabricantes',
            'GetNumArticulosWS' => 'Total de artículos'
        ];
        
        // Filtrar solo endpoints disponibles
        $available_endpoints = self::filter_available_endpoints($all_endpoints, $valid_session);
        
        $results = [];
        $success_count = 0;
        $total_latency = 0;
        
        // Configurar solo endpoints disponibles
        foreach ($available_endpoints as $endpoint => $label) {
            $measurement = self::measure_endpoint_latency($endpoint, $valid_session);
            
            if ($measurement['success']) {
                $latency = $measurement['latency'];
                $recommended_ttl = self::calculate_auto_ttl($latency, $endpoint);
                
                // Actualizar configuración en la base de datos
                $cache_config = get_option('mi_integracion_api_cache_config', []);
                if (!isset($cache_config[$endpoint])) {
                    $cache_config[$endpoint] = [];
                }
                
                $cache_config[$endpoint]['ttl'] = $recommended_ttl;
                $cache_config[$endpoint]['enabled'] = 1;
                $cache_config[$endpoint]['auto_configured'] = true;
                $cache_config[$endpoint]['last_measurement'] = time();
                $cache_config[$endpoint]['measured_latency'] = $latency;
                $cache_config[$endpoint]['session_used'] = $valid_session;
                
                update_option('mi_integracion_api_cache_config', $cache_config);
                
                $results[$endpoint] = [
                    'success' => true,
                    'latency' => $latency,
                    'recommended_ttl' => $recommended_ttl,
                    'label' => $label
                ];
                
                $success_count++;
                $total_latency += $latency;
            }
        }
        
        // Añadir información sobre endpoints no disponibles
        $unavailable_endpoints = array_diff_key($all_endpoints, $available_endpoints);
        foreach ($unavailable_endpoints as $endpoint => $label) {
            $results[$endpoint] = [
                'success' => false,
                'error' => 'Endpoint no disponible',
                'label' => $label,
                'reason' => 'No responde correctamente a la API'
            ];
        }
        
        return [
            'results' => $results,
            'success_count' => $success_count,
            'total_endpoints' => count($all_endpoints),
            'available_endpoints' => count($available_endpoints),
            'unavailable_endpoints' => count($unavailable_endpoints),
            'average_latency' => $success_count > 0 ? $total_latency / $success_count : 0,
            'session_used' => $valid_session
        ];
    }

    /**
     * Procesa las acciones de caché enviadas por formulario
     *
     * @return void
     * @since 2.0.0
     */
    private static function process_cache_actions(): void {
        // Procesar acciones de caché (clear, toggle, clear_group)
        if (isset($_POST['mi_integracion_api_nonce']) &&
            wp_verify_nonce($_POST['mi_integracion_api_nonce'], 'mi_integracion_api_cache_actions')) {
            
            $action = sanitize_text_field($_POST['action'] ?? '');

            switch ($action) {
                case 'clear_cache':
                    self::clear_all_cache();
                    break;
                case 'toggle_cache':
                    self::toggle_cache();
                    break;
                case 'clear_group':
                    $group = sanitize_text_field($_POST['cache_group'] ?? '');
                    if (!empty($group)) {
                        self::clear_cache_group($group);
                    }
                    break;
                case 'auto_configure_ttls':
                    self::handle_auto_configure_ttls();
                    break;
                case 'diagnose_connectivity':
                    self::handle_diagnose_connectivity();
                    break;
                default:
                    // Acción no reconocida
                    break;
            }
        }

        // Procesar configuración de TTL por endpoint
        if (isset($_POST['_wpnonce']) &&
            wp_verify_nonce($_POST['_wpnonce'], 'mi_integracion_api_cache_options')) {
            
            self::process_ttl_configuration();
        }
    }

    /**
     * Maneja la configuración automática de TTLs
     *
     * @return void
     * @since 2.0.0
     */
    private static function handle_auto_configure_ttls(): void {
        try {
            $result = self::auto_configure_ttls();
            
            self::add_console_log('info', __('Iniciando configuración automática de TTLs…', 'mi-integracion-api'));

            if ($result['success_count'] > 0) {
                $message = sprintf(
                    __('Configuración automática completada. %d de %d endpoints configurados. Latencia promedio: %.2fs', 'mi-integracion-api'),
                    $result['success_count'],
                    $result['total_endpoints'],
                    $result['average_latency']
                );
                
                if (isset($result['session_used'])) {
                    $message .= sprintf(' (Sesión: %d)', $result['session_used']);
                }
                
                self::add_admin_notice($message, 'success');
                self::add_console_log('success', $message);
                
                if (isset($result['unavailable_endpoints']) && $result['unavailable_endpoints'] > 0) {
                    $unavailable_message = sprintf(
                        __('%d endpoints no están disponibles en la API actual', 'mi-integracion-api'),
                        $result['unavailable_endpoints']
                    );
                    self::add_admin_notice($unavailable_message, 'info');
                    self::add_console_log('warning', $unavailable_message);
                }
                
                // Mostrar detalles de cada endpoint
                foreach ($result['results'] as $endpoint => $endpoint_result) {
                    if ($endpoint_result['success']) {
                        $detail_message = sprintf(
                            __('%s: TTL configurado a %d segundos (latencia: %.2fs)', 'mi-integracion-api'),
                            $endpoint_result['label'],
                            $endpoint_result['recommended_ttl'],
                            $endpoint_result['latency']
                        );
                        self::add_admin_notice($detail_message, 'info');
                        self::add_console_log('info', $detail_message);
                    } else {
                        $error_details = $endpoint_result['error'];
                        if (isset($endpoint_result['response_code'])) {
                            $error_details .= sprintf(' (HTTP %d)', $endpoint_result['response_code']);
                        }
                        if (isset($endpoint_result['url'])) {
                            $error_details .= sprintf(' - URL: %s', $endpoint_result['url']);
                        }
                        
                        $error_message = sprintf(
                            __('%s: %s', 'mi-integracion-api'),
                            $endpoint_result['label'],
                            $error_details
                        );
                        self::add_admin_notice($error_message, 'warning');
                        self::add_console_log('warning', $error_message);
                    }
                }
            } else {
                $msg = __('No se pudo configurar ningún endpoint automáticamente. Verifica la conectividad con la API.', 'mi-integracion-api');
                self::add_admin_notice($msg, 'error');
                self::add_console_log('error', $msg);
            }
        } catch (Exception $e) {
            $msg = sprintf(__('Error en configuración automática: %s', 'mi-integracion-api'), $e->getMessage());
            self::add_admin_notice($msg, 'error');
            self::add_console_log('error', $msg);
        }
    }

    /**
     * Maneja la acción de diagnóstico de conectividad de la API
     * 
     * @return void
     * @since 2.0.0
     */
    private static function handle_diagnose_connectivity(): void {
        try {
            $diagnosis = self::diagnose_api_connectivity();

            // Resumen
            $summary = $diagnosis['server_reachable']
                ? __('Servidor accesible: OK', 'mi-integracion-api')
                : __('Servidor no accesible', 'mi-integracion-api');
            self::add_admin_notice($summary, $diagnosis['server_reachable'] ? 'success' : 'error');
            self::add_console_log($diagnosis['server_reachable'] ? 'success' : 'error', $summary);

            // Detalle por endpoint
            if (!empty($diagnosis['endpoints_status'])) {
                foreach ($diagnosis['endpoints_status'] as $endpoint => $status) {
                    if ($status['success']) {
                        self::add_admin_notice(
                            sprintf(
                                __('%s: OK (latencia: %.2fs, HTTP %s)', 'mi-integracion-api'),
                                $status['label'] ?? $endpoint,
                                isset($status['latency']) ? (float) $status['latency'] : 0,
                                isset($status['response_code']) ? (string) $status['response_code'] : '200'
                            ),
                            'info'
                        );
                        self::add_console_log('info', sprintf('%s: OK (latencia: %.2fs, HTTP %s)', $status['label'] ?? $endpoint, isset($status['latency']) ? (float) $status['latency'] : 0, isset($status['response_code']) ? (string) $status['response_code'] : '200'));
                    } else {
                        self::add_admin_notice(
                            sprintf(
                                __('%s: ERROR %s%s', 'mi-integracion-api'),
                                $status['label'] ?? $endpoint,
                                isset($status['response_code']) ? '(HTTP ' . $status['response_code'] . ') ' : '',
                                isset($status['error']) ? esc_html($status['error']) : ''
                            ),
                            'warning'
                        );
                        self::add_console_log('warning', sprintf('%s: ERROR %s%s', $status['label'] ?? $endpoint, isset($status['response_code']) ? '(HTTP ' . $status['response_code'] . ') ' : '', isset($status['error']) ? (string) $status['error'] : ''));
                    }
                }
            }

            // Recomendaciones
            if (!empty($diagnosis['recommendations'])) {
                foreach ($diagnosis['recommendations'] as $rec) {
                    self::add_admin_notice($rec, 'warning');
                    self::add_console_log('warning', $rec);
                }
            }
        } catch (\Exception $e) {
            $msg = sprintf(__('Error en el diagnóstico: %s', 'mi-integracion-api'), $e->getMessage());
            self::add_admin_notice($msg, 'error');
            self::add_console_log('error', $msg);
        }
    }

    /**
     * Limpia toda la caché
     *
     * @return void
     * @since 2.0.0
     */
    private static function clear_all_cache(): void {
        try {
            $cache_manager = \MiIntegracionApi\CacheManager::get_instance();
            $result = $cache_manager->clear_all_cache();
            
            if ($result) {
                self::add_admin_notice(
                    __('Caché limpiada correctamente.', 'mi-integracion-api'),
                    'success'
                );
            } else {
                self::add_admin_notice(
                    __('Error al limpiar la caché.', 'mi-integracion-api'),
                    'error'
                );
            }
        } catch (\Exception $e) {
            self::add_admin_notice(
                sprintf(__('Error al limpiar la caché: %s', 'mi-integracion-api'), $e->getMessage()),
                'error'
            );
        }
    }

    /**
     * Activa/desactiva la caché
     *
     * @return void
     * @since 2.0.0
     */
    private static function toggle_cache(): void {
        try {
            // Alternar estado usando CacheConfig
            $current_enabled = \MiIntegracionApi\Core\CacheConfig::is_enabled();
            $result = \MiIntegracionApi\Core\CacheConfig::set_enabled(!$current_enabled);
            
            if ($result) {
                $status = !$current_enabled ? 'activada' : 'desactivada';
                self::add_admin_notice(
                    sprintf(__('Caché %s correctamente.', 'mi-integracion-api'), $status),
                    'success'
                );
            } else {
                self::add_admin_notice(
                    __('Error al cambiar el estado de la caché.', 'mi-integracion-api'),
                    'error'
                );
            }
        } catch (\Exception $e) {
            self::add_admin_notice(
                sprintf(__('Error al cambiar el estado de la caché: %s', 'mi-integracion-api'), $e->getMessage()),
                'error'
            );
        }
    }

    /**
     * Limpia todos los elementos de un grupo específico de caché
     *
     * Este método elimina de forma segura todos los elementos almacenados en el grupo
     * de caché especificado. Es utilizado internamente por la interfaz de administración
     * para permitir la limpieza selectiva de caché.
     *
     * @param string $group Nombre del grupo de caché a limpiar. Debe ser un identificador
     *                     válido sin espacios ni caracteres especiales.
     * @return void No devuelve ningún valor.
     * @throws \Exception Si ocurre un error durante la limpieza del caché.
     * @since 2.0.0
     * @uses wp_cache_flush() Para limpiar la caché de WordPress.
     * @uses Logger::log() Para registrar errores que puedan ocurrir durante el proceso.
     * @example
     * // Limpiar la caché de productos
     * self::clear_cache_group('productos');
     *
     * @security Requiere permisos de administrador para ser ejecutado.
     * @todo Implementar limpieza selectiva por prefijo en lugar de limpiar todo el grupo.
     * @see CachePageView::render_cache_interface() Donde se utiliza este método.
     */
    private static function clear_cache_group(string $group): void {
        try {
            $cache_manager = \MiIntegracionApi\CacheManager::get_instance();
            
            // Intentar limpiar grupo en el gestor de caché HTTP (si aplica)
            $cleared_http = 0;
            if (class_exists('MiIntegracionApi\\Cache\\HTTP_Cache_Manager')) {
                $cleared_http = (int) \MiIntegracionApi\Cache\HTTP_Cache_Manager::flush_group($group);
            }
            
            // Respaldo: limpiar por patrón en el caché interno
            $cleared_internal = 0;
            if (method_exists($cache_manager, 'delete_by_pattern')) {
                $cleared_internal = (int) $cache_manager->delete_by_pattern($group . '*');
            }
            
            $total_cleared = $cleared_http + $cleared_internal;
            
            if ($total_cleared > 0) {
                self::add_admin_notice(
                    sprintf(__('Grupo de caché "%s" limpiado (%d entradas).', 'mi-integracion-api'), $group, $total_cleared),
                    'success'
                );
            } else {
                self::add_admin_notice(
                    sprintf(__('No se encontraron entradas para el grupo "%s".', 'mi-integracion-api'), $group),
                    'warning'
                );
            }
        } catch (\Exception $e) {
            self::add_admin_notice(
                sprintf(__('Error al limpiar el grupo de caché: %s', 'mi-integracion-api'), $e->getMessage()),
                'error'
                );
        }
    }

    /**
     * Procesa y guarda la configuración de TTL (Time To Live) para los endpoints de caché
     *
     * Este método maneja el formulario de configuración de caché, validando y guardando
     * los valores de TTL personalizados para cada endpoint. Los valores se almacenan
     * en las opciones de WordPress para su uso posterior por el sistema de caché.
     *
     * El método procesa los siguientes parámetros POST:
     * - cache_enabled: Array que indica qué endpoints tienen caché habilitada
     * - cache_ttl: Array con los valores de TTL en segundos para cada endpoint
     *
     * @return void Este método no devuelve ningún valor, pero puede mostrar notificaciones
     *             al usuario sobre el resultado de la operación.
     *
     * @since 2.0.0
     * @uses get_option() Para recuperar la configuración actual de caché
     * @uses update_option() Para guardar la nueva configuración de caché
     * @uses apply_filters() Para permitir la personalización del TTL por defecto
     * @uses self::add_admin_notice() Para mostrar mensajes de retroalimentación al usuario
     *
     * @example
     * ```php
     * // Ejemplo de datos POST que procesa este método
     * $_POST = [
     *     'cache_enabled' => [
     *         'GetArticulosWS' => '1',
     *         'GetClientesWS' => '1'
     *     ],
     *     'cache_ttl' => [
     *         'GetArticulosWS' => '3600',
     *         'GetClientesWS' => '1800'
     *     ]
     * ];
     * ```
     *
     * @todo Añadir validación más estricta de los valores de TTL
     * @todo Implementar sistema de respaldo antes de guardar cambios
     * @todo Añadir filtros para modificar los endpoints disponibles
     *
     * @see mi_integracion_api_default_cache_ttl Filtro para modificar el TTL por defecto
     * @see mi_integracion_api_cache_config Opción de WordPress que almacena la configuración
     */
    private static function process_ttl_configuration(): void {
        $cache_config = get_option('mi_integracion_api_cache_config', []);
        
        // Endpoints disponibles
        $endpoints = [
            'GetArticulosWS' => 'Artículos',
            'GetImagenesArticulosWS' => 'Imágenes',
            'GetCondicionesTarifaWS' => 'Precios/Tarifas',
            'GetCategoriasWS' => 'Categorías',
            'GetFabricantesWS' => 'Fabricantes',
            'GetNumArticulosWS' => 'Total de artículos'
        ];
        
        // Valores predeterminados
        $defaultTTL = apply_filters('mi_integracion_api_default_cache_ttl', 3600);
        
        $newConfig = [];
        
        foreach ($endpoints as $endpoint => $label) {
            $enabled = isset($_POST['cache_enabled'][$endpoint]) ? 1 : 0;
            $ttl = isset($_POST['cache_ttl'][$endpoint]) ? intval($_POST['cache_ttl'][$endpoint]) : $defaultTTL;
            
            $newConfig[$endpoint] = [
                'enabled' => $enabled,
                'ttl' => $ttl
            ];
        }
        
        $result = update_option('mi_integracion_api_cache_config', $newConfig);
        
        // ✅ NUEVO: Procesar configuración de rotación de caché de lotes
        if (isset($_POST['batch_cache_max_age_hours'])) {
            $max_age_hours = intval($_POST['batch_cache_max_age_hours']);
            // Validar rango (1-24 horas)
            if ($max_age_hours >= 1 && $max_age_hours <= 24) {
                update_option('mia_batch_cache_max_age_hours', $max_age_hours);
            }
        }

        // ✅ NUEVO: Procesar configuración de límite de tamaño global con LRU
        if (isset($_POST['cache_max_size_mb'])) {
            $max_size_mb = intval($_POST['cache_max_size_mb']);
            // Validar rango (50-5000 MB)
            if ($max_size_mb >= 50 && $max_size_mb <= 5000) {
                update_option('mia_cache_max_size_mb', $max_size_mb);
            }
        }

        // ✅ NUEVO: Procesar configuración de caché en dos niveles (Hot/Cold)
        if (isset($_POST['hot_cache_threshold'])) {
            $threshold = sanitize_text_field($_POST['hot_cache_threshold']);
            $validThresholds = ['very_high', 'high', 'medium', 'low'];
            if (in_array($threshold, $validThresholds)) {
                update_option('mia_hot_cache_threshold', $threshold);
            }
        }

        if (isset($_POST['enable_hot_cold_migration'])) {
            update_option('mia_enable_hot_cold_migration', true);
        } else {
            update_option('mia_enable_hot_cold_migration', false);
        }

        // ✅ NUEVO: Procesar configuración de flush segmentado
        if (isset($_POST['segment_flush_threshold'])) {
            $threshold = intval($_POST['segment_flush_threshold']);
            // Validar rango (100-10000)
            if ($threshold >= 100 && $threshold <= 10000) {
                update_option('mia_cache_segment_flush_threshold', $threshold);
            }
        }

        if (isset($_POST['segment_flush_size'])) {
            $size = intval($_POST['segment_flush_size']);
            // Validar rango (10-2000)
            if ($size >= 10 && $size <= 2000) {
                update_option('mia_cache_segment_flush_size', $size);
            }
        }

        if (isset($_POST['segment_flush_max_time'])) {
            $maxTime = intval($_POST['segment_flush_max_time']);
            // Validar rango (5-300 segundos)
            if ($maxTime >= 5 && $maxTime <= 300) {
                update_option('mia_cache_segment_flush_max_time', $maxTime);
            }
        }
        
        if ($result) {
            self::add_admin_notice(
                __('Configuración de TTL guardada correctamente.', 'mi-integracion-api'),
                'success'
            );
        } else {
            self::add_admin_notice(
                __('Error al guardar la configuración de TTL.', 'mi-integracion-api'),
                'error'
            );
        }
    }

    /**
     * Muestra una notificación administrativa estilizada en el panel de WordPress
     *
     * Este método genera y muestra un mensaje de notificación en el área de administración
     * de WordPress, con estilos y comportamiento consistentes con el diseño de WordPress.
     * Las notificaciones pueden ser de diferentes tipos (éxito, error, advertencia o informativas).
     *
     * @param string $message El mensaje de texto que se mostrará en la notificación.
     *                       Se escapa automáticamente para evitar problemas de seguridad.
     * @param string $type Tipo de notificación, que determina el estilo visual.
     *                    Valores aceptados:
     *                    - 'success': Notificación de éxito (verde)
     *                    - 'error': Notificación de error (rojo)
     *                    - 'warning': Notificación de advertencia (amarillo)
     *                    - 'info': Notificación informativa (azul, valor por defecto)
     * @return void Este método no devuelve ningún valor, genera salida HTML directamente.
     *
     * @since 2.0.0
     * @uses esc_attr() Para escapar atributos HTML
     * @uses esc_html() Para escapar el contenido del mensaje
     *
     * @example
     * ```php
     * // Mostrar notificación de éxito
     * self::add_admin_notice('Los cambios se han guardado correctamente', 'success');
     *
     * // Mostrar notificación de error
     * self::add_admin_notice('Error al guardar los datos', 'error');
     *
     * // Mostrar notificación informativa (tipo por defecto)
     * self::add_admin_notice('Este es un mensaje informativo');
     * ```
     *
     * @todo Añadir soporte para notificaciones persistentes usando transients
     * @todo Implementar soporte para notificaciones desechables con AJAX
     *
     * @see admin_notices Acción de WordPress donde se deben mostrar las notificaciones
     * @see https://developer.wordpress.org/reference/hooks/admin_notices/ Documentación de admin_notices
     */
    private static function add_admin_notice(string $message, string $type = 'info'): void {
        $class = 'notice notice-' . $type . ' is-dismissible';
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }

    /**
     * Renderiza la interfaz de administración de caché
     *
     * Este método genera la interfaz de usuario completa para la gestión de la caché,
     * incluyendo estadísticas, configuración de TTL por endpoint y controles de limpieza.
     * La interfaz sigue el diseño del panel de administración de WordPress.
     *
     * @return void Este método no devuelve ningún valor, genera salida HTML directamente.
     *
     * @since 2.0.0
     * @uses \MiIntegracionApi\CacheManager Para obtener estadísticas de caché
     * @uses \MiIntegracionApi\Core\CacheConfig Para verificar el estado de la caché
     * @uses get_option() Para recuperar la configuración de caché guardada
     * @uses admin_url() Para generar URLs del panel de administración
     * @uses apply_filters() Para permitir la personalización del TTL por defecto
     *
     * @example
     * ```php
     * // Llamada al método
     * CachePageView::render_cache_interface();
     * ```
     *
     * @todo Implementar vista responsiva para dispositivos móviles
     * @todo Añadir gráficos de uso de caché con una biblioteca como Chart.js
     * @todo Implementar búsqueda y filtrado de entradas en caché
     *
     * @see \MiIntegracionApi\CacheManager Para la gestión de la caché
     * @see \MiIntegracionApi\Core\CacheConfig Para la configuración de caché
     */
    private static function render_cache_interface(): void {
        // Obtener instancia del gestor de caché
        $cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        $cache_stats = $cache_manager->get_stats();
        $cache_enabled = \MiIntegracionApi\Core\CacheConfig::is_enabled();

        // Obtener configuración de TTL
        $cache_config = get_option('mi_integracion_api_cache_config', []);
        
        // Endpoints disponibles para configuración
        $endpoints = [
            'GetArticulosWS' => 'Artículos',
            'GetImagenesArticulosWS' => 'Imágenes',
            'GetCondicionesTarifaWS' => 'Precios/Tarifas',
            'GetCategoriasWS' => 'Categorías',
            'GetFabricantesWS' => 'Fabricantes',
            'GetNumArticulosWS' => 'Total de artículos'
        ];

        // Valores predeterminados
        $defaultTTL = apply_filters('mi_integracion_api_default_cache_ttl', 3600);

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
                        <li class="unified-nav-item">
                            <a href="<?php echo admin_url('admin.php?page=mi-integracion-api-endpoints'); ?>" class="unified-nav-link" data-page="endpoints">
                                <span class="nav-icon dashicons dashicons-networking"></span>
                                <span class="nav-text">Endpoints</span>
                            </a>
                        </li>
                        <li class="unified-nav-item active">
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
                                <span class="nav-icon dashicons dashicons-performance"></span>
                                <span class="nav-text">Memoria</span>
                            </a>
                        </li>
                    </ul>
                    
                    <!-- Sección de Acciones Rápidas -->
                    <div class="unified-actions-section">
                        <h3><?php esc_html_e('Acciones Rápidas', 'mi-integracion-api'); ?></h3>
                        <div class="unified-actions-grid">
                            <button class="unified-action-btn" data-action="clear-cache" title="<?php esc_attr_e('Vaciar toda la caché', 'mi-integracion-api'); ?>">
                                <i class="fas fa-trash"></i>
                                <span><?php esc_html_e('Vaciar Caché', 'mi-integracion-api'); ?></span>
                            </button>
                            <button class="unified-action-btn" data-action="toggle-cache" title="<?php esc_attr_e('Activar/Desactivar caché', 'mi-integracion-api'); ?>">
                                <i class="fas fa-<?php echo $cache_enabled ? 'pause' : 'play'; ?>"></i>
                                <span><?php echo $cache_enabled ? esc_html__('Desactivar', 'mi-integracion-api') : esc_html__('Activar', 'mi-integracion-api'); ?></span>
                            </button>
                            <button class="unified-action-btn" data-action="clear-group" title="<?php esc_attr_e('Limpiar por grupo', 'mi-integracion-api'); ?>">
                                <i class="fas fa-filter"></i>
                                <span><?php esc_html_e('Por Grupo', 'mi-integracion-api'); ?></span>
                            </button>
                            <button class="unified-action-btn" data-action="refresh-stats" title="<?php esc_attr_e('Actualizar estadísticas', 'mi-integracion-api'); ?>">
                                <i class="fas fa-chart-bar"></i>
                                <span><?php esc_html_e('Estadísticas', 'mi-integracion-api'); ?></span>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Sección de Configuración -->
                    <div class="unified-config-section">
                        <h3><?php esc_html_e('Configuración', 'mi-integracion-api'); ?></h3>
                        <div class="unified-config-grid">
                            <div class="unified-config-item">
                                <span class="config-label"><?php esc_html_e('Estado:', 'mi-integracion-api'); ?></span>
                                <span class="config-value status-<?php echo $cache_enabled ? 'enabled' : 'disabled'; ?>">
                                    <?php echo $cache_enabled ? esc_html__('Activado', 'mi-integracion-api') : esc_html__('Desactivado', 'mi-integracion-api'); ?>
                                </span>
                            </div>
                            <?php if ($cache_enabled && !empty($cache_stats)) : ?>
                            <div class="unified-config-item">
                                <span class="config-label"><?php esc_html_e('Elementos:', 'mi-integracion-api'); ?></span>
                                <span class="config-value"><?php echo esc_html(number_format_i18n($cache_stats['count'] ?? 0)); ?></span>
                            </div>
                            <div class="unified-config-item">
                                <span class="config-label"><?php esc_html_e('Tamaño:', 'mi-integracion-api'); ?></span>
                                <span class="config-value"><?php echo esc_html(isset($cache_stats['size_formatted']) ? $cache_stats['size_formatted'] : size_format($cache_stats['size_bytes'] ?? 0, 2)); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Búsqueda -->
                    <div class="unified-search-section">
                        <h3><?php esc_html_e('Búsqueda', 'mi-integracion-api'); ?></h3>
                        <div class="unified-search-form">
                            <input type="text" placeholder="<?php esc_attr_e('Buscar en caché...', 'mi-integracion-api'); ?>" class="unified-search-input">
                            <button type="button" class="unified-search-button">
                                <span class="dashicons dashicons-search"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Contenido principal -->
            <div class="mi-integracion-api-main-content">
                <!-- Banner principal -->
                <div class="mi-integracion-api-banner">
                    <div class="banner-content">
                        <div class="banner-icon">
                            <span class="dashicons dashicons-performance"></span>
                        </div>
                        <div class="banner-text">
                            <h1><?php echo esc_html__('Gestión de Caché', 'mi-integracion-api'); ?></h1>
                            <p><?php echo esc_html__('Administra el sistema de caché para optimizar el rendimiento', 'mi-integracion-api'); ?></p>
                        </div>
                        <div class="banner-visual">
                            <div class="visual-animation">
                                <div class="cache-icon">
                                    <span class="dashicons dashicons-database"></span>
                                </div>
                                <div class="performance-indicators">
                                    <div class="indicator speed"></div>
                                    <div class="indicator efficiency"></div>
                                    <div class="indicator optimization"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Icono de Ayuda -->
					<div class="cache-help">
						<a href="<?php echo esc_url(MiIntegracionApi_PLUGIN_URL . 'docs/manual-usuario/manual-cache.html'); ?>"
						target="_blank" 
						class="help-link"
						title="<?php esc_attr_e('Abrir Manual de Caché', 'mi-integracion-api'); ?>">
							<i class="fas fa-question-circle"></i>
							<span><?php esc_html_e('Ayuda', 'mi-integracion-api'); ?></span>
						</a>
					</div>
                </div>
                
                <!-- Estado del Caché -->
                <div class="mi-integracion-api-card">
                    <h2><?php esc_html_e('Estado del Caché', 'mi-integracion-api'); ?></h2>
                    
                    <div class="cache-status-grid">
                        <div class="cache-status-item">
                            <div class="status-icon">
                                <span class="dashicons dashicons-<?php echo $cache_enabled ? 'yes-alt' : 'dismiss'; ?>" 
                                      style="color: <?php echo $cache_enabled ? '#46b450' : '#dc3232'; ?>;">
                                </span>
                            </div>
                            <div class="status-content">
                                <h3><?php esc_html_e('Estado', 'mi-integracion-api'); ?></h3>
                                <p class="status-text">
                                    <?php 
                                    echo $cache_enabled 
                                        ? esc_html__('Activado', 'mi-integracion-api') 
                                        : esc_html__('Desactivado', 'mi-integracion-api'); 
                                    ?>
                                </p>
                            </div>
                        </div>
                    
                        <?php if ($cache_enabled && !empty($cache_stats)) : ?>
                            <div class="cache-status-item">
                                <div class="status-icon">
                                    <span class="dashicons dashicons-database"></span>
                                </div>
                                <div class="status-content">
                                    <h3><?php esc_html_e('Tamaño total', 'mi-integracion-api'); ?></h3>
                                    <p class="status-text"><?php echo esc_html(isset($cache_stats['size_formatted']) ? $cache_stats['size_formatted'] : size_format($cache_stats['size_bytes'] ?? 0, 2)); ?></p>
                                </div>
                            </div>
                            
                            <div class="cache-status-item">
                                <div class="status-icon">
                                    <span class="dashicons dashicons-list-view"></span>
                                </div>
                                <div class="status-content">
                                    <h3><?php esc_html_e('Número de entradas', 'mi-integracion-api'); ?></h3>
                                    <p class="status-text"><?php echo esc_html(number_format_i18n($cache_stats['count'] ?? 0)); ?></p>
                                </div>
                            </div>
                            
                            <div class="cache-status-item">
                                <div class="status-icon">
                                    <span class="dashicons dashicons-clock"></span>
                                </div>
                                <div class="status-content">
                                    <h3><?php esc_html_e('Tiempo de vida por defecto', 'mi-integracion-api'); ?></h3>
                                    <p class="status-text">
                                        <?php 
                                        $expiration = \MiIntegracionApi\Core\CacheConfig::get_default_ttl();
                                        echo $expiration > 0 
                                            ? esc_html(sprintf(
                                                _n('%d hora', '%d horas', $expiration / HOUR_IN_SECONDS, 'mi-integracion-api'),
                                                $expiration / HOUR_IN_SECONDS
                                            ))
                                            : esc_html__('No expira', 'mi-integracion-api');
                                        ?>
                                    </p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                
                    <!-- Acciones de Caché -->
                    <div class="cache-actions-section">
                        <h3><?php esc_html_e('Acciones Rápidas', 'mi-integracion-api'); ?></h3>
                        
                        <div class="cache-actions-grid">
                            <div class="cache-action-item">
                                <form method="post" action="" class="cache-action-form">
                                    <?php wp_nonce_field('mi_integracion_api_cache_actions', 'mi_integracion_api_nonce'); ?>
                                    <input type="hidden" name="action" value="clear_cache">
                                    <button type="submit" class="mi-integracion-api-button primary">
                                        <span class="dashicons dashicons-trash"></span>
                                        <?php esc_html_e('Vaciar caché', 'mi-integracion-api'); ?>
                                    </button>
                                    <p class="action-description"><?php esc_html_e('Elimina todas las entradas de la caché.', 'mi-integracion-api'); ?></p>
                                </form>
                            </div>
                            
                            <div class="cache-action-item">
                                <form method="post" action="" class="cache-action-form">
                                    <?php wp_nonce_field('mi_integracion_api_cache_actions', 'mi_integracion_api_nonce'); ?>
                                    <input type="hidden" name="action" value="toggle_cache">
                                    <button type="submit" class="mi-integracion-api-button secondary">
                                        <span class="dashicons dashicons-<?php echo $cache_enabled ? 'dismiss' : 'yes-alt'; ?>"></span>
                                        <?php 
                                        echo $cache_enabled 
                                            ? esc_html__('Desactivar caché', 'mi-integracion-api')
                                            : esc_html__('Activar caché', 'mi-integracion-api');
                                        ?>
                                    </button>
                                    <p class="action-description">
                                        <?php 
                                        echo $cache_enabled 
                                            ? esc_html__('Desactiva temporalmente el sistema de caché.', 'mi-integracion-api')
                                            : esc_html__('Activa el sistema de caché para mejorar el rendimiento.', 'mi-integracion-api');
                                        ?>
                                    </p>
                                </form>
                            </div>
                            
                            <div class="cache-action-item">
                                <form method="post" action="" class="cache-action-form">
                                    <?php wp_nonce_field('mi_integracion_api_cache_actions', 'mi_integracion_api_nonce'); ?>
                                    <input type="hidden" name="action" value="auto_configure_ttls">
                                    <button type="submit" class="mi-integracion-api-button auto-configure" 
                                            onclick="return confirm('<?php esc_attr_e('¿Estás seguro? Esto medirá la latencia de cada endpoint y configurará automáticamente los TTLs. El proceso puede tomar unos minutos.', 'mi-integracion-api'); ?>')">
                                        <span class="dashicons dashicons-performance"></span>
                                        <?php esc_html_e('Configuración Automática', 'mi-integracion-api'); ?>
                                    </button>
                                    <p class="action-description">
                                        <?php esc_html_e('Mide automáticamente la latencia de la API y configura los TTLs óptimos para cada endpoint.', 'mi-integracion-api'); ?>
                                    </p>
                                </form>
                            </div>
                            <div class="cache-action-item">
                                <form method="post" action="" class="cache-action-form">
                                    <?php wp_nonce_field('mi_integracion_api_cache_actions', 'mi_integracion_api_nonce'); ?>
                                    <input type="hidden" name="action" value="diagnose_connectivity">
                                    <button type="submit" class="mi-integracion-api-button secondary">
                                        <span class="dashicons dashicons-admin-network"></span>
                                        <?php esc_html_e('Diagnosticar Conectividad', 'mi-integracion-api'); ?>
                                    </button>
                                    <p class="action-description">
                                        <?php esc_html_e('Comprueba el estado de conexión y latencia de los endpoints clave.', 'mi-integracion-api'); ?>
                                    </p>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Consola de resultados -->
                    <div class="cache-console-section">
                        <h3><?php esc_html_e('Consola', 'mi-integracion-api'); ?></h3>
                        <div class="cache-console">
                            <?php if (!empty(self::$consoleLogs)) : ?>
                                <?php foreach (self::$consoleLogs as $log) : ?>
                                    <div class="console-line console-<?php echo esc_attr($log['type']); ?>">
                                        <span class="console-time">[<?php echo esc_html($log['time']); ?>]</span>
                                        <span class="console-type">(<?php echo esc_html(strtoupper($log['type'])); ?>)</span>
                                        <span class="console-message"><?php echo esc_html($log['message']); ?></span>
                                    </div>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <div class="console-line console-info">
                                    <span class="console-time">[--:--:--]</span>
                                    <span class="console-type">(INFO)</span>
                                    <span class="console-message"><?php esc_html_e('La consola mostrará aquí los resultados de diagnóstico y configuración automática.', 'mi-integracion-api'); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Limpieza por Grupo -->
                    <div class="cache-group-section">
                        <h3><?php esc_html_e('Limpiar por Grupo', 'mi-integracion-api'); ?></h3>
                        <form method="post" action="" class="cache-group-form">
                            <?php wp_nonce_field('mi_integracion_api_cache_actions', 'mi_integracion_api_nonce'); ?>
                            <input type="hidden" name="action" value="clear_group">
                            <div class="form-group">
                                <select name="cache_group" required class="mi-integracion-api-select">
                                    <option value=""><?php esc_html_e('Seleccionar grupo...', 'mi-integracion-api'); ?></option>
                                    <option value="products"><?php esc_html_e('Productos', 'mi-integracion-api'); ?></option>
                                    <option value="images"><?php esc_html_e('Imágenes', 'mi-integracion-api'); ?></option>
                                    <option value="prices"><?php esc_html_e('Precios', 'mi-integracion-api'); ?></option>
                                    <option value="categories"><?php esc_html_e('Categorías', 'mi-integracion-api'); ?></option>
                                    <option value="manufacturers"><?php esc_html_e('Fabricantes', 'mi-integracion-api'); ?></option>
                                </select>
                                <button type="submit" class="mi-integracion-api-button secondary">
                                    <span class="dashicons dashicons-trash"></span>
                                    <?php esc_html_e('Limpiar Grupo', 'mi-integracion-api'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Configuración de TTL por Endpoint -->
                <div class="mi-integracion-api-card">
                    <h2><?php esc_html_e('Configuración de TTL por Endpoint', 'mi-integracion-api'); ?></h2>
                
                    <p><?php esc_html_e('Configure los tiempos de caché para cada endpoint del API. Un valor de 0 desactiva la caché para ese endpoint.', 'mi-integracion-api'); ?></p>
                    
                    <form method="post" action="" class="ttl-config-form">
                        <?php wp_nonce_field('mi_integracion_api_cache_options'); ?>
                        
                        <div class="ttl-config-grid">
                            <?php foreach ($endpoints as $endpoint => $label): ?>
                                <?php 
                                $enabled = isset($cache_config[$endpoint]['enabled']) ? $cache_config[$endpoint]['enabled'] : 1;
                                $ttl = isset($cache_config[$endpoint]['ttl']) ? $cache_config[$endpoint]['ttl'] : $defaultTTL;
                                ?>
                                <div class="ttl-config-item">
                                    <div class="endpoint-info">
                                        <h4><?php echo esc_html($label); ?></h4>
                                        <p class="endpoint-description">
                                            <?php 
                                            switch($endpoint) {
                                                case 'GetArticulosWS':
                                                    echo esc_html__('Datos de artículos (recomendado: 3600)', 'mi-integracion-api');
                                                    break;
                                                case 'GetImagenesArticulosWS':
                                                    echo esc_html__('Imágenes de productos (recomendado: 7200)', 'mi-integracion-api');
                                                    break;
                                                case 'GetCondicionesTarifaWS':
                                                    echo esc_html__('Precios y tarifas (recomendado: 1800)', 'mi-integracion-api');
                                                    break;
                                                case 'GetCategoriasWS':
                                                    echo esc_html__('Categorías de productos (recomendado: 86400)', 'mi-integracion-api');
                                                    break;
                                                case 'GetFabricantesWS':
                                                    echo esc_html__('Fabricantes y editores (recomendado: 86400)', 'mi-integracion-api');
                                                    break;
                                                case 'GetNumArticulosWS':
                                                    echo esc_html__('Número total de artículos (recomendado: 21600)', 'mi-integracion-api');
                                                    break;
                                            }
                                            ?>
                                        </p>
                                    </div>
                                    <div class="endpoint-controls">
                                        <div class="control-group">
                                            <label class="checkbox-label">
                                                <input 
                                                    type="checkbox" 
                                                    name="cache_enabled[<?php echo esc_attr($endpoint); ?>]" 
                                                    value="1" 
                                                    <?php checked($enabled); ?>
                                                    class="mi-checkbox"
                                                >
                                                <span class="checkmark"></span>
                                                <?php esc_html_e('Habilitado', 'mi-integracion-api'); ?>
                                            </label>
                                        </div>
                                        <div class="control-group">
                                            <label for="ttl_<?php echo esc_attr($endpoint); ?>" class="ttl-label">
                                                <?php esc_html_e('TTL (segundos)', 'mi-integracion-api'); ?>
                                            </label>
                                            <input 
                                                type="number" 
                                                id="ttl_<?php echo esc_attr($endpoint); ?>"
                                                name="cache_ttl[<?php echo esc_attr($endpoint); ?>]" 
                                                value="<?php echo esc_attr($ttl); ?>" 
                                                class="mi-integracion-api-input"
                                                min="0"
                                                step="1"
                                            >
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    
                        <div class="form-actions">
                            <button type="submit" class="mi-integracion-api-button primary">
                                <span class="dashicons dashicons-saved"></span>
                                <?php esc_html_e('Guardar cambios', 'mi-integracion-api'); ?>
                            </button>
                            <button type="button" class="mi-integracion-api-button secondary" onclick="resetToDefaults()">
                                <span class="dashicons dashicons-undo"></span>
                                <?php esc_html_e('Restablecer valores predeterminados', 'mi-integracion-api'); ?>
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Configuración de Rotación de Caché de Lotes -->
                <div class="mi-integracion-api-card">
                    <h2><?php esc_html_e('Rotación de Caché de Lotes', 'mi-integracion-api'); ?></h2>
                    
                    <p><?php esc_html_e('Configura la rotación automática de caché para lotes de sincronización. Los lotes más antiguos que el umbral configurado se eliminarán automáticamente durante la limpieza de caché.', 'mi-integracion-api'); ?></p>
                    
                    <form method="post" action="" class="batch-cache-rotation-form">
                        <?php wp_nonce_field('mi_integracion_api_cache_options'); ?>
                        
                        <?php
                        // Obtener valor actual o usar default (3 horas)
                        $current_max_age = get_option('mia_batch_cache_max_age_hours', 3);
                        ?>
                        
                        <div class="batch-cache-config-section">
                            <div class="control-group">
                                <label for="batch_cache_max_age_hours" class="ttl-label">
                                    <?php esc_html_e('Edad máxima de lotes (horas)', 'mi-integracion-api'); ?>
                                </label>
                                <div class="input-with-suffix">
                                    <input 
                                        type="number" 
                                        id="batch_cache_max_age_hours" 
                                        name="batch_cache_max_age_hours" 
                                        value="<?php echo esc_attr($current_max_age); ?>" 
                                        min="1" 
                                        max="24" 
                                        step="1"
                                        class="mi-integracion-api-input"
                                        style="width: 100px;"
                                        required
                                    />
                                    <span class="input-suffix"><?php esc_html_e('horas', 'mi-integracion-api'); ?></span>
                                </div>
                                <p class="endpoint-description" style="margin-top: 8px;">
                                    <?php 
                                    printf(
                                        esc_html__('Los lotes de caché más antiguos que %d horas se eliminarán automáticamente. Rango permitido: 1-24 horas.', 'mi-integracion-api'),
                                        $current_max_age
                                    );
                                    ?>
                                </p>
                                <div class="info-box">
                                    <span class="dashicons dashicons-info"></span>
                                    <p>
                                        <?php esc_html_e('Esta configuración es especialmente útil para sincronizaciones largas que cruzan múltiples horas. Con ~8000 productos, una sincronización puede tardar 3-4 horas, generando lotes en diferentes ventanas de tiempo.', 'mi-integracion-api'); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="submit" class="mi-integracion-api-button primary">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e('Guardar configuración', 'mi-integracion-api'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Configuración de Límite de Tamaño Global con LRU -->
                <div class="mi-integracion-api-card">
                    <h2><?php esc_html_e('Límite de Tamaño Global con LRU', 'mi-integracion-api'); ?></h2>
                    
                    <p><?php esc_html_e('Configura el límite máximo de tamaño para toda la caché del sistema. Cuando se alcance este límite, se aplicará evicción automática LRU (Least Recently Used) para mantener los datos más utilizados.', 'mi-integracion-api'); ?></p>
                    
                    <form method="post" action="" class="lru-cache-limit-form">
                        <?php wp_nonce_field('mi_integracion_api_cache_options'); ?>
                        
                        <?php
                        // Obtener valor actual o usar default (500MB)
                        $current_max_size = get_option('mia_cache_max_size_mb', 500);
                        $cacheManager = \MiIntegracionApi\CacheManager::get_instance();
                        $current_total_size = $cacheManager->getTotalCacheSize();
                        $current_limit = $cacheManager->getGlobalCacheSizeLimit();
                        $usage_percentage = $current_limit > 0 ? round(($current_total_size / $current_limit) * 100, 1) : 0;
                        ?>
                        
                        <div class="lru-cache-config-section">
                            <div class="control-group">
                                <label for="cache_max_size_mb" class="ttl-label">
                                    <?php esc_html_e('Límite máximo de caché (MB)', 'mi-integracion-api'); ?>
                                </label>
                                <div class="input-with-suffix">
                                    <input 
                                        type="number" 
                                        id="cache_max_size_mb" 
                                        name="cache_max_size_mb" 
                                        value="<?php echo esc_attr($current_max_size); ?>" 
                                        min="50" 
                                        max="5000" 
                                        step="50"
                                        class="mi-integracion-api-input"
                                        style="width: 120px;"
                                        required
                                    />
                                    <span class="input-suffix"><?php esc_html_e('MB', 'mi-integracion-api'); ?></span>
                                </div>
                                <p class="endpoint-description" style="margin-top: 8px;">
                                    <?php 
                                    printf(
                                        esc_html__('El límite actual es de %d MB. Tamaño actual de caché: %.2f MB (%.1f%% del límite). Rango permitido: 50-5000 MB.', 'mi-integracion-api'),
                                        $current_limit,
                                        $current_total_size,
                                        $usage_percentage
                                    );
                                    ?>
                                </p>
                                
                                <!-- Indicador visual de uso -->
                                <div style="margin-top: 12px;">
                                    <div style="display: flex; justify-content: space-between; margin-bottom: 4px;">
                                        <span style="color: rgba(255, 255, 255, 0.8); font-size: 12px;"><?php esc_html_e('Uso actual', 'mi-integracion-api'); ?></span>
                                        <span style="color: rgba(255, 255, 255, 0.8); font-size: 12px;"><?php echo esc_html($usage_percentage); ?>%</span>
                                    </div>
                                    <div style="background: rgba(0, 0, 0, 0.2); border-radius: 4px; height: 8px; overflow: hidden;">
                                        <div style="background: <?php echo $usage_percentage > 90 ? '#e74c3c' : ($usage_percentage > 70 ? '#f39c12' : '#667eea'); ?>; height: 100%; width: <?php echo esc_attr(min(100, $usage_percentage)); ?>%; transition: width 0.3s ease;"></div>
                                    </div>
                                </div>
                                
                                <div class="info-box">
                                    <span class="dashicons dashicons-info"></span>
                                    <p>
                                        <?php esc_html_e('La evicción LRU se activa automáticamente cuando el tamaño total de caché alcanza el límite configurado. Los elementos menos usados se eliminarán primero, manteniendo los datos más accedidos. Esto es especialmente útil para sistemas con ~8000 productos donde la caché puede crecer significativamente durante sincronizaciones largas.', 'mi-integracion-api'); ?>
                                    </p>
                                </div>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="submit" class="mi-integracion-api-button primary">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e('Guardar configuración', 'mi-integracion-api'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Configuración de Caché en Dos Niveles (Hot/Cold) -->
                <div class="mi-integracion-api-card">
                    <h2><?php esc_html_e('Caché en Dos Niveles (Hot/Cold)', 'mi-integracion-api'); ?></h2>
                    
                    <p><?php esc_html_e('El sistema utiliza caché en dos niveles para optimizar memoria y rendimiento. Los datos frecuentemente accedidos (hot) se almacenan en memoria rápida, mientras que los datos raramente accedidos (cold) se almacenan comprimidos en disco.', 'mi-integracion-api'); ?></p>
                    
                    <form method="post" action="" class="hot-cold-cache-form">
                        <?php wp_nonce_field('mi_integracion_api_cache_options'); ?>
                        
                        <?php
                        // Obtener configuración actual
                        $hotCacheThreshold = get_option('mia_hot_cache_threshold', 'medium');
                        $autoMigrationEnabled = get_option('mia_enable_hot_cold_migration', true);
                        $cacheManager = \MiIntegracionApi\CacheManager::get_instance();
                        $twoTierStats = $cacheManager->getTwoTierCacheStats();
                        ?>
                        
                        <div class="hot-cold-cache-config-section">
                            <div class="control-group">
                                <label for="hot_cache_threshold" class="ttl-label">
                                    <?php esc_html_e('Umbral para Hot Cache', 'mi-integracion-api'); ?>
                                </label>
                                <select 
                                    id="hot_cache_threshold" 
                                    name="hot_cache_threshold" 
                                    class="mi-integracion-api-select"
                                    style="width: 200px;"
                                >
                                    <option value="very_high" <?php selected($hotCacheThreshold, 'very_high'); ?>>
                                        <?php esc_html_e('Muy Alta (solo datos muy frecuentes)', 'mi-integracion-api'); ?>
                                    </option>
                                    <option value="high" <?php selected($hotCacheThreshold, 'high'); ?>>
                                        <?php esc_html_e('Alta (datos frecuentes)', 'mi-integracion-api'); ?>
                                    </option>
                                    <option value="medium" <?php selected($hotCacheThreshold, 'medium'); ?>>
                                        <?php esc_html_e('Media (recomendado)', 'mi-integracion-api'); ?>
                                    </option>
                                    <option value="low" <?php selected($hotCacheThreshold, 'low'); ?>>
                                        <?php esc_html_e('Baja (más datos en hot cache)', 'mi-integracion-api'); ?>
                                    </option>
                                </select>
                                <p class="endpoint-description" style="margin-top: 8px;">
                                    <?php esc_html_e('Los datos con frecuencia igual o superior al umbral se almacenan en hot cache (memoria rápida). Los datos con menor frecuencia se almacenan en cold cache (disco comprimido).', 'mi-integracion-api'); ?>
                                </p>
                            </div>
                            
                            <div class="control-group" style="margin-top: 20px;">
                                <label class="checkbox-label">
                                    <input 
                                        type="checkbox" 
                                        id="enable_hot_cold_migration" 
                                        name="enable_hot_cold_migration" 
                                        value="1"
                                        <?php checked($autoMigrationEnabled); ?>
                                        class="mi-checkbox"
                                    >
                                    <span class="checkmark"></span>
                                    <?php esc_html_e('Habilitar migración automática Hot→Cold', 'mi-integracion-api'); ?>
                                </label>
                                <p class="endpoint-description" style="margin-top: 8px;">
                                    <?php esc_html_e('Cuando está habilitada, los datos que bajan su frecuencia de acceso se migran automáticamente de hot cache a cold cache durante la limpieza programada.', 'mi-integracion-api'); ?>
                                </p>
                            </div>
                            
                            <!-- Estadísticas de Hot/Cold Cache -->
                            <div style="margin-top: 24px; padding: 16px; background: rgba(102, 126, 234, 0.1); border-radius: 8px; border-left: 3px solid #667eea;">
                                <h4 style="margin: 0 0 12px 0; color: white; font-size: 14px; font-weight: 600;">
                                    <?php esc_html_e('Estadísticas Actuales', 'mi-integracion-api'); ?>
                                </h4>
                                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px;">
                                    <div>
                                        <div style="color: rgba(255, 255, 255, 0.8); font-size: 12px; margin-bottom: 4px;">
                                            <?php esc_html_e('Hot Cache', 'mi-integracion-api'); ?>
                                        </div>
                                        <div style="color: white; font-size: 18px; font-weight: 600;">
                                            <?php echo esc_html($twoTierStats['hot_cache']['count']); ?> <?php esc_html_e('elementos', 'mi-integracion-api'); ?>
                                        </div>
                                        <div style="color: rgba(255, 255, 255, 0.7); font-size: 11px;">
                                            <?php echo esc_html($twoTierStats['hot_cache']['size_mb']); ?> MB
                                        </div>
                                    </div>
                                    <div>
                                        <div style="color: rgba(255, 255, 255, 0.8); font-size: 12px; margin-bottom: 4px;">
                                            <?php esc_html_e('Cold Cache', 'mi-integracion-api'); ?>
                                        </div>
                                        <div style="color: white; font-size: 18px; font-weight: 600;">
                                            <?php echo esc_html($twoTierStats['cold_cache']['count']); ?> <?php esc_html_e('elementos', 'mi-integracion-api'); ?>
                                        </div>
                                        <div style="color: rgba(255, 255, 255, 0.7); font-size: 11px;">
                                            <?php echo esc_html($twoTierStats['cold_cache']['size_mb']); ?> MB
                                        </div>
                                    </div>
                                    <div>
                                        <div style="color: rgba(255, 255, 255, 0.8); font-size: 12px; margin-bottom: 4px;">
                                            <?php esc_html_e('Total', 'mi-integracion-api'); ?>
                                        </div>
                                        <div style="color: white; font-size: 18px; font-weight: 600;">
                                            <?php echo esc_html($twoTierStats['total']['count']); ?> <?php esc_html_e('elementos', 'mi-integracion-api'); ?>
                                        </div>
                                        <div style="color: rgba(255, 255, 255, 0.7); font-size: 11px;">
                                            <?php echo esc_html($twoTierStats['total']['size_mb']); ?> MB
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="info-box">
                                <span class="dashicons dashicons-info"></span>
                                <p>
                                    <?php esc_html_e('El sistema migra automáticamente datos entre hot y cold cache según su frecuencia de acceso. Los datos accedidos recientemente se mantienen en hot cache para acceso rápido, mientras que los datos antiguos o raramente accedidos se almacenan comprimidos en disco para ahorrar memoria. Esto es especialmente útil para sistemas con ~8000 productos donde la mayoría de datos se acceden raramente.', 'mi-integracion-api'); ?>
                                </p>
                            </div>
                            
                            <div class="form-actions">
                                <button type="submit" name="submit" class="mi-integracion-api-button primary">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e('Guardar configuración', 'mi-integracion-api'); ?>
                                </button>
                                <button type="button" class="mi-integracion-api-button secondary" onclick="performHotToColdMigration()">
                                    <span class="dashicons dashicons-update"></span>
                                    <?php esc_html_e('Ejecutar migración Hot→Cold ahora', 'mi-integracion-api'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Configuración de Flush Inteligente por Segmentos -->
                <div class="mi-integracion-api-card">
                    <h2><?php esc_html_e('Flush Inteligente por Segmentos', 'mi-integracion-api'); ?></h2>
                    
                    <p><?php esc_html_e('Configura el sistema de limpieza de caché por segmentos para evitar bloqueos y timeouts cuando hay grandes cantidades de transients. El sistema automáticamente usará flush segmentado cuando el número de transients supere el umbral configurado.', 'mi-integracion-api'); ?></p>
                    
                    <form method="post" action="" class="segment-flush-form">
                        <?php wp_nonce_field('mi_integracion_api_cache_options'); ?>
                        
                        <?php
                        // Obtener valores actuales o usar defaults
                        $currentThreshold = get_option('mia_cache_segment_flush_threshold', 1000);
                        $currentSegmentSize = get_option('mia_cache_segment_flush_size', 500);
                        $currentMaxTime = get_option('mia_cache_segment_flush_max_time', 30);
                        ?>
                        
                        <div class="segment-flush-config-section">
                            <div class="control-group">
                                <label for="segment_flush_threshold" class="ttl-label">
                                    <?php esc_html_e('Umbral para activar flush segmentado', 'mi-integracion-api'); ?>
                                </label>
                                <div class="input-with-suffix">
                                    <input 
                                        type="number" 
                                        id="segment_flush_threshold" 
                                        name="segment_flush_threshold" 
                                        value="<?php echo esc_attr($currentThreshold); ?>" 
                                        min="100" 
                                        max="10000" 
                                        step="100"
                                        class="mi-integracion-api-input"
                                        style="width: 120px;"
                                        required
                                    />
                                    <span class="input-suffix"><?php esc_html_e('transients', 'mi-integracion-api'); ?></span>
                                </div>
                                <p class="endpoint-description" style="margin-top: 8px;">
                                    <?php 
                                    printf(
                                        esc_html__('Si hay más de %d transients, se usará flush segmentado automáticamente. Rango permitido: 100-10000.', 'mi-integracion-api'),
                                        $currentThreshold
                                    );
                                    ?>
                                </p>
                            </div>
                            
                            <div class="control-group" style="margin-top: 20px;">
                                <label for="segment_flush_size" class="ttl-label">
                                    <?php esc_html_e('Tamaño de cada segmento', 'mi-integracion-api'); ?>
                                </label>
                                <div class="input-with-suffix">
                                    <input 
                                        type="number" 
                                        id="segment_flush_size" 
                                        name="segment_flush_size" 
                                        value="<?php echo esc_attr($currentSegmentSize); ?>" 
                                        min="10" 
                                        max="2000" 
                                        step="10"
                                        class="mi-integracion-api-input"
                                        style="width: 120px;"
                                        required
                                    />
                                    <span class="input-suffix"><?php esc_html_e('transients/segmento', 'mi-integracion-api'); ?></span>
                                </div>
                                <p class="endpoint-description" style="margin-top: 8px;">
                                    <?php esc_html_e('Número de transients a procesar en cada segmento. Valores más pequeños = menos bloqueo pero más overhead. Rango permitido: 10-2000.', 'mi-integracion-api'); ?>
                                </p>
                            </div>
                            
                            <div class="control-group" style="margin-top: 20px;">
                                <label for="segment_flush_max_time" class="ttl-label">
                                    <?php esc_html_e('Tiempo máximo de ejecución', 'mi-integracion-api'); ?>
                                </label>
                                <div class="input-with-suffix">
                                    <input 
                                        type="number" 
                                        id="segment_flush_max_time" 
                                        name="segment_flush_max_time" 
                                        value="<?php echo esc_attr($currentMaxTime); ?>" 
                                        min="5" 
                                        max="300" 
                                        step="5"
                                        class="mi-integracion-api-input"
                                        style="width: 120px;"
                                        required
                                    />
                                    <span class="input-suffix"><?php esc_html_e('segundos', 'mi-integracion-api'); ?></span>
                                </div>
                                <p class="endpoint-description" style="margin-top: 8px;">
                                    <?php esc_html_e('Tiempo máximo permitido para procesar segmentos. Si se alcanza este límite, la limpieza se detendrá para evitar timeouts. Rango permitido: 5-300 segundos.', 'mi-integracion-api'); ?>
                                </p>
                            </div>
                            
                            <div class="info-box" style="margin-top: 20px;">
                                <span class="dashicons dashicons-info"></span>
                                <p>
                                    <?php esc_html_e('Con ~8000 productos, el sistema puede generar miles de transients. El flush segmentado divide la limpieza en lotes más pequeños, evitando bloqueos y mejorando la experiencia del usuario.', 'mi-integracion-api'); ?>
                                </p>
                            </div>
                            
                            <div class="form-actions" style="margin-top: 24px;">
                                <button type="submit" name="submit" class="mi-integracion-api-button primary">
                                    <span class="dashicons dashicons-saved"></span>
                                    <?php esc_html_e('Guardar configuración', 'mi-integracion-api'); ?>
                                </button>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Estadísticas de Caché -->
                <div class="mi-integracion-api-card">
                    <h2><?php esc_html_e('Estadísticas de Caché', 'mi-integracion-api'); ?></h2>
                    
                    <?php
                    // Estadísticas básicas de transients
                    global $wpdb;
                    $total_transients = $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->options WHERE option_name LIKE '%mi_integracion_api_cache%'");
                    ?>
                    
                    <div class="cache-stats-grid">
                        <div class="cache-stat-item">
                            <div class="stat-icon">
                                <span class="dashicons dashicons-database"></span>
                            </div>
                            <div class="stat-content">
                                <h3><?php esc_html_e('Total de elementos en caché', 'mi-integracion-api'); ?></h3>
                                <p class="stat-value"><?php echo intval($total_transients); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script type="text/javascript">
        var ajaxurl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        
        /**
         * ✅ NUEVO: Ejecuta migración Hot→Cold manualmente
         */
        function performHotToColdMigration() {
            if (!confirm('<?php esc_js(__('¿Desea ejecutar la migración Hot→Cold ahora? Esto moverá datos de baja frecuencia de hot cache a cold cache.', 'mi-integracion-api')); ?>')) {
                return;
            }
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mia_perform_hot_cold_migration',
                    nonce: '<?php echo wp_create_nonce('mia_hot_cold_migration'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('<?php esc_js(__('Migración completada. Elementos migrados: ', 'mi-integracion-api')); ?>' + response.data.migrated_count);
                        location.reload();
                    } else {
                        alert('<?php esc_js(__('Error durante la migración: ', 'mi-integracion-api')); ?>' + (response.data.message || 'Error desconocido'));
                    }
                },
                error: function() {
                    alert('<?php esc_js(__('Error de red durante la migración.', 'mi-integracion-api')); ?>');
                }
            });
        }
        
        function resetToDefaults() {
            if (confirm('<?php esc_js(__('¿Está seguro de que desea restablecer todos los valores a los predeterminados?', 'mi-integracion-api')); ?>')) {
                const defaultValues = {
                    'GetArticulosWS': 3600,
                    'GetImagenesArticulosWS': 7200,
                    'GetCondicionesTarifaWS': 1800,
                    'GetCategoriasWS': 86400,
                    'GetFabricantesWS': 86400,
                    'GetNumArticulosWS': 21600
                };
                
                // Establecer valores predeterminados en el formulario
                for (const endpoint in defaultValues) {
                    const ttlInput = document.querySelector(`input[name="cache_ttl[${endpoint}]"]`);
                    const enabledInput = document.querySelector(`input[name="cache_enabled[${endpoint}]"]`);
                    
                    if (ttlInput) {
                        ttlInput.value = defaultValues[endpoint];
                    }
                    
                    if (enabledInput) {
                        enabledInput.checked = true;
                    }
                }
            }
        }
        </script>
        
        <?php
        // Enqueue assets necesarios
        wp_enqueue_style('dashicons');
        
        // Cargar Font Awesome primero para los iconos
        wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css', array(), '6.0.0');
        
        $version = '1.0.0';
        
        // Cargar design-system primero (variables CSS)
        wp_enqueue_style(
            'mi-integracion-api-design-system',
            plugin_dir_url(__FILE__) . '../../assets/css/design-system.css',
            array(),
            $version
        );
        
        // Cargar CSS del dashboard
        wp_enqueue_style(
            'mi-integracion-api-dashboard',
            plugin_dir_url(__FILE__) . '../../assets/css/dashboard.css',
            array('mi-integracion-api-design-system'),
            $version
        );
        
        // Cargar CSS del sidebar unificado
        wp_enqueue_style(
            'mi-integracion-api-unified-sidebar',
            plugin_dir_url(__FILE__) . '../../assets/css/unified-sidebar.css',
            array('mi-integracion-api-dashboard'),
            $version
        );
        
        // Cargar CSS específico de caché (con dependencia de font-awesome para iconos)
        wp_enqueue_style(
            'mi-integracion-api-cache-admin',
            plugin_dir_url(__FILE__) . '../../assets/css/cache-admin.css',
            array('mi-integracion-api-unified-sidebar', 'font-awesome'),
            $version
        );
        
        wp_enqueue_script('mi-integracion-api-unified-sidebar', plugin_dir_url(__FILE__) . '../../assets/js/unified-sidebar.js', array('jquery'), $version, true);
        wp_enqueue_script('mi-integracion-api-cache-admin', plugin_dir_url(__FILE__) . '../../assets/js/cache-admin.js', array('jquery'), $version, true);
        
        // Localizar script para AJAX
        wp_localize_script('mi-integracion-api-cache-admin', 'miIntegracionApiCache', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('mi_cache_nonce'),
            'loading' => __('Procesando...', 'mi-integracion-api'),
            'error' => __('Error al procesar la solicitud', 'mi-integracion-api'),
            'success' => __('Operación completada exitosamente', 'mi-integracion-api'),
            'confirmClear' => __('¿Está seguro de que desea limpiar toda la caché?', 'mi-integracion-api'),
            'confirmToggle' => __('¿Está seguro de que desea cambiar el estado de la caché?', 'mi-integracion-api')
        ));
    }

    /**
     * Obtiene una nueva sesión válida de la API de Verial
     *
     * @return int|null ID de sesión válido o null si falla
     * @since 2.0.0
     */
    public static function get_valid_session(): ?int {
        // Intentar con diferentes IDs de sesión comunes
        $session_ids = [18, 1, 2, 3, 4, 5];
        
        foreach ($session_ids as $session_id) {
            $test_url = 'http://x.verial.org:8000/WcfServiceLibraryVerial/GetPaisesWS?x=' . $session_id;
            
            $response = wp_remote_get($test_url, [
                'timeout' => 10,
                'sslverify' => false,
                'user-agent' => 'MiIntegracionApi/2.0'
            ]);
            
            if (is_wp_error($response)) {
                continue;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                continue;
            }
            
            $body = wp_remote_retrieve_body($response);
            $json_data = json_decode($body, true);
            
            if (json_last_error() === JSON_ERROR_NONE && 
                isset($json_data['InfoError']['Codigo']) && 
                $json_data['InfoError']['Codigo'] === 0) {
                return $session_id;
            }
        }
        
        return null;
    }

    /**
     * Filtra endpoints disponibles basándose en la respuesta de la API
     *
     * @param array $endpoints Lista de endpoints a probar
     * @param int   $sessionId ID de sesión válido
     * @return array Endpoints que funcionan correctamente
     * @since 2.0.0
     */
    public static function filter_available_endpoints(array $endpoints, int $sessionId): array {
        $available = [];
        
        foreach ($endpoints as $endpoint => $label) {
            $measurement = self::measure_endpoint_latency($endpoint, $sessionId);
            
            if ($measurement['success']) {
                $available[$endpoint] = $label;
            }
        }
        
        return $available;
    }

    /**
     * Diagnostica la conectividad con la API de Verial
     *
     * @return array Resultado del diagnóstico
     * @since 2.0.0
     */
    public static function diagnose_api_connectivity(): array {
        $diagnosis = [
            'server_reachable' => false,
            'endpoints_status' => [],
            'recommendations' => []
        ];
        
        // Probar conectividad básica
        $test_url = 'http://x.verial.org:8000/WcfServiceLibraryVerial/GetPaisesWS?x=18';
        $response = wp_remote_get($test_url, [
            'timeout' => 10,
            'sslverify' => false
        ]);
        
        if (!is_wp_error($response)) {
            $diagnosis['server_reachable'] = true;
        }
        
        // Probar cada endpoint individualmente
        $endpoints = [
            'GetArticulosWS' => 'Artículos',
            'GetImagenesArticulosWS' => 'Imágenes',
            'GetCondicionesTarifaWS' => 'Precios/Tarifas',
            'GetCategoriasWS' => 'Categorías',
            'GetFabricantesWS' => 'Fabricantes',
            'GetNumArticulosWS' => 'Total de artículos'
        ];
        
        foreach ($endpoints as $endpoint => $label) {
            $measurement = self::measure_endpoint_latency($endpoint);
            $diagnosis['endpoints_status'][$endpoint] = [
                'label' => $label,
                'success' => $measurement['success'],
                'error' => $measurement['error'] ?? null,
                'response_code' => $measurement['response_code'] ?? null,
                'latency' => $measurement['latency'] ?? null
            ];
        }
        
        // Generar recomendaciones
        $failed_endpoints = array_filter($diagnosis['endpoints_status'], function($status) {
            return !$status['success'];
        });
        
        if (count($failed_endpoints) > 0) {
            $diagnosis['recommendations'][] = sprintf(
                __('%d endpoints fallaron. Verifica que la API esté funcionando correctamente.', 'mi-integracion-api'),
                count($failed_endpoints)
            );
        }
        
        if (!$diagnosis['server_reachable']) {
            $diagnosis['recommendations'][] = __('El servidor x.verial.org:8000 no es accesible. Verifica la conectividad de red.', 'mi-integracion-api');
        }
        
        return $diagnosis;
    }
}
