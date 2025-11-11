<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

class MiIntegracionApi {
    private $connector;
    private $logger;
    private $log_manager;
    private $sync_manager;
    private $cache_manager;
    private $admin_menu;
    private static $instance = null;
    
    /**
     * Obtiene la instancia única de esta clase (patrón Singleton)
     * 
     * @return MiIntegracionApi Instancia única de MiIntegracionApi
     */
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->init();
        }
        return self::$instance;
    }

    public function init(): void {
        try {
            // Inicializar logger si está disponible
            if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
                $this->logger = new \MiIntegracionApi\Helpers\Logger('core');
            }

            // Obtener las opciones de configuración
            $options = get_option('mi_integracion_api_ajustes', array());
            
            // Configurar max retries y timeout
            $max_retries = isset($options['mia_max_retries']) ? intval($options['mia_max_retries']) : 3;
            $retry_delay = isset($options['mia_retry_delay']) ? intval($options['mia_retry_delay']) : 2;
            $timeout = isset($options['mia_timeout']) ? intval($options['mia_timeout']) : 30;

            // Verificar que la clase ApiConnector exista antes de instanciarla
            if (class_exists('\\MiIntegracionApi\\Core\\ApiConnector')) {
                // Usar el patrón singleton para evitar múltiples instancias
                $this->connector = ApiConnector::get_instance($this->logger, $max_retries, $retry_delay, $timeout);
                
                // La configuración se carga automáticamente desde VerialApiConfig
                // No es necesario configurar manualmente la URL y sesión
            } else {
                $this->log_error('Clase ApiConnector no encontrada. Algunas funcionalidades no estarán disponibles.');
            }

            // Inicializar el manejador REST centralizado para registrar todos los endpoints REST (incluyendo sync)
            if (class_exists('\\MiIntegracionApi\\Core\\REST_API_Handler')) {
                \MiIntegracionApi\Core\REST_API_Handler::init();
            } else {
                $this->log_error('Clase REST_API_Handler no encontrada. Los endpoints REST no estarán disponibles.');
            }
            
            // Registrar endpoints legacy (compatibilidad)
            add_action('rest_api_init', [$this, 'register_endpoints']);

            // Inicializar registro de configuraciones
            if (class_exists('\\MiIntegracionApi\\Admin\\SettingsRegistration')) {
                \MiIntegracionApi\Admin\SettingsRegistration::init();
            } else {
                $this->log_error('Clase SettingsRegistration no encontrada. La configuración no estará disponible.');
            }

            // Inicializar menú de administración
            if (class_exists('\\MiIntegracionApi\\Admin\\AdminMenu')) {
                // Eliminados logs innecesarios que generaban spam
                $this->admin_menu = new \MiIntegracionApi\Admin\AdminMenu(
                    "mi-integracion-api",
                    MiIntegracionApi_PLUGIN_URL . "assets/",
                    MiIntegracionApi_PLUGIN_DIR . "templates/admin/"
                );
                $this->admin_menu->init(); // Inicializar el menú de administración
            } else {
                $this->log_error('Clase AdminMenu no encontrada. El menú de administración no estará disponible.');
            }

            // Inicializar assets
            if (class_exists('\\MiIntegracionApi\\Admin\\Assets')) {
                $assets = new \MiIntegracionApi\Admin\Assets();
                $assets->init();
            } else {
                $this->log_error('Clase Assets no encontrada. Los recursos CSS/JS no estarán disponibles.');
            }

            // Inicializar la página de configuración

            // CORRECCIÓN #8: Inicializar gestor de configuración de reintentos inteligente
            if (class_exists('\\MiIntegracionApi\\Admin\\RetrySettingsManager')) {
                $retry_settings = new \MiIntegracionApi\Admin\RetrySettingsManager();
                $retry_settings->init();
            } else {
                $this->log_error('Clase RetrySettingsManager no encontrada. La configuración de reintentos no estará disponible.');
            }

            // CORRECCIÓN #9: Inicializar gestor de monitoreo de memoria mejorado
            if (class_exists('\\MiIntegracionApi\\Admin\\MemoryMonitoringManager')) {
                $memory_monitoring = new \MiIntegracionApi\Admin\MemoryMonitoringManager();
                $memory_monitoring->init();
            } else {
                $this->log_error('Clase MemoryMonitoringManager no encontrada. El monitoreo de memoria no estará disponible.');
            }

            // Inicializar Dashboard de sincronización de pedidos
            if (class_exists('\\MiIntegracionApi\\Admin\\OrderSyncDashboard')) {
                \MiIntegracionApi\Admin\OrderSyncDashboard::get_instance();
            } else {
                $this->log_error('Clase OrderSyncDashboard no encontrada. El dashboard de sincronización de pedidos no estará disponible.');
            }
        } catch (\Throwable $e) {
            $this->log_error('Error en init: ' . $e->getMessage());
        }
    }

    public function register_endpoints(): void {
        if (!isset($this->connector) || !$this->connector) {
            $this->log_error('No se pueden registrar endpoints: Conector API no disponible');
            return;
        }

        try {
            // Registrar endpoints verificando primero la existencia de las clases
            if (class_exists('\\MiIntegracionApi\\Endpoints\\GetPaisesWS')) {
                $paises = new \MiIntegracionApi\Endpoints\GetPaisesWS($this->connector);
                $paises->register_route();
            }

            if (class_exists('\\MiIntegracionApi\\Endpoints\\ProvinciasWS')) {
                $provincias = new \MiIntegracionApi\Endpoints\ProvinciasWS($this->connector);
                $provincias->register_route();
            }

            if (class_exists('\\MiIntegracionApi\\Endpoints\\GetClientesWS')) {
                $clientes = new \MiIntegracionApi\Endpoints\GetClientesWS($this->connector);
                $clientes->register_route();
            }
        } catch (\Throwable $e) {
            $this->log_error('Error al registrar endpoints: ' . $e->getMessage());
        }
    }

    /**
     * Obtiene un componente centralizado
     * Implementa patrón Singleton para componentes centralizados
     * 
     * @param string $component_name Nombre del componente
     * @return mixed|null Componente o null si no está disponible
     */
    public function getComponent(string $component_name)
    {
        switch ($component_name) {
            case 'logger':
                // Usar Logger centralizado (Singleton)
                if ($this->logger === null) {
                    if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
                        $this->logger = new \MiIntegracionApi\Helpers\Logger('core');
                    }
                }
                return $this->logger;
            case 'log_manager':
                // Crear LogManager centralizado (Singleton)
                if (!isset($this->log_manager)) {
                    if (class_exists('\\MiIntegracionApi\\Core\\LogManager')) {
                        $this->log_manager = new \MiIntegracionApi\Core\LogManager('centralized');
                    }
                }
                return $this->log_manager ?? null;
            case 'api_connector':
                return $this->connector;
            case 'sync_manager':
                // Crear Sync_Manager centralizado (Singleton)
                if (!isset($this->sync_manager)) {
                    if (class_exists('\\MiIntegracionApi\\Core\\Sync_Manager')) {
                        $this->sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
                    }
                }
                return $this->sync_manager ?? null;
            case 'cache_manager':
                // Crear Cache Manager centralizado (Singleton)
                if (!isset($this->cache_manager)) {
                    if (class_exists('\\MiIntegracionApi\\Cache\\HTTP_Cache_Manager')) {
                        $this->cache_manager = \MiIntegracionApi\Cache\HTTP_Cache_Manager::class;
                    }
                }
                return $this->cache_manager ?? null;
            case 'admin_menu':
                // Devolver el menú de administración
                return $this->admin_menu ?? null;
            default:
                return null;
        }
    }

    /**
     * Registra un error de forma segura
     *
     * @param string $message El mensaje de error
     * @return void
     */
    private function log_error($message): void {
        // Usar el logger si está disponible
        if (isset($this->logger) && $this->logger) {
            $this->logger->error($message);
            return;
        }
        
        // Fallback a error_log si el logger no está disponible
        error_log('Mi Integración API: ' . $message);
        
        // Si estamos en el admin, mostrar notificación
        if (is_admin() && function_exists('add_action')) {
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('Error en Mi Integración API:', 'mi-integracion-api') . '</strong> ' . esc_html($message);
                echo '</p></div>';
            });
        }
    }
}

// No se detecta uso de Logger::log, solo $this->logger->error y error_log estándar.
