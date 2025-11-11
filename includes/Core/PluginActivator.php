<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Módulo de Inicialización del Sistema Unificado
 * 
 * Este archivo implementa el sistema de arranque seguro para el subsistema
 * de heartbeat y limpieza automática del plugin Mi Integración API.
 * 
 * Responsabilidades:
 * - Gestionar el ciclo de vida del sistema unificado
 * - Coordinar la inicialización ordenada de componentes
 * - Prevenir inicializaciones múltiples
 * - Manejar la configuración por defecto
 * 
 * @package     MiIntegracionApi\Core
 * @subpackage  Core
 * @since       1.4.0
 * @version     1.4.0
 * @author      Equipo de Desarrollo <dev@miintegracion.com>
 * 
 * @see         HeartbeatWorker Para el sistema de latidos
 * @see         Logger Para el registro de eventos
 * 
 * @example
 * // Uso básico
 * $activator = PluginActivator::getInstance();
 * $activator->initializeUnifiedSystem();
 * 
 * // Obtener estado
 * $status = $activator->getSystemStatus();
 */
/**
 * Gestor de Inicialización del Sistema Unificado (Singleton)
 * 
 * Esta clase implementa el patrón Singleton para garantizar una única instancia
 * que gestiona el ciclo de vida del sistema de heartbeat y limpieza.
 * 
 * Características principales:
 * - Inicialización bajo demanda (lazy initialization)
 * - Prevención de cargas duplicadas
 * - Gestión de dependencias
 * - Sistema de logging integrado
 * 
 * @package     MiIntegracionApi\Core
 * @since       1.4.0
 * @version     1.4.0
 * 
 * @method static PluginActivator getInstance() Obtiene la instancia única
 * 
 * @property-read bool $system_initialized Indica si el sistema está inicializado
 * @property-read HeartbeatWorker|null $heartbeatWorker Instancia del trabajador de latidos
 * 
 * @example
 * // Uso recomendado
 * $activator = PluginActivator::getInstance();
 * if (!$activator->isSystemInitialized()) {
 *     $activator->initializeUnifiedSystem();
 * }
 */
class PluginActivator
{
    /**
     * Instancia única de la clase (patrón Singleton)
     * 
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Instancia del logger para registro de eventos
     * 
     * @var Logger
     */
    private Logger $logger;

    /**
     * Instancia del trabajador de latidos
     * 
     * @var HeartbeatWorker|null
     */
    private ?HeartbeatWorker $heartbeatWorker = null;

    /**
     * Indica si el sistema ha sido inicializado
     * 
     * @var bool
     */
    private bool $system_initialized = false;
    
    /**
     * Constructor privado (Singleton)
     * 
     * Inicializa las dependencias básicas del activador.
     * El acceso a la instancia debe hacerse a través de getInstance().
     * 
     * @since 1.4.0
     * 
     * @see getInstance()
     */
    private function __construct()
    {
        $this->logger = new Logger('plugin-activator');
    }
    
