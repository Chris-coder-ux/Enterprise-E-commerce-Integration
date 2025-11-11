<?php

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

/**
 * Gestor de configuración avanzada para overrides del mapeo Verial
 * 
 * @since 1.0.0
 * @package MiIntegracionApi\Admin
 */
class MappingOverrides {
    
    /**
     * Prefijo para las opciones de WordPress
     */
    private const OPTION_PREFIX = 'mi_integracion_api_override_';
    
    /**
     * Configuraciones por defecto
     */
    private const DEFAULTS = [
        'destino' => [],
        'formas_envio' => [],
        'metodos_pago' => [],
        'bultos' => [],
        'peso_default' => 0.5,
        'tipo_portes_default' => 2,
        'pais_default' => 1, // España
    ];
    
    /**
     * Obtiene una opción de WordPress con fallback seguro
     * 
     * @param string $option_name Nombre de la opción
     * @param mixed $default Valor por defecto
     * @return mixed
     */
    private static function getOptionSafe(string $option_name, $default = false) {
        if (function_exists('get_option')) {
            return get_option($option_name, $default);
        }
        // Fallback para entornos sin WordPress (testing)
        return $default;
    }
    
    /**
     * Establece una opción de WordPress con fallback seguro
     * 
     * @param string $option_name Nombre de la opción
     * @param mixed $value Valor a establecer
     * @return bool
     */
    private static function updateOptionSafe(string $option_name, $value): bool {
        if (function_exists('update_option')) {
            return update_option($option_name, $value);
        }
        // Fallback para entornos sin WordPress (testing)
        return true;
    }
    
    /**
     * Obtiene override de destinos
     * 
     * @return array Mapeo país_estado => id_destino
     */
    public static function getDestinoOverrides(): array {
        $result = self::getOptionSafe(self::OPTION_PREFIX . 'destino', self::DEFAULTS['destino']);
        return is_array($result) ? $result : self::DEFAULTS['destino'];
    }
    
    /**
     * Establece override de destinos
     * 
     * @param array $overrides Mapeo país_estado => id_destino
     * @return bool
     */
    public static function setDestinoOverrides(array $overrides): bool {
        return self::updateOptionSafe(self::OPTION_PREFIX . 'destino', $overrides);
    }
    
    /**
     * Añade un override de destino específico
     * 
     * @param string $country_state Código país_estado (ej: ES_PM)
     * @param int $destino_id ID de destino Verial
     * @return bool
     */
    public static function addDestinoOverride(string $country_state, int $destino_id): bool {
        $overrides = self::getDestinoOverrides();
        $overrides[$country_state] = $destino_id;
        return self::setDestinoOverrides($overrides);
    }
    
    /**
     * Obtiene override de formas de envío
     * 
     * @return array Mapeo shipping_method => id_forma_envio
     */
    public static function getFormasEnvioOverrides(): array {
        $result = self::getOptionSafe(self::OPTION_PREFIX . 'formas_envio', self::DEFAULTS['formas_envio']);
        return is_array($result) ? $result : self::DEFAULTS['formas_envio'];
    }
    
    /**
     * Establece override de formas de envío
     * 
     * @param array $overrides Mapeo shipping_method => id_forma_envio
     * @return bool
     */
    public static function setFormasEnvioOverrides(array $overrides): bool {
        return self::updateOptionSafe(self::OPTION_PREFIX . 'formas_envio', $overrides);
    }
    
    /**
     * Obtiene override de métodos de pago
     * 
     * @return array Mapeo payment_method => id_metodo_pago
     */
    public static function getMetodosPagoOverrides(): array {
        $result = self::getOptionSafe(self::OPTION_PREFIX . 'metodos_pago', self::DEFAULTS['metodos_pago']);
        return is_array($result) ? $result : self::DEFAULTS['metodos_pago'];
    }
    
    /**
     * Establece override de métodos de pago
     * 
     * @param array $overrides Mapeo payment_method => id_metodo_pago
     * @return bool
     */
    public static function setMetodosPagoOverrides(array $overrides): bool {
        return update_option(self::OPTION_PREFIX . 'metodos_pago', $overrides);
    }
    
