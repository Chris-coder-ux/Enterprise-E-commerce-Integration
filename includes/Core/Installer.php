<?php
declare(strict_types=1);

namespace MiIntegracionApi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Gestiona las tareas de instalación y activación del plugin.
 *
 * Esta clase se encarga de crear todas las tablas de base de datos necesarias
 * para el funcionamiento del plugin, así como configurar las opciones por defecto
 * del sistema de polling adaptativo, limpieza de transients y reintentos inteligentes.
 *
 * @package MiIntegracionApi\Core
 * @since   1.1.0
 * @author  Mi Integración API Team
 * @version 1.4.1
 */
class Installer {

    /**
     * El nombre de la tabla para los errores de sincronización.
     *
     * @var string
     */
    const SYNC_ERRORS_TABLE = 'mi_api_sync_errors';

    /**
     * El nombre de la tabla para el mapeo de productos.
     *
     * @var string
     */
    const PRODUCT_MAPPING_TABLE = 'verial_product_mapping';

    /**
     * El nombre de la tabla para el caché de precios.
     *
     * @var string
     */
    const PRICE_CACHE_TABLE = 'mi_api_price_cache';

    /**
     * El nombre de la tabla para los logs del sistema.
     *
     * @var string
     */
    const LOGS_TABLE = 'mi_integracion_api_logs';

    /**
     * El nombre de la tabla para el historial de sincronización.
     *
     * @var string
     */
    const SYNC_HISTORY_TABLE = 'mi_integracion_api_sync_history';

    /**
     * El nombre de la tabla para la migración de transients.
     *
     * @var string
     */
    const TRANSIENTS_MIGRATION_TABLE = 'mia_transients_migration';

    /**
     * El nombre de la tabla para las métricas de sincronización.
     *
     * @var string
     */
    const SYNC_METRICS_TABLE = 'mia_sync_metrics';

    /**
     * El nombre de la tabla para el latido de sincronización.
     *
     * @var string
     */
    const SYNC_HEARTBEAT_TABLE = 'mia_sync_heartbeat';

    /**
     * El nombre de la tabla para los bloqueos de sincronización.
     *
     * @var string
     */
    const SYNC_LOCK_TABLE = 'mia_sync_lock';

    /**
     * El nombre de la tabla para las solicitudes de productos.
     *
     * @var string
     */
    const PRODUCT_REQUESTS_TABLE = 'mia_product_requests';



    /**
     * Callback para el hook de activación del plugin.
     *
     * Crea todas las tablas de base de datos necesarias para el funcionamiento
     * del plugin y configura las opciones por defecto del sistema.
     *
     * Tablas creadas:
     * - mi_api_sync_errors: Errores de sincronización
     * - verial_product_mapping: Mapeo de productos WooCommerce-Verial
     * - mi_api_price_cache: Caché de precios con TTL
     * - mi_integracion_api_logs: Logs del sistema
     * - mi_integracion_api_sync_history: Historial de sincronizaciones
     * - mia_transients_migration: Migración de transients
     * - mia_sync_metrics: Métricas de rendimiento
     * - mia_sync_heartbeat: Monitoreo de procesos activos
     * - mia_sync_lock: Sistema de bloqueos distribuidos
     *
     * @since 1.1.0
     * @since 1.4.1 Agregado soporte para polling adaptativo y limpieza automática
     * 
     * @global wpdb $wpdb WordPress database abstraction object
     * @return void
     * 
     * @throws Exception Si hay errores en la creación de tablas
     */
    public static function activate(): void {
        global $wpdb;

        // Verificar que $wpdb esté disponible
        if (!$wpdb || !isset($wpdb->prefix)) {
            return;
        }

        $table_name      = $wpdb->prefix . self::SYNC_ERRORS_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_run_id VARCHAR(100) NOT NULL,
            item_sku VARCHAR(100) NOT NULL,
            item_data LONGTEXT NOT NULL,
            error_code VARCHAR(50) NOT NULL,
            error_message TEXT NOT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sync_run_id (sync_run_id),
            KEY item_sku (item_sku)
        ) {$charset_collate};";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        dbDelta( $sql );
        