    /**
     * Obtiene la instancia única de la clase (Singleton)
     * 
     * Este método sigue el patrón Singleton para garantizar que solo exista
     * una instancia de PluginActivator en toda la ejecución del plugin.
     * 
     * @since 1.4.0
     * 
     * @return self Instancia única de PluginActivator
     * 
     * @example
     * // Obtener la instancia
     * $activator = PluginActivator::getInstance();
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inicializa el sistema unificado de heartbeat y limpieza
     * 
     * Este método es el punto de entrada principal para iniciar todos los componentes
     * del sistema unificado. Se encarga de:
     * - Verificar si el sistema ya está inicializado
     * - Comprobar configuraciones necesarias
     * - Inicializar componentes en el orden correcto
     * - Manejar errores durante la inicialización
     * 
     * SIDE EFFECTS:
     * - Configura opciones por defecto si no existen
     * - Inicia el HeartbeatWorker en segundo plano
     * - Modifica el estado interno system_initialized
     * - Escribe en el log de eventos
     * 
     * @since 1.4.0
     * 
     * @return void
     * 
     * @throws \RuntimeException Si ocurre un error durante la inicialización
     * 
     * @example
     * // Inicializar el sistema
     * $activator = PluginActivator::getInstance();
     * $activator->initializeUnifiedSystem();
     * 
     * // Verificar si se inicializó correctamente
     * if ($activator->isSystemInitialized()) {
     *     echo 'Sistema listo';
     * }
     */
    public function initializeUnifiedSystem(): void
    {
        if ($this->system_initialized) {
            $this->logger->debug('Sistema unificado ya inicializado');
            return;
        }
        
        try {
            $this->logger->info('Inicializando sistema unificado de heartbeat y limpieza');
            
            // 1. Verificar que el sistema esté habilitado
            if (!$this->isUnifiedSystemEnabled()) {
                $this->logger->info('Sistema unificado deshabilitado por configuración');
                return;
            }
            
            // 2. Inicializar configuración por defecto si es necesario
            $this->ensureDefaultOptions();
            
            // 3. Iniciar el HeartbeatWorker en background
            $this->startHeartbeatWorker();
            
            $this->system_initialized = true;
            $this->logger->info('Sistema unificado inicializado exitosamente');
            
        } catch (\Exception $e) {
            $this->logger->error('Error al inicializar sistema unificado', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw new \RuntimeException('Error al inicializar el sistema unificado', 0, $e);
        }
    }
    
    /**
     * Verifica si el sistema unificado está habilitado
     * 
     * Comprueba la configuración del sistema para determinar si el subsistema
     * unificado de heartbeat y limpieza debe estar activo.
     * 
     * La opción se puede configurar mediante:
     * - Filtro 'mia_unified_system_enabled'
     * - Opción de WordPress 'mia_unified_system_enabled'
     * - Valor por defecto: true
     * 
     * @since 1.4.0
     * 
     * @return bool true si el sistema está habilitado, false en caso contrario
     * 
     * @see ensureDefaultOptions() Donde se establece el valor por defecto
     * 
     * @example
     * if ($activator->isUnifiedSystemEnabled()) {
     *     // Lógica cuando el sistema está habilitado
     * }
     */
    public function isUnifiedSystemEnabled(): bool
    {
        $option = get_option('mia_unified_system_enabled', true);
        return $option === true || $option === '1' || $option === 'true';
    }
    
    /**
     * Asegura que las opciones por defecto estén configuradas
     * 
     * Este método se encarga de inicializar todas las opciones de configuración
     * necesarias para el funcionamiento del sistema unificado, estableciendo
     * valores por defecto si no existen.
     * 
     * Opciones configuradas:
     * - Timeouts de bloqueos (locks)
     * - Intervalos de heartbeat
     * - Configuraciones de limpieza
     * - Opciones de depuración
     * 
     * SIDE EFFECTS:
     * - Crea entradas en la tabla wp_options si no existen
     * - No sobrescribe valores existentes
     * - Escribe en el log de depuración
     * 
     * @since 1.4.0
     * 
     * @return void
     * 
     * @global wpdb $wpdb Objeto de base de datos de WordPress
     * 
     * @see isUnifiedSystemEnabled() Donde se usa una de estas opciones
     * 
     * @example
     * // Forzar verificación de opciones
     * $activator->ensureDefaultOptions();
     */
    private function ensureDefaultOptions(): void
    {
        $default_options = [
            // Timeouts de locks
            'mia_global_lock_timeout' => 600,        // 10 minutos
            'mia_batch_lock_timeout' => 300,         // 5 minutos
            'mia_heartbeat_interval' => 60,          // 1 minuto
            'mia_heartbeat_timeout' => 300,          // 5 minutos
            'mia_lock_cleanup_interval' => 300,      // 5 minutos
            'mia_process_dead_timeout' => 180,       // 3 minutos
            
            // Configuración del sistema
            'mia_unified_system_enabled' => true,
            'mia_automatic_heartbeat' => true,
            'mia_dead_process_detection' => true,
            'mia_proactive_cleanup' => true,
            'mia_heartbeat_logging' => true,
            'mia_cleanup_logging' => true,
            'mia_dead_process_logging' => true,
            'mia_max_cleanup_retries' => 3,
            'mia_cleanup_retry_delay' => 30,
            'mia_heartbeat_failure_threshold' => 3
        ];
        
        foreach ($default_options as $option => $default_value) {
            if (get_option($option) === false) {
                add_option($option, $default_value);
                $this->logger->debug("Opción por defecto añadida: {$option}");
            }
        }
    }

    /**
     * Inicia el HeartbeatWorker en segundo plano
     * 
     * Este método se encarga de programar la ejecución del primer latido del sistema.
     * Utiliza el sistema de eventos programados de WordPress para ejecutar tareas
     * periódicas en segundo plano.
     * 
     * Características:
     * - Evita programaciones duplicadas usando wp_next_scheduled()
     * - Respeta el intervalo configurado en las opciones
     * - Programa la ejecución asíncrona del manejador de eventos
     * 
     * SIDE EFFECTS:
     * - Crea una entrada en la tabla wp_cron
     * - Escribe en el log de eventos
     * - No inicia procesos de forma síncrona
     * 
     * @since 1.4.0
     * 
     * @return void
     * 
     * @uses wp_next_scheduled() Para verificar eventos existentes
     * @uses wp_schedule_single_event() Para programar la ejecución
     * 
     * @see ensureDefaultOptions() Donde se define el intervalo por defecto
     * @see HeartbeatWorker Clase que maneja los latidos
     * 
     * @example
     * // Iniciar el worker de heartbeat
     * $activator->startHeartbeatWorker();
     * 
     * // Verificar si está programado
     * $next_run = wp_next_scheduled('mia_heartbeat_event');
     */
    public function startHeartbeatWorker(): void
    {
        try {
            // Verificar si ya hay un heartbeat programado
            if (wp_next_scheduled('mia_heartbeat_event')) {
                $this->logger->debug('Heartbeat ya está programado');
                return;
            }
            
            // Programar el primer heartbeat
            $interval = (int) get_option('mia_heartbeat_interval', 60);
            $next_run = time() + $interval;
            
            wp_schedule_single_event($next_run, 'mia_heartbeat_event');
            
            $this->logger->info('Heartbeat programado', [
                'next_run' => date('Y-m-d H:i:s', $next_run),
                'interval' => $interval
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('Error al programar el HeartbeatWorker', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
    
    /**
     * Verifica si el heartbeat automático está habilitado
     * 
     * Este método verifica la configuración del sistema para determinar si
     * el sistema de latidos automáticos está activado. El valor puede ser
     * configurado a través de la opción 'mia_automatic_heartbeat'.
     * 
     * La opción acepta varios formatos para compatibilidad:
     * - booleano: true/false
     * - string: '1'/'0', 'true'/'false' (case-insensitive)
     * 
     * @since 1.4.0
     * 
     * @return bool true si el heartbeat automático está habilitado, false en caso contrario
     * 
     * @see ensureDefaultOptions() Donde se establece el valor por defecto (true)
     * @see startHeartbeatWorker() Donde se verifica esta opción
     * 
     * @example
     * if ($activator->isAutomaticHeartbeatEnabled()) {
     *     // El sistema de latidos está activo
     * }
     */
    public function isAutomaticHeartbeatEnabled(): bool
    {
        $option = get_option('mia_automatic_heartbeat', true);
        return $option === true || $option === '1' || $option === 'true';
    }
    
    
    /**
     * Detiene el sistema unificado de manera segura
     * 
     * Este método detiene todos los componentes del sistema unificado,
     * liberando recursos y limpiando estados. Es el método inverso a
     * initializeUnifiedSystem().
     * 
     * Funcionalidades principales:
     * - Detiene el HeartbeatWorker si está activo
     * - Limpia todos los eventos programados de WordPress
     * - Actualiza el estado interno del sistema
     * - Maneja errores durante la detención
     * 
     * SIDE EFFECTS:
     * - Detiene el HeartbeatWorker
     * - Elimina eventos programados de wp_cron
     * - Modifica el estado interno system_initialized a false
     * - Escribe en el log de eventos
     * 
     * @since 1.4.0
     * 
     * @return void
     * 
     * @throws \RuntimeException Si ocurre un error durante la detención
     * 
     * @see initializeUnifiedSystem() Método inverso
     * @see HeartbeatWorker::stop() Para detalles sobre la detención del worker
     * 
     * @example
     * // Detener el sistema unificado
     * $activator = PluginActivator::getInstance();
     * $activator->stopUnifiedSystem();
     * 
     * // Verificar si se detuvo correctamente
     * if (!$activator->isSystemInitialized()) {
     *     echo 'Sistema detenido correctamente';
     * }
     */
    public function stopUnifiedSystem(): void
    {
        try {
            // 1. Detener el HeartbeatWorker si está activo
            if ($this->heartbeatWorker) {
                $this->heartbeatWorker->stop();
                $this->logger->info('HeartbeatWorker detenido');
            }
            
            // 2. Limpiar eventos programados de WordPress
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook('mia_start_heartbeat_worker');
                wp_clear_scheduled_hook('mia_automatic_lock_cleanup');
                $this->logger->debug('Eventos programados eliminados');
            }
            
            // 3. Actualizar estado interno
            $this->system_initialized = false;
            $this->logger->info('Sistema unificado detenido exitosamente');
            
        } catch (\Exception $e) {
            $errorMsg = 'Error al detener el sistema unificado';
            $this->logger->error($errorMsg, [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            throw new \RuntimeException($errorMsg, 0, $e);
        }
    }
    
    /**
     * Obtiene el estado del sistema unificado
     */
    public function getSystemStatus(): array
    {
        $next_cleanup = null;
        if (function_exists('wp_next_scheduled')) {
            $next_cleanup = wp_next_scheduled('mia_automatic_lock_cleanup');
        }
        
        return [
            'system_initialized' => $this->system_initialized,
            'heartbeat_worker_running' => $this->heartbeatWorker ? $this->heartbeatWorker->getStats()['is_running'] : false,
            'unified_system_enabled' => $this->isUnifiedSystemEnabled(),
            'automatic_heartbeat_enabled' => $this->isAutomaticHeartbeatEnabled(),
            'next_cleanup' => $next_cleanup
        ];
    }
}
