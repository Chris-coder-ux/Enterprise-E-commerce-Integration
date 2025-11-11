<?php
declare(strict_types=1);

namespace MiIntegracionApi\Config;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Configuración del sistema de limpieza de logs
 * 
 * @package MiIntegracionApi\Config
 * @since 2.0.0
 */
class LogCleanupConfig
{
    /**
     * Obtiene la configuración por defecto del sistema de limpieza
     * 
     * @return array
     */
    public static function getDefaultConfig(): array
    {
        return [
            'enabled' => true,
            'max_age_days' => 7,           // Eliminar logs más antiguos de 7 días
            'max_size_mb' => 100,          // Limpiar cuando supere 100MB
            'max_files' => 10,             // Mantener máximo 10 archivos
            'cleanup_interval' => 24,      // Verificar cada 24 horas
            'products_interval' => 1000,   // Limpiar cada 1000 productos sincronizados
            'log_directories' => [
                'api_connector',
                'logs',
                'cache'
            ],
            'file_patterns' => [
                '*.log',
                '*.txt',
                '*.json'
            ],
            'exclude_patterns' => [
                '*.backup',
                '*.old',
                '*.tmp'
            ]
        ];
    }
    
    /**
     * Obtiene la configuración desde la base de datos de WordPress
     * 
     * @return array
     */
    public static function getConfig(): array
    {
        $default_config = self::getDefaultConfig();
        $saved_config = get_option('mia_log_cleanup_config', []);
        
        return array_merge($default_config, $saved_config);
    }
    
    /**
     * Guarda la configuración en la base de datos de WordPress
     * 
     * @param array $config
     * @return bool
     */
    public static function saveConfig(array $config): bool
    {
        $validated_config = self::validateConfig($config);
        return update_option('mia_log_cleanup_config', $validated_config);
    }
    
    /**
     * Valida la configuración
     * 
     * @param array $config
     * @return array
     */
    public static function validateConfig(array $config): array
    {
        $default_config = self::getDefaultConfig();
        $validated_config = [];
        
        // Validar enabled
        if (isset($config['enabled'])) {
            $validated_config['enabled'] = (bool)$config['enabled'];
        }
        
        // Validar max_age_days
        if (isset($config['max_age_days'])) {
            $validated_config['max_age_days'] = max(1, min(365, (int)$config['max_age_days']));
        }
        
        // Validar max_size_mb
        if (isset($config['max_size_mb'])) {
            $validated_config['max_size_mb'] = max(1, min(10000, (int)$config['max_size_mb']));
        }
        
        // Validar max_files
        if (isset($config['max_files'])) {
            $validated_config['max_files'] = max(1, min(1000, (int)$config['max_files']));
        }
        
        // Validar cleanup_interval
        if (isset($config['cleanup_interval'])) {
            $validated_config['cleanup_interval'] = max(1, min(168, (int)$config['cleanup_interval']));
        }
        
        // Validar products_interval
        if (isset($config['products_interval'])) {
            $validated_config['products_interval'] = max(10, min(100000, (int)$config['products_interval']));
        }
        
        // Validar log_directories
        if (isset($config['log_directories']) && is_array($config['log_directories'])) {
            $validated_config['log_directories'] = array_filter($config['log_directories'], 'is_string');
        }
        
        // Validar file_patterns
        if (isset($config['file_patterns']) && is_array($config['file_patterns'])) {
            $validated_config['file_patterns'] = array_filter($config['file_patterns'], 'is_string');
        }
        
        // Validar exclude_patterns
        if (isset($config['exclude_patterns']) && is_array($config['exclude_patterns'])) {
            $validated_config['exclude_patterns'] = array_filter($config['exclude_patterns'], 'is_string');
        }
        
        return array_merge($default_config, $validated_config);
    }
    
    /**
     * Obtiene la configuración para un entorno específico
     * 
     * @param string $environment
     * @return array
     */
    public static function getConfigForEnvironment(string $environment): array
    {
        $base_config = self::getConfig();
        
        switch ($environment) {
            case 'development':
                return array_merge($base_config, [
                    'max_age_days' => 3,
                    'max_size_mb' => 50,
                    'max_files' => 5,
                    'products_interval' => 100
                ]);
                
            case 'staging':
                return array_merge($base_config, [
                    'max_age_days' => 5,
                    'max_size_mb' => 75,
                    'max_files' => 7,
                    'products_interval' => 500
                ]);
                
            case 'production':
                return array_merge($base_config, [
                    'max_age_days' => 14,
                    'max_size_mb' => 200,
                    'max_files' => 20,
                    'products_interval' => 2000
                ]);
                
            default:
                return $base_config;
        }
    }
    
    /**
     * Resetea la configuración a los valores por defecto
     * 
     * @return bool
     */
    public static function resetToDefault(): bool
    {
        return update_option('mia_log_cleanup_config', self::getDefaultConfig());
    }
    
    /**
     * Obtiene estadísticas de la configuración actual
     * 
     * @return array
     */
    public static function getConfigStats(): array
    {
        $config = self::getConfig();
        
        return [
            'enabled' => $config['enabled'],
            'max_age_days' => $config['max_age_days'],
            'max_size_mb' => $config['max_size_mb'],
            'max_files' => $config['max_files'],
            'cleanup_interval_hours' => $config['cleanup_interval'],
            'products_interval' => $config['products_interval'],
            'directories_count' => count($config['log_directories']),
            'file_patterns_count' => count($config['file_patterns']),
            'exclude_patterns_count' => count($config['exclude_patterns']),
            'last_updated' => get_option('mia_log_cleanup_config_updated', 'Nunca')
        ];
    }
}
