<?php

declare(strict_types=1);

/**
 * Gestor centralizado de configuraciones del plugin
 *
 * Esta clase maneja todas las configuraciones del plugin de forma centralizada,
 * proporcionando métodos para obtener, actualizar y validar opciones.
 * Implementa el patrón Singleton para garantizar una única instancia.
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 * @author Mi Integración API
 */

namespace MiIntegracionApi\Core;

class Config_Manager {
    /**
     * Instancia única de la clase (patrón Singleton)
     * 
     * @var Config_Manager|null
     * @since 1.0.0
     */
    private static $instance = null;

    /**
     * Configuración en caché para mejorar el rendimiento
     * 
     * @var array
     * @since 1.0.0
     */
    private $config_cache = [];

    /**
     * Definiciones de todas las opciones disponibles
     * 
     * @var array
     * @since 1.0.0
     */
    private $option_definitions = [];

    /**
     * Constructor privado para implementar el patrón Singleton
     * 
     * Inicializa las definiciones de opciones al crear la instancia.
     * 
     * @return void
     * @since 1.0.0
     */
    private function __construct() {
        $this->define_options();
    }

    /**
     * Obtiene la instancia única de la clase
     * 
     * Implementa el patrón Singleton para garantizar que solo exista
     * una instancia de Config_Manager en toda la aplicación.
     *
     * @return Config_Manager Instancia única de la clase
     * @since 1.0.0
     */
    public static function get_instance(): Config_Manager {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Define todas las opciones disponibles
     */
    private function define_options(): void {
        $this->option_definitions = [
            // API y conexión
            'mia_url_base' => [
                'default' => 'https://api.verialerp.com/v1',
                'sanitize_callback' => 'esc_url_raw',
                'type' => 'string',
                'group' => 'api',
                'show_in_rest' => false,
            ],
            'mia_numero_sesion' => [
                'default' => '18',
                'sanitize_callback' => 'sanitize_text_field',
                'type' => 'string',
                'group' => 'api',
                'show_in_rest' => false,
            ],
            'mia_clave_api' => [
                'default' => '',
                'sanitize_callback' => 'sanitize_text_field',
                'type' => 'string',
                'group' => 'api',
                'show_in_rest' => false,
            ],
            'mia_debug_mode' => [
                'default' => 'no',
                'sanitize_callback' => 'sanitize_text_field',
                'type' => 'string',
                'group' => 'api',
                'show_in_rest' => true,
            ],
            // Sincronización
            'mia_sync_interval_min' => [
                'default' => 15,
                'sanitize_callback' => 'absint',
                'type' => 'integer',
                'group' => 'sync',
                'show_in_rest' => true,
            ],
           'mia_sync_batch_size' => [
               'default' => 100,
               'sanitize_callback' => 'absint',
               'type' => 'integer',
               'group' => 'sync',
               'show_in_rest' => true,
           ],
           'mia_sync_sku_fields' => [
               'default' => 'ReferenciaBarras,Id,CodigoArticulo',
               'sanitize_callback' => 'sanitize_text_field',
               'type' => 'string',
               'group' => 'sync',
               'show_in_rest' => true,
           ],
        ];

        // Permitir a otros plugins añadir/modificar opciones
        $this->option_definitions = apply_filters('mi_integracion_api_option_definitions', $this->option_definitions);
    }

    /**
     * Obtiene el valor de una configuración específica
     * 
     * Busca la configuración en el caché primero, luego en las opciones
     * unificadas del plugin, y finalmente en opciones individuales para
     * compatibilidad. Aplica sanitización automática si está definida.
     * 
     * @param string $key     Clave de la configuración
     * @param mixed  $default Valor por defecto si no existe
     * @return mixed Valor de la configuración o valor por defecto
     * @since 1.0.0
     */
    public function get(string $key, $default = null) {
        // Verificar caché
        if (isset($this->config_cache[$key])) {
            return $this->config_cache[$key];
        }

        // Obtener opciones unificadas
        $options = get_option('mi_integracion_api_ajustes', []);
        
        // Si la clave existe en las opciones unificadas
        if (isset($options[$key])) {
            $value = $options[$key];
        } else {
            // Intentar obtener de opciones individuales para compatibilidad
            $value = get_option($key, $default);
        }

        // Aplicar sanitización si está definida
        if (isset($this->option_definitions[$key]['sanitize_callback']) && 
            is_callable($this->option_definitions[$key]['sanitize_callback'])) {
            $value = call_user_func($this->option_definitions[$key]['sanitize_callback'], $value);
        }

        // Guardar en caché
        $this->config_cache[$key] = $value;

        return $value;
    }

    /**
     * Actualiza una configuración específica
     *
     * @param string $key Clave de la configuración
     * @param mixed $value Nuevo valor
     * @return bool True si se actualizó correctamente
     */
    public function update(string $key, $value): bool {
        // Obtener opciones actuales
        $options = get_option('mi_integracion_api_ajustes', []);

        // Aplicar sanitización si está definida
        if (isset($this->option_definitions[$key]['sanitize_callback']) && 
            is_callable($this->option_definitions[$key]['sanitize_callback'])) {
            $value = call_user_func($this->option_definitions[$key]['sanitize_callback'], $value);
        }

        // Actualizar opciones
        $options[$key] = $value;
        $result = update_option('mi_integracion_api_ajustes', $options);

        // Actualizar caché
        if ($result) {
            $this->config_cache[$key] = $value;
        }

        return $result;
    }

    /**
     * Obtiene todas las configuraciones
     *
     * @return array Todas las configuraciones
     */
    public function get_all(): array {
        $config = [];
        foreach ($this->option_definitions as $key => $definition) {
            $config[$key] = $this->get($key, $definition['default'] ?? null);
        }
        return $config;
    }

    /**
     * Registra todas las opciones en WordPress
     */
    public function register_settings(): void {
        foreach ($this->option_definitions as $key => $definition) {
            register_setting(
                'mi_integracion_api_settings_group',
                'mi_integracion_api_ajustes',
                [
                    'type' => 'array',
                    'description' => 'Ajustes del plugin Mi Integración API',
                    'sanitize_callback' => [$this, 'sanitize_settings'],
                    'default' => []
                ]
            );
        }
    }

    /**
     * Sanitiza los valores de configuración
     *
     * @param array $input Valores a sanitizar
     * @return array Valores sanitizados
     */
    public function sanitize_settings(array $input): array {
        $sanitized = [];
        foreach ($input as $key => $value) {
            if (isset($this->option_definitions[$key]['sanitize_callback']) && 
                is_callable($this->option_definitions[$key]['sanitize_callback'])) {
                $sanitized[$key] = call_user_func($this->option_definitions[$key]['sanitize_callback'], $value);
            } else {
                $sanitized[$key] = $value;
            }
        }
        return $sanitized;
    }
} 