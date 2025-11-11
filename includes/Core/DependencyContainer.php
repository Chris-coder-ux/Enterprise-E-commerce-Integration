<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use Psr\Container\ContainerInterface;
use MiIntegracionApi\Core\LogManager;
use MiIntegracionApi\CacheManager;
use MiIntegracionApi\Security;
use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Hooks\HooksInit;
use MiIntegracionApi\Hooks\CoreHookRegistry;
use MiIntegracionApi\Hooks\AdminHookRegistry;
use MiIntegracionApi\Hooks\WooCommerceHookRegistry;
use MiIntegracionApi\Core\ContextDetector;
use MiIntegracionApi\Admin\TemplateRenderer;
use Psr\Log\LoggerInterface;

class DependencyContainer implements ContainerInterface
{
    private const DEFAULT_BATCH_SIZE = 50;
    private const DEFAULT_SYNC_TIMEOUT = 30;
    private const MEMORY_WARNING_THRESHOLD = 80;
    private const SYNC_STUCK_TIMEOUT = 30;
    
    private static ?self $instance = null;
    private array $services = [];
    private array $config;
    
    private function __construct()
    {
        $this->loadConfiguration();
        $this->registerCoreServices();
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    public static function reset(): void
    {
        self::$instance = null;
    }
    
    private function loadConfiguration(): void
    {
        $this->config = [
            'batch_size' => self::getConsistentBatchSizeFromSyncManager(),
            'sync_timeout' => get_option('mia_sync_timeout', self::DEFAULT_SYNC_TIMEOUT),
            'memory_warning_threshold' => get_option('mia_memory_warning_threshold', self::MEMORY_WARNING_THRESHOLD),
            'sync_stuck_timeout' => get_option('mia_sync_stuck_timeout', self::SYNC_STUCK_TIMEOUT),
        ];
    }
    
    private function registerCoreServices(): void
    {
        // 1. SERVICIOS BÁSICOS (sin dependencias)
        $this->services['config'] = $this->config;
        
        $loggerInstance = LogManager::getInstance();
        
        $this->services['logger'] = $loggerInstance;
        
        if (interface_exists('Psr\Log\LoggerInterface')) {
            $this->services[LoggerInterface::class] = $loggerInstance;
        }
        
        $this->services['MiIntegracionApi\Logging\Interfaces\ILogger'] = $loggerInstance;
        
        $this->services[ContextDetector::class] = function() {
            return ContextDetector::getInstance();
        };
        
        // 2. SERVICIOS DE ADMINISTRACIÓN (dependen solo de LoggerInterface)
        
        
        $this->services[TemplateRenderer::class] = function($container) {
            $templatesPath = (defined('MIA_PLUGIN_DIR') ? constant('MIA_PLUGIN_DIR') : '') . 'templates/admin/';
            if (!is_dir($templatesPath)) {
                $templatesPath = (defined('MIA_PLUGIN_DIR') ? constant('MIA_PLUGIN_DIR') : '') . 'templates/';
            }
            return new TemplateRenderer(
                $container->get('logger'),
                $templatesPath
            );
        };
        
        // 3. SERVICIOS DE HOOKS (dependen de servicios de administración)
        $this->services[AdminHookRegistry::class] = function($container) {
            return new AdminHookRegistry($container);
        };
        
        $this->services[HooksInit::class] = function($container) {
            return new HooksInit(
                $container->get('logger'),
                $container->get(ContextDetector::class),
                [
                    new CoreHookRegistry($container->get('logger')),
                    $container->get(AdminHookRegistry::class),
                    new WooCommerceHookRegistry(),
                ]
            );
        };
    }
    
    public function get(string $id)
    {
        if (!$this->has($id)) {
            throw new ServiceNotFoundException("Service {$id} not found");
        }
        
        // Lazy initialization for heavy services
        if (is_callable($this->services[$id])) {
            $this->services[$id] = $this->services[$id]($this);
        }
        
        return $this->services[$id];
    }
    
    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
    
    public function register(string $id, $service): void
    {
        $this->services[$id] = $service;
    }

    /**
     * CENTRALIZACIÓN: Obtiene batch size consistente desde Sync_Manager
     * Elimina acceso directo a fuentes externas para batch size
     * 
     * @return int Batch size consistente
     */
    private static function getConsistentBatchSizeFromSyncManager(): int {
        try {
            // Intentar obtener desde Sync_Manager si está disponible
            if (class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
                $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
                if (method_exists($sync_manager, 'getConsistentBatchSize')) {
                    return $sync_manager->getConsistentBatchSize('default');
                }
            }
            
            // Fallback a BatchSizeHelper si Sync_Manager no está disponible
            if (class_exists('\MiIntegracionApi\Helpers\BatchSizeHelper')) {
                return \MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize('productos');
            }
            
            // Fallback final a valor por defecto
            return self::DEFAULT_BATCH_SIZE;
            
        } catch (\Throwable $e) {
            // Log del error y fallback seguro
            error_log("DependencyContainer: Error obteniendo batch size consistente: " . $e->getMessage());
            
            // Valor por defecto seguro
            return self::DEFAULT_BATCH_SIZE;
        }
    }
}