    /**
     * Obtiene override de bultos por método de envío
     * 
     * @return array Mapeo shipping_method => numero_bultos
     */
    public static function getBultosOverrides(): array {
        $result = get_option(self::OPTION_PREFIX . 'bultos', self::DEFAULTS['bultos']);
        return is_array($result) ? $result : self::DEFAULTS['bultos'];
    }
    
    /**
     * Establece override de bultos
     * 
     * @param array $overrides Mapeo shipping_method => numero_bultos
     * @return bool
     */
    public static function setBultosOverrides(array $overrides): bool {
        return update_option(self::OPTION_PREFIX . 'bultos', $overrides);
    }
    
    /**
     * Obtiene peso por defecto para productos sin peso
     * 
     * @return float Peso en kg
     */
    public static function getPesoDefault(): float {
        $result = get_option(self::OPTION_PREFIX . 'peso_default', self::DEFAULTS['peso_default']);
        return is_numeric($result) ? (float)$result : (float)self::DEFAULTS['peso_default'];
    }
    
    /**
     * Establece peso por defecto
     * 
     * @param float $peso Peso en kg
     * @return bool
     */
    public static function setPesoDefault(float $peso): bool {
        return update_option(self::OPTION_PREFIX . 'peso_default', $peso);
    }
    
    /**
     * Obtiene tipo de portes por defecto
     * 
     * @return int 1=Debidos, 2=Pagados
     */
    public static function getTipoPortesDefault(): int {
        $result = get_option(self::OPTION_PREFIX . 'tipo_portes_default', self::DEFAULTS['tipo_portes_default']);
        $value = is_numeric($result) ? (int)$result : (int)self::DEFAULTS['tipo_portes_default'];
        return in_array($value, [1, 2]) ? $value : (int)self::DEFAULTS['tipo_portes_default'];
    }
    
    /**
     * Establece tipo de portes por defecto
     * 
     * @param int $tipo 1=Debidos, 2=Pagados
     * @return bool
     */
    public static function setTipoPortesDefault(int $tipo): bool {
        if (!in_array($tipo, [1, 2])) {
            return false;
        }
        return update_option(self::OPTION_PREFIX . 'tipo_portes_default', $tipo);
    }
    
    /**
     * Obtiene país por defecto para clientes sin país
     * 
     * @return int ID país Verial
     */
    public static function getPaisDefault(): int {
        $result = get_option(self::OPTION_PREFIX . 'pais_default', self::DEFAULTS['pais_default']);
        $value = is_numeric($result) ? (int)$result : (int)self::DEFAULTS['pais_default'];
        return $value > 0 ? $value : (int)self::DEFAULTS['pais_default'];
    }
    
    /**
     * Establece país por defecto
     * 
     * @param int $pais_id ID país Verial
     * @return bool
     */
    public static function setPaisDefault(int $pais_id): bool {
        return update_option(self::OPTION_PREFIX . 'pais_default', $pais_id);
    }
    
    /**
     * Obtiene toda la configuración de overrides
     * 
     * @return array Configuración completa
     */
    public static function getAllOverrides(): array {
        return [
            'destino' => self::getDestinoOverrides(),
            'formas_envio' => self::getFormasEnvioOverrides(),
            'metodos_pago' => self::getMetodosPagoOverrides(),
            'bultos' => self::getBultosOverrides(),
            'peso_default' => self::getPesoDefault(),
            'tipo_portes_default' => self::getTipoPortesDefault(),
            'pais_default' => self::getPaisDefault(),
        ];
    }
    
    /**
     * Resetea todas las configuraciones a valores por defecto
     * 
     * @return bool
     */
    public static function resetToDefaults(): bool {
        $success = true;
        foreach (array_keys(self::DEFAULTS) as $key) {
            $option_name = self::OPTION_PREFIX . $key;
            $success = $success && delete_option($option_name);
        }
        return $success;
    }
    