        // Crear tabla de mapeo de productos
        $mapping_table_name = $wpdb->prefix . self::PRODUCT_MAPPING_TABLE;
        
        $mapping_sql = "CREATE TABLE {$mapping_table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wc_id bigint(20) NOT NULL,
            verial_id bigint(20) NOT NULL,
            sku varchar(100) DEFAULT '',
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_wc_id (wc_id),
            UNIQUE KEY unique_verial_id (verial_id),
            KEY sku (sku)
        ) {$charset_collate};";
        
        dbDelta( $mapping_sql );
        
        // Crear tabla de caché de precios
        $price_cache_table_name = $wpdb->prefix . self::PRICE_CACHE_TABLE;
        
        $price_cache_sql = "CREATE TABLE {$price_cache_table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sku VARCHAR(100) NOT NULL,
            precio DECIMAL(10,2) DEFAULT NULL,
            condiciones_tarifa_json TEXT DEFAULT NULL,
            fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ttl INT(11) NOT NULL DEFAULT 3600,
            hash_datos VARCHAR(32) NOT NULL DEFAULT '',
            estado ENUM('activo', 'expirado', 'invalidado') NOT NULL DEFAULT 'activo',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sku_unique (sku),
            KEY fecha_actualizacion (fecha_actualizacion),
            KEY estado (estado),
            KEY ttl (ttl),
            KEY hash_datos (hash_datos)
        ) {$charset_collate};";
        
        dbDelta( $price_cache_sql );
        
        // Crear tabla de logs del sistema
        $logs_table_name = $wpdb->prefix . self::LOGS_TABLE;
        
        $logs_sql = "CREATE TABLE {$logs_table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            fecha datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tipo varchar(20) NOT NULL DEFAULT 'info',
            usuario varchar(100),
            entidad varchar(100),
            mensaje text NOT NULL,
            contexto longtext,
            PRIMARY KEY (id),
            KEY idx_fecha (fecha),
            KEY idx_tipo (tipo),
            KEY idx_entidad (entidad)
        ) {$charset_collate};";
        
        dbDelta( $logs_sql );
        
        // Crear tabla de historial de sincronización
        $sync_history_table_name = $wpdb->prefix . self::SYNC_HISTORY_TABLE;
        
        $sync_history_sql = "CREATE TABLE {$sync_history_table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            tipo varchar(50) NOT NULL,
            message text NOT NULL,
            details longtext,
            status varchar(20) NOT NULL DEFAULT 'complete',
            elapsed_time float DEFAULT NULL,
            items_processed int DEFAULT 0,
            items_success int DEFAULT 0,
            items_error int DEFAULT 0,
            PRIMARY KEY (id)
        ) {$charset_collate};";
        
        dbDelta( $sync_history_sql );
        
        // Crear tabla de migración de transients
        $transients_migration_table_name = $wpdb->prefix . self::TRANSIENTS_MIGRATION_TABLE;
        
        $transients_migration_sql = "CREATE TABLE {$transients_migration_table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            data longblob NOT NULL,
            original_size_bytes bigint(20) NOT NULL,
            compressed_size_bytes bigint(20) NOT NULL,
            compression_ratio decimal(5,4) NOT NULL,
            migrated_at datetime NOT NULL,
            last_accessed datetime NOT NULL,
            access_count int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY size_index (original_size_bytes),
            KEY access_index (last_accessed, access_count)
        ) {$charset_collate};";
        
        dbDelta( $transients_migration_sql );
        
        // Crear tabla de métricas de sincronización
        $sync_metrics_table_name = $wpdb->prefix . self::SYNC_METRICS_TABLE;
        
        $sync_metrics_sql = "CREATE TABLE {$sync_metrics_table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            total_items int(11) NOT NULL DEFAULT 0,
            processed_items int(11) NOT NULL DEFAULT 0,
            successful_items int(11) NOT NULL DEFAULT 0,
            error_items int(11) NOT NULL DEFAULT 0,
            processing_time decimal(10,3) NOT NULL DEFAULT 0.000,
            memory_used decimal(10,3) NOT NULL DEFAULT 0.000,
            memory_peak decimal(10,3) NOT NULL DEFAULT 0.000,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sync_type (sync_type),
            KEY created_at (created_at),
            KEY processing_time (processing_time)
        ) {$charset_collate};";
        
        dbDelta( $sync_metrics_sql );
        
        // Crear tabla de latido de sincronización
        $sync_heartbeat_table_name = $wpdb->prefix . self::SYNC_HEARTBEAT_TABLE;
        
        $sync_heartbeat_sql = "CREATE TABLE {$sync_heartbeat_table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_id varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            last_heartbeat datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            progress_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            current_batch int(11) NOT NULL DEFAULT 0,
            total_batches int(11) NOT NULL DEFAULT 0,
            items_processed int(11) NOT NULL DEFAULT 0,
            total_items int(11) NOT NULL DEFAULT 0,
            error_count int(11) NOT NULL DEFAULT 0,
            memory_usage decimal(10,3) NOT NULL DEFAULT 0.000,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sync_id (sync_id),
            KEY status (status),
            KEY last_heartbeat (last_heartbeat),
            KEY progress_percent (progress_percent)
        ) {$charset_collate};";
        
        dbDelta( $sync_heartbeat_sql );
        
        // Crear tabla de bloqueos de sincronización
        $sync_lock_table_name = $wpdb->prefix . self::SYNC_LOCK_TABLE;
        
        $sync_lock_sql = "CREATE TABLE {$sync_lock_table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            lock_key varchar(100) NOT NULL,
            lock_type varchar(50) NOT NULL,
            lock_data longtext,
            acquired_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            released_at datetime DEFAULT NULL,
            lock_owner varchar(100),
            PRIMARY KEY (id),
            UNIQUE KEY lock_key (lock_key),
            KEY lock_type (lock_type),
            KEY acquired_at (acquired_at),
            KEY expires_at (expires_at)
        ) {$charset_collate};";
        
        dbDelta( $sync_lock_sql );
        
        // CORRECCIÓN #6: Configurar opciones por defecto del polling adaptativo
        self::setup_default_polling_options();
    }
    
    /**
     * Configura las opciones por defecto del sistema de polling adaptativo.
     * 
     * Establece la configuración inicial para:
     * - Intervalos de polling adaptativos según el estado del sistema
     * - Umbrales para cambio de frecuencia de polling
     * - Sistema de limpieza automática de transients
     * - Sistema de reintentos inteligente con backoff exponencial
     * - Monitoreo avanzado de memoria con alertas
     * - Dashboard unificado con diagnóstico automático
     * 
     * Solo configura opciones que no existan previamente para preservar
     * la configuración personalizada del usuario.
     * 
     * @since 1.4.1
     * @return void
     * 
     * @see get_option() Para verificar opciones existentes
     * @see add_option() Para agregar nuevas opciones
     */
    private static function setup_default_polling_options(): void {
        // Intervalos de polling en milisegundos
        $default_options = [
            'mia_polling_active_interval' => 1500,   // Cuando hay progreso activo (1.5s) - OPTIMIZADO
            'mia_polling_normal_interval' => 3000,   // Intervalo normal (3s) - OPTIMIZADO
            'mia_polling_slow_interval' => 6000,     // Cuando no hay cambios (6s) - OPTIMIZADO
            'mia_polling_idle_interval' => 12000,    // Cuando está inactivo (12s) - OPTIMIZADO
            'mia_polling_error_interval' => 8000,   // Después de errores (8s)
            
            // Límites para cambio de frecuencia
            'mia_polling_threshold_slow' => 3,      // Cambiar a slow después de N verificaciones
            'mia_polling_threshold_idle' => 8,      // Cambiar a idle después de N verificaciones
            'mia_polling_max_errors' => 5,          // Máximo errores antes de parar
            
            // Configuración adicional
            'mia_polling_enabled' => true,          // Habilitar polling adaptativo
            'mia_polling_version' => '1.0',         // Versión de configuración
            
            // CORRECCIÓN #7: Opciones de limpieza automática de transients
            'mia_transient_cleanup_enabled' => true,    // Habilitar limpieza automática
            'mia_transient_cleanup_age' => 24,           // Edad máxima en horas (24h por defecto)
            'mia_transient_cleanup_frequency' => 'daily', // Frecuencia: daily, twicedaily, hourly
            'mia_transient_cleanup_max_items' => 1000,   // Máximo items a limpiar por ejecución
            'mia_transient_cleanup_version' => '1.0',    // Versión de configuración de limpieza
            
            // CORRECCIÓN #8: Opciones de sistema de reintentos inteligente
            'mia_retry_system_enabled' => true,          // Habilitar sistema de reintentos inteligente
            'mia_retry_default_max_attempts' => 3,       // Número máximo de reintentos por defecto
            'mia_retry_default_base_delay' => 2,         // Retraso base en segundos
            'mia_retry_max_delay' => 30,                 // Retraso máximo en segundos
            'mia_retry_backoff_factor' => 2.0,           // Factor de backoff exponencial
            'mia_retry_jitter_enabled' => true,          // Habilitar jitter para evitar thundering herd
            'mia_retry_jitter_max_ms' => 1000,          // Jitter máximo en milisegundos
            
            // Políticas de reintentos por tipo de error
            'mia_retry_policy_network' => 'aggressive',  // network, timeout, ssl: aggressive, moderate, conservative
            'mia_retry_policy_server' => 'moderate',     // server errors: moderate
            'mia_retry_policy_client' => 'conservative', // client errors: conservative
            'mia_retry_policy_validation' => 'none',     // validation errors: no reintentos
            
            // Configuración específica por tipo de operación
            'mia_retry_sync_products_max_attempts' => 3,     // Sincronización de productos
            'mia_retry_sync_orders_max_attempts' => 4,       // Sincronización de pedidos (más crítico)
            'mia_retry_sync_customers_max_attempts' => 3,    // Sincronización de clientes
            'mia_retry_api_calls_max_attempts' => 5,         // Llamadas API generales
            'mia_retry_ssl_operations_max_attempts' => 3,    // Operaciones SSL
            
            'mia_retry_system_version' => '1.0',            // Versión de configuración de reintentos
            
            // CORRECCIÓN #9: Opciones de monitoreo de memoria mejorado
            'mia_memory_monitoring_enabled' => true,         // Habilitar monitoreo avanzado de memoria
            'mia_memory_warning_threshold' => 0.7,           // Umbral de advertencia (70%)
            'mia_memory_critical_threshold' => 0.9,          // Umbral crítico (90%)
            'mia_memory_cleanup_threshold' => 0.75,          // Umbral para limpieza (75%)
            'mia_memory_history_max_records' => 100,         // Máximo registros en historial
            'mia_memory_alerts_max_records' => 50,           // Máximo alertas en historial
            'mia_memory_auto_cleanup_enabled' => true,       // Habilitar limpieza automática
            'mia_memory_auto_cleanup_interval' => 300,       // Intervalo de limpieza automática (5 min)
            'mia_memory_notifications_enabled' => true,      // Habilitar notificaciones de memoria
            'mia_memory_dashboard_refresh_interval' => 30,   // Intervalo de refresco del dashboard (30s)
            'mia_memory_system_version' => '1.0',           // Versión de configuración de memoria
            
            // CORRECCIÓN #10: Opciones de dashboard unificado con diagnóstico automático
            'mia_dashboard_unified_enabled' => true,         // Habilitar dashboard unificado
            'mia_dashboard_auto_diagnostic_enabled' => true, // Habilitar diagnóstico automático
            'mia_dashboard_refresh_interval' => 60,          // Intervalo de refresco del dashboard (60s)
            'mia_dashboard_health_thresholds' => [           // Umbrales de salud del sistema
                'memory_critical' => 0.9,                   // 90% memoria = crítico
                'memory_warning' => 0.7,                    // 70% memoria = advertencia
                'retry_critical' => 0.6,                    // 60% éxito reintentos = crítico
                'retry_warning' => 0.8,                     // 80% éxito reintentos = advertencia
                'sync_timeout_hours' => 24                  // 24h sin sincronización = advertencia
            ],
            'mia_dashboard_notifications_enabled' => true,   // Habilitar notificaciones del dashboard
            'mia_dashboard_export_enabled' => true,          // Habilitar exportación de reportes
            'mia_dashboard_system_version' => '1.0'         // Versión de configuración del dashboard
        ];
        
        // Solo agregar opciones que no existan (no sobrescribir configuración existente)
        foreach ($default_options as $option_name => $default_value) {
            if (get_option($option_name) === false) {
                add_option($option_name, $default_value);
            }
        }
    }
    
    /**
     * Crea la tabla de mapeo de productos si no existe.
     * 
     * Esta tabla mantiene la relación entre productos de WooCommerce y Verial,
     * permitiendo sincronización bidireccional y resolución de conflictos.
     * 
     * Estructura de la tabla:
     * - id: Clave primaria autoincremental
     * - wc_id: ID del producto en WooCommerce (único)
     * - verial_id: ID del producto en Verial (único)
     * - sku: SKU del producto para referencia rápida
     * - created_at/updated_at: Timestamps de auditoría
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_product_mapping_table(): bool {
        global $wpdb;
        
        // Verificar que $wpdb esté disponible
        if (!$wpdb || !isset($wpdb->prefix)) {
            return false;
        }
        
        $table_name = $wpdb->prefix . self::PRODUCT_MAPPING_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            wc_id bigint(20) NOT NULL,
            verial_id bigint(20) NOT NULL,
            sku varchar(100) DEFAULT '',
            created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_wc_id (wc_id),
            UNIQUE KEY unique_verial_id (verial_id),
            KEY sku (sku)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Crea la tabla de caché de precios si no existe.
     * 
     * Sistema de caché inteligente para precios con soporte para:
     * - TTL (Time To Live) configurable por entrada
     * - Estados de caché (activo, expirado, invalidado)
     * - Hash de datos para detección de cambios
     * - Condiciones de tarifa en formato JSON
     * - Timestamps automáticos para auditoría
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_price_cache_table(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::PRICE_CACHE_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sku VARCHAR(100) NOT NULL,
            precio DECIMAL(10,2) DEFAULT NULL,
            condiciones_tarifa_json TEXT DEFAULT NULL,
            fecha_actualizacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ttl INT(11) NOT NULL DEFAULT 3600,
            hash_datos VARCHAR(32) NOT NULL DEFAULT '',
            estado ENUM('activo', 'expirado', 'invalidado') NOT NULL DEFAULT 'activo',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sku_unique (sku),
            KEY fecha_actualizacion (fecha_actualizacion),
            KEY estado (estado),
            KEY ttl (ttl),
            KEY hash_datos (hash_datos)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Crea la tabla de logs del sistema si no existe.
     * 
     * Sistema centralizado de logging con soporte para:
     * - Diferentes tipos de log (info, warning, error, debug)
     * - Contexto de usuario y entidad afectada
     * - Contexto adicional en formato JSON
     * - Índices optimizados para consultas por fecha, tipo y entidad
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_logs_table(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::LOGS_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            fecha datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tipo varchar(20) NOT NULL DEFAULT 'info',
            usuario varchar(100),
            entidad varchar(100),
            mensaje text NOT NULL,
            contexto longtext,
            PRIMARY KEY (id),
            KEY idx_fecha (fecha),
            KEY idx_tipo (tipo),
            KEY idx_entidad (entidad)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Crea la tabla de historial de sincronización si no existe.
     * 
     * Mantiene un registro completo de todas las operaciones de sincronización:
     * - Timestamp y tipo de sincronización
     * - Mensaje descriptivo y detalles técnicos
     * - Estado final (complete, error, cancelled)
     * - Métricas de rendimiento (tiempo, items procesados)
     * - Contadores de éxito y error
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_sync_history_table(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::SYNC_HISTORY_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            tipo varchar(50) NOT NULL,
            message text NOT NULL,
            details longtext,
            status varchar(20) NOT NULL DEFAULT 'complete',
            elapsed_time float DEFAULT NULL,
            items_processed int DEFAULT 0,
            items_success int DEFAULT 0,
            items_error int DEFAULT 0,
            PRIMARY KEY (id)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Crea la tabla de migración de transients si no existe.
     * 
     * Sistema de migración y compresión de transients para optimizar
     * el rendimiento de la base de datos:
     * - Almacenamiento comprimido con ratio de compresión
     * - Métricas de tamaño original vs comprimido
     * - Tracking de acceso y frecuencia de uso
     * - Timestamps para limpieza automática
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_transients_migration_table(): bool {
        global $wpdb;
        
        // Verificar que $wpdb esté disponible
        if (!$wpdb || !isset($wpdb->prefix)) {
            return false;
        }
        
        $table_name = $wpdb->prefix . self::TRANSIENTS_MIGRATION_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            cache_key varchar(255) NOT NULL,
            data longblob NOT NULL,
            original_size_bytes bigint(20) NOT NULL,
            compressed_size_bytes bigint(20) NOT NULL,
            compression_ratio decimal(5,4) NOT NULL,
            migrated_at datetime NOT NULL,
            last_accessed datetime NOT NULL,
            access_count int(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY cache_key (cache_key),
            KEY size_index (original_size_bytes),
            KEY access_index (last_accessed, access_count)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Crea la tabla de métricas de sincronización si no existe.
     * 
     * Almacena métricas detalladas de rendimiento para análisis:
     * - Tipo de sincronización y contadores de items
     * - Tiempo de procesamiento y uso de memoria
     * - Pico de memoria durante la operación
     * - Timestamps automáticos para trending
     * - Índices optimizados para reportes de rendimiento
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_sync_metrics_table(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::SYNC_METRICS_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_type varchar(50) NOT NULL,
            total_items int(11) NOT NULL DEFAULT 0,
            processed_items int(11) NOT NULL DEFAULT 0,
            successful_items int(11) NOT NULL DEFAULT 0,
            error_items int(11) NOT NULL DEFAULT 0,
            processing_time decimal(10,3) NOT NULL DEFAULT 0.000,
            memory_used decimal(10,3) NOT NULL DEFAULT 0.000,
            memory_peak decimal(10,3) NOT NULL DEFAULT 0.000,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sync_type (sync_type),
            KEY created_at (created_at),
            KEY processing_time (processing_time)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Crea la tabla de latido de sincronización si no existe.
     * 
     * Sistema de monitoreo en tiempo real para procesos de sincronización:
     * - ID único de sincronización y estado actual
     * - Heartbeat timestamp para detección de procesos colgados
     * - Progreso detallado (porcentaje, lotes, items)
     * - Contadores de errores y uso de memoria
     * - Timestamps automáticos para auditoría
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_sync_heartbeat_table(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::SYNC_HEARTBEAT_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_id varchar(100) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            last_heartbeat datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            progress_percent decimal(5,2) NOT NULL DEFAULT 0.00,
            current_batch int(11) NOT NULL DEFAULT 0,
            total_batches int(11) NOT NULL DEFAULT 0,
            items_processed int(11) NOT NULL DEFAULT 0,
            total_items int(11) NOT NULL DEFAULT 0,
            error_count int(11) NOT NULL DEFAULT 0,
            memory_usage decimal(10,3) NOT NULL DEFAULT 0.000,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY sync_id (sync_id),
            KEY status (status),
            KEY last_heartbeat (last_heartbeat),
            KEY progress_percent (progress_percent)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Crea la tabla de bloqueos de sincronización si no existe.
     * 
     * Sistema de bloqueos distribuidos para prevenir condiciones de carrera:
     * - Clave única de bloqueo y tipo de operación
     * - Datos del bloqueo en formato JSON
     * - Timestamps de adquisición, expiración y liberación
     * - Propietario del bloqueo para debugging
     * - Limpieza automática de bloqueos expirados
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_sync_lock_table(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::SYNC_LOCK_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            lock_key varchar(100) NOT NULL,
            lock_type varchar(50) NOT NULL,
            lock_data longtext,
            acquired_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            released_at datetime DEFAULT NULL,
            lock_owner varchar(100),
            pid int UNSIGNED DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY lock_key (lock_key),
            KEY lock_type (lock_type),
            KEY acquired_at (acquired_at),
            KEY expires_at (expires_at),
            KEY pid (pid)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        $table_created = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if ($table_created) {
            // Migrar tabla existente si no tiene la columna pid
            self::migrate_sync_lock_table_if_needed();
        }
        
        return $table_created;
    }
    
    /**
     * Migra la tabla mia_sync_lock si no tiene la columna pid
     * 
     * @return bool True si la migración fue exitosa o no era necesaria
     * @since 1.4.0
     */
    public static function migrate_sync_lock_table_if_needed(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::SYNC_LOCK_TABLE;
        
        // Verificar si la tabla existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            return false;
        }
        
        // Verificar si ya tiene la columna pid
        $column_exists = $wpdb->get_var(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS 
             WHERE TABLE_SCHEMA = DATABASE() 
             AND TABLE_NAME = '$table_name' 
             AND COLUMN_NAME = 'pid'"
        );
        
        if ($column_exists > 0) {
            return true; // Ya tiene la columna
        }
        
        // Agregar la columna pid
        $result = $wpdb->query(
            "ALTER TABLE $table_name 
             ADD COLUMN pid int UNSIGNED DEFAULT NULL,
             ADD KEY pid (pid)"
        );
        
        return $result !== false;
    }
    
    /**
     * Crea la tabla de solicitudes de productos si no existe.
     * 
     * Sistema de gestión de solicitudes de productos con soporte para:
     * - Referencia única del producto
     * - Datos del producto en formato JSON
     * - Estado de la solicitud (pending, processing, completed, failed)
     * - Timestamps de creación y actualización
     * - Índices optimizados para consultas por referencia y estado
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_product_requests_table(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::PRODUCT_REQUESTS_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            reference varchar(100) NOT NULL,
            product_data longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY reference (reference),
            KEY status (status),
            KEY created_at (created_at)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    
    /**
     * Crea la tabla de errores de sincronización si no existe.
     * 
     * Sistema de registro de errores de sincronización con soporte para:
     * - ID único de ejecución de sincronización
     * - SKU del item que falló
     * - Datos completos del item en formato JSON
     * - Código y mensaje de error específicos
     * - Timestamp automático para auditoría
     * - Índices optimizados para consultas por ejecución y SKU
     * 
     * @since 1.4.1
     * @global wpdb $wpdb WordPress database abstraction object
     * @return bool True si la tabla se creó o ya existía, False en caso de error
     * 
     * @see dbDelta() Para creación segura de tablas
     */
    public static function create_sync_errors_table(): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::SYNC_ERRORS_TABLE;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Verificar si la tabla ya existe
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            return true;
        }
        
        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_run_id VARCHAR(100) NOT NULL,
            item_sku VARCHAR(100) NOT NULL,
            item_data LONGTEXT NOT NULL,
            error_code VARCHAR(50) NOT NULL,
            error_message TEXT NOT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sync_run_id (sync_run_id),
            KEY item_sku (item_sku)
        ) {$charset_collate};";
        
        $result = dbDelta( $sql );
        
        // Verificar si la tabla se creó correctamente
        return $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    }
    

}
