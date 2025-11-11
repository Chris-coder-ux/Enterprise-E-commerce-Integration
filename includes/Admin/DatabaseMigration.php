<?php

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar migraciones de base de datos
 */
class DatabaseMigration
{
    /**
     * Versión actual de la base de datos
     */
    public const DB_VERSION = '1.1.0';
    
    /**
     * Opción para almacenar la versión de la base de datos
     */
    public const DB_VERSION_OPTION = 'mia_db_version';
    
    
    /**
     * Ejecutar migraciones necesarias
     * 
     * @return void
     * @throws \Exception Si el Installer no está disponible o falla la creación de tablas
     */
    public static function runMigrations(): void
    {
        $current_version = get_option(self::DB_VERSION_OPTION, '1.0.0');
        
        if (version_compare($current_version, self::DB_VERSION, '<')) {
            // Verificar que el Installer esté disponible
            if (!class_exists('MiIntegracionApi\\Core\\Installer')) {
                throw new \Exception('Installer no disponible. No se pueden ejecutar las migraciones de base de datos.');
            }
            
            // Crear todas las tablas necesarias usando el Installer centralizado
            $tables_to_create = [
                'create_sync_errors_table',
                'create_product_requests_table',
                'create_sync_lock_table',
                'create_product_mapping_table',
                'create_price_cache_table',
                'create_logs_table',
                'create_sync_history_table',
                'create_transients_migration_table',
                'create_sync_metrics_table',
                'create_sync_heartbeat_table'
            ];
            
            $failed_tables = [];
            
            foreach ($tables_to_create as $table_method) {
                $result = \MiIntegracionApi\Core\Installer::$table_method();
                if (!$result) {
                    $failed_tables[] = $table_method;
                }
            }
            
            // Si alguna tabla falló, lanzar excepción
            if (!empty($failed_tables)) {
                throw new \Exception('Error al crear las siguientes tablas: ' . implode(', ', $failed_tables));
            }
            
            update_option(self::DB_VERSION_OPTION, self::DB_VERSION);
        }
    }
    
    /**
     * Verificar si la tabla de solicitudes de productos existe
     * 
     * @return bool
     * @throws \Exception Si el Installer no está disponible
     */
    public static function tableExists(): bool
    {
        if (!class_exists('MiIntegracionApi\\Core\\Installer')) {
            throw new \Exception('Installer no disponible. No se puede verificar la existencia de la tabla.');
        }
        
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'mia_product_requests';
        $result = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");
        
        return $result === $table_name;
    }
}