    /**
     * Valida configuración de overrides
     * 
     * @param array $config Configuración a validar
     * @return array ['valid' => bool, 'errors' => array]
     */
    public static function validateOverrides(array $config): array {
        $errors = [];
        
        // Validar destinos
        if (isset($config['destino'])) {
            foreach ($config['destino'] as $key => $value) {
                if (!is_string($key) || !is_int($value) || $value < 1) {
                    $errors[] = "Destino override inválido: {$key} => {$value}";
                }
            }
        }
        
        // Validar formas de envío
        if (isset($config['formas_envio'])) {
            foreach ($config['formas_envio'] as $key => $value) {
                if (!is_string($key) || !is_int($value) || $value < 1) {
                    $errors[] = "Forma de envío override inválida: {$key} => {$value}";
                }
            }
        }
        
        // Validar métodos de pago
        if (isset($config['metodos_pago'])) {
            foreach ($config['metodos_pago'] as $key => $value) {
                if (!is_string($key) || !is_int($value) || $value < 1) {
                    $errors[] = "Método de pago override inválido: {$key} => {$value}";
                }
            }
        }
        
        // Validar bultos
        if (isset($config['bultos'])) {
            foreach ($config['bultos'] as $key => $value) {
                if (!is_string($key) || !is_int($value) || $value < 1) {
                    $errors[] = "Bultos override inválido: {$key} => {$value}";
                }
            }
        }
        
        // Validar peso por defecto
        if (isset($config['peso_default'])) {
            if (!is_numeric($config['peso_default']) || $config['peso_default'] < 0) {
                $errors[] = "Peso por defecto debe ser numérico positivo";
            }
        }
        
        // Validar tipo portes por defecto
        if (isset($config['tipo_portes_default'])) {
            if (!in_array($config['tipo_portes_default'], [1, 2])) {
                $errors[] = "Tipo portes debe ser 1 (Debidos) o 2 (Pagados)";
            }
        }
        
        // Validar país por defecto
        if (isset($config['pais_default'])) {
            if (!is_int($config['pais_default']) || $config['pais_default'] < 1) {
                $errors[] = "País por defecto debe ser un ID válido";
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Exporta configuración para backup
     * 
     * @return string JSON con toda la configuración
     */
    public static function exportConfiguration(): string {
        $config = self::getAllOverrides();
        $config['_export_timestamp'] = current_time('Y-m-d H:i:s');
        $config['_plugin_version'] = get_plugin_data(__FILE__)['Version'] ?? 'unknown';
        
        return json_encode($config, JSON_PRETTY_PRINT);
    }
    
    /**
     * Importa configuración desde backup
     * 
     * @param string $json_config JSON de configuración
     * @return array ['success' => bool, 'message' => string]
     */
    public static function importConfiguration(string $json_config): array {
        $config = json_decode($json_config, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'message' => 'JSON inválido: ' . json_last_error_msg()
            ];
        }
        
        // Validar configuración
        $validation = self::validateOverrides($config);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => 'Configuración inválida: ' . implode(', ', $validation['errors'])
            ];
        }
        
        // Aplicar configuración
        $success = true;
        foreach ($config as $key => $value) {
            if (strpos($key, '_') === 0) continue; // Skip metadata
            
            $option_name = self::OPTION_PREFIX . $key;
            $success = $success && update_option($option_name, $value);
        }
        
        return [
            'success' => $success,
            'message' => $success ? 'Configuración importada correctamente' : 'Error al importar configuración'
        ];
    }
    
    /**
     * Obtiene estadísticas de uso de overrides
     * 
     * @return array Estadísticas de uso
     */
    public static function getUsageStats(): array {
        $overrides = self::getAllOverrides();
        
        return [
            'destino_overrides' => count($overrides['destino']),
            'formas_envio_overrides' => count($overrides['formas_envio']),
            'metodos_pago_overrides' => count($overrides['metodos_pago']),
            'bultos_overrides' => count($overrides['bultos']),
            'has_peso_default' => $overrides['peso_default'] != self::DEFAULTS['peso_default'],
            'has_tipo_portes_default' => $overrides['tipo_portes_default'] != self::DEFAULTS['tipo_portes_default'],
            'has_pais_default' => $overrides['pais_default'] != self::DEFAULTS['pais_default'],
            'total_overrides' => count($overrides['destino']) + count($overrides['formas_envio']) + 
                               count($overrides['metodos_pago']) + count($overrides['bultos']),
        ];
    }
}
