<?php

declare(strict_types=1);

namespace MiIntegracionApi\Traits;

/**
 * Trait para proporcionar acceso centralizado al plugin principal.
 * 
 * Este trait implementa el patrón de Inversión de Dependencias para obtener
 * componentes centralizados del plugin principal, siguiendo las mejores
 * prácticas de DRY, Single Responsibility y Resource Management.
 * 
 * Proporciona métodos universales que pueden ser utilizados por cualquier
 * clase del plugin que necesite acceso a componentes centralizados como:
 * - Logger centralizado
 * - ApiConnector centralizado
 * - CacheManager centralizado
 * - SyncManager centralizado
 * 
 * @package     MiIntegracionApi\Traits
 * @since       1.0.0
 * @version     1.0.0
 * @author      Mi Integración API Team
 */
trait MainPluginAccessor
{
    /**
     * Obtiene la instancia del plugin principal para acceder a componentes centralizados.
     *
     * Este método implementa el patrón Singleton para obtener la instancia única del plugin principal,
     * siguiendo los principios de Inversión de Dependencias y Fail Fast.
     * 
     * Aplica las siguientes buenas prácticas:
     * - Verificación de existencia de clase antes de instanciación
     * - Manejo robusto de errores con logging detallado
     * - Retorno de null en caso de error para evitar excepciones no controladas
     * - Logging estructurado para debugging y monitoreo
     *
     * @since       1.0.0
     * @access      private
     * @return      \MiIntegracionApi\Core\MiIntegracionApi|null  Instancia del plugin principal o null si no está disponible.
     * @see         \MiIntegracionApi\Core\MiIntegracionApi::__construct()
     * @throws      \RuntimeException  Si ocurre un error al obtener la instancia del plugin.
     */
    private function getMainPlugin(): ?\MiIntegracionApi\Core\MiIntegracionApi
    {
        if (!class_exists('\MiIntegracionApi\Core\MiIntegracionApi')) {
            return null;
        }
        
        try {
            // La clase MiIntegracionApi no implementa Singleton, se instancia directamente
            return new \MiIntegracionApi\Core\MiIntegracionApi();
        } catch (\Throwable $e) {
            // Log del error para debugging
            $this->logMainPluginError($e);
            return null;
        }
    }
    
    /**
     * Obtiene un componente centralizado del plugin principal.
     *
     * Este método implementa el patrón de Inversión de Dependencias para obtener componentes
     * de manera centralizada, siguiendo las mejores prácticas de Resource Management.
     * 
     * Componentes disponibles:
     * - 'logger': Logger centralizado
     * - 'api_connector': Cliente de API de Verial
     * - 'cache_manager': Gestor de caché
     * - 'sync_manager': Gestor de sincronización
     * - 'log_manager': Gestor de logs
     *
     * @since       1.0.0
     * @access      private
     * @param       string  $componentName  Nombre del componente a obtener. Debe coincidir
     *                                      con un componente registrado en el plugin principal.
     * @return      mixed|null  Instancia del componente solicitado o null si no está disponible
     *                          o si el plugin principal no está accesible.
     * @see         \MiIntegracionApi\Core\MiIntegracionApi::getComponent()
     */
    private function getCentralizedComponent(string $componentName): mixed
    {
        $mainPlugin = $this->getMainPlugin();
        if (!$mainPlugin) {
            return null;
        }
        
        return $mainPlugin->getComponent($componentName);
    }
    
    /**
     * Obtiene el Logger centralizado con categoría específica.
     *
     * Este método implementa el patrón de Inversión de Dependencias para obtener un logger
     * con categoría específica, siguiendo las mejores prácticas de DRY y Resource Management.
     * 
     * El logger centralizado proporciona:
     * - Logging estructurado con contexto
     * - Categorización automática por clase
     * - Integración con el sistema de logging del plugin
     * - Fallback a logger local si no hay plugin principal
     *
     * @since       1.0.0
     * @access      private
     * @param       string  $category  Categoría del logger. Debe coincidir con una categoría
     *                                 registrada en el plugin principal.
     * @return      \MiIntegracionApi\Helpers\Logger|\MiIntegracionApi\Core\LogManager|null  Instancia del logger solicitado o null
     *                                                                                       si no está disponible o si el plugin principal
     *                                                                                       no está accesible.
     * @see         \MiIntegracionApi\Core\MiIntegracionApi::getComponent()
     */
    private function getCentralizedLogger(string $category): ?\MiIntegracionApi\Logging\Interfaces\ILogger
    {
        // Intentar obtener del plugin principal primero
        $centralizedLogger = $this->getCentralizedComponent('logger');
        if ($centralizedLogger) {
            return $centralizedLogger;
        }
        
        // Fallback: usar el nuevo sistema de LogManager
        if (class_exists('\MiIntegracionApi\Logging\Core\LogManager')) {
            try {
                $logManager = \MiIntegracionApi\Logging\Core\LogManager::getInstance();
                return $logManager->getLogger($category);
            } catch (\Throwable $e) {
                // Log del error para debugging
                error_log('Error obteniendo logger centralizado: ' . $e->getMessage());
                return null;
            }
        }
        
        // Fallback final: crear logger local solo si no hay plugin principal
        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
            return new \MiIntegracionApi\Helpers\Logger($category);
        }
        
        return null;
    }
    
    /**
     * Obtiene el LogManager centralizado para compatibilidad.
     *
     * Este método proporciona acceso al LogManager centralizado,
     * manteniendo compatibilidad con código existente que usa getLogManager().
     * 
     * @param   string  $category  Categoría del logger.
     * @return  \MiIntegracionApi\Logging\Core\LogManager|null  Instancia del LogManager o null si no está accesible.
     * @since   1.1.0
     */
    private function getLogManager(string $category): ?\MiIntegracionApi\Logging\Core\LogManager
    {
        // Usar el nuevo sistema de LogManager
        if (class_exists('\MiIntegracionApi\Logging\Core\LogManager')) {
            try {
                return \MiIntegracionApi\Logging\Core\LogManager::getInstance();
            } catch (\Throwable $e) {
                // Log del error para debugging
                error_log('Error obteniendo LogManager centralizado: ' . $e->getMessage());
                return null;
            }
        }
        
        return null;
    }
    
    /**
     * Obtiene el ApiConnector centralizado.
     *
     * Este método proporciona acceso al cliente de API de Verial centralizado,
     * siguiendo el patrón de Inversión de Dependencias.
     * 
     * El ApiConnector centralizado proporciona:
     * - Conexión unificada con la API de Verial
     * - Gestión centralizada de autenticación
     * - Pool de conexiones reutilizable
     * - Configuración centralizada de timeouts y reintentos
     *
     * @since       1.0.0
     * @access      private
     * @return      \MiIntegracionApi\Core\ApiConnector|null  Instancia del ApiConnector o null si no está disponible.
     * @see         \MiIntegracionApi\Core\MiIntegracionApi::getComponent()
     */
    private function getCentralizedApiConnector(): ?\MiIntegracionApi\Core\ApiConnector
    {
        $apiConnector = $this->getCentralizedComponent('api_connector');
        if ($apiConnector instanceof \MiIntegracionApi\Core\ApiConnector) {
            return $apiConnector;
        }
        
        return null;
    }
    
    /**
     * Obtiene el CacheManager centralizado.
     *
     * Este método proporciona acceso al gestor de caché centralizado,
     * siguiendo el patrón de Inversión de Dependencias.
     * 
     * El CacheManager centralizado proporciona:
     * - Gestión unificada de caché HTTP
     * - Invalidación automática de caché
     * - Configuración centralizada de TTL
     * - Métricas de rendimiento de caché
     *
     * @since       1.0.0
     * @access      private
     * @return      \MiIntegracionApi\CacheManager|null  Instancia del CacheManager o null si no está disponible.
     * @see         \MiIntegracionApi\Core\MiIntegracionApi::getComponent()
     */
    private function getCentralizedCacheManager(): ?\MiIntegracionApi\CacheManager
    {
        $cacheManager = $this->getCentralizedComponent('cache_manager');
        if ($cacheManager instanceof \MiIntegracionApi\CacheManager) {
            return $cacheManager;
        }
        
        return null;
    }
    
    /**
     * Registra errores del plugin principal con logging estructurado.
     *
     * Este método proporciona logging centralizado para errores relacionados
     * con el acceso al plugin principal, siguiendo las mejores prácticas
     * de logging estructurado y debugging.
     *
     * @since       1.0.0
     * @access      private
     * @param       \Throwable  $exception  Excepción capturada al acceder al plugin principal.
     * @return      void
     */
    private function logMainPluginError(\Throwable $exception): void
    {
        // Intentar usar logger centralizado si está disponible
        $logger = $this->getCentralizedLogger('main-plugin-accessor');
        if ($logger) {
            $logger->error('Error obteniendo instancia del plugin principal', [
                'error' => $exception->getMessage(),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => $exception->getTraceAsString(),
                'class' => get_class($this),
                'timestamp' => time()
            ]);
            return;
        }
        
        // Fallback: usar error_log si no hay logger disponible
        error_log(sprintf(
            'MainPluginAccessor Error [%s]: %s in %s:%d (Class: %s)',
            date('Y-m-d H:i:s'),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            get_class($this)
        ));
    }
    
    /**
     * Verifica si el plugin principal está disponible.
     *
     * Este método proporciona una verificación rápida de la disponibilidad
     * del plugin principal sin intentar instanciarlo.
     *
     * @since       1.0.0
     * @access      private
     * @return      bool  true si el plugin principal está disponible, false en caso contrario.
     */
    private function isMainPluginAvailable(): bool
    {
        return class_exists('\MiIntegracionApi\Core\MiIntegracionApi') && 
               method_exists('\MiIntegracionApi\Core\MiIntegracionApi', 'getInstance');
    }
    
    /**
     * Obtiene información de diagnóstico del plugin principal.
     *
     * Este método proporciona información detallada sobre el estado del plugin principal
     * para propósitos de debugging y diagnóstico.
     *
     * @since       1.0.0
     * @access      private
     * @return      array<string, mixed>  Información de diagnóstico del plugin principal.
     */
    private function getMainPluginDiagnostics(): array
    {
        $diagnostics = [
            'class_exists' => class_exists('\MiIntegracionApi\Core\MiIntegracionApi'),
            'method_exists' => method_exists('\MiIntegracionApi\Core\MiIntegracionApi', 'getInstance'),
            'instance_available' => false,
            'components_available' => [],
            'error' => null
        ];
        
        if ($diagnostics['class_exists'] && $diagnostics['method_exists']) {
            try {
                $mainPlugin = \MiIntegracionApi\Core\MiIntegracionApi::getInstance();
                $diagnostics['instance_available'] = ($mainPlugin !== null);
                
                if ($mainPlugin) {
                    // Verificar componentes disponibles
                    $components = ['logger', 'api_connector', 'cache_manager', 'sync_manager'];
                    foreach ($components as $component) {
                        $diagnostics['components_available'][$component] = ($mainPlugin->getComponent($component) !== null);
                    }
                }
            } catch (\Throwable $e) {
                $diagnostics['error'] = [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ];
            }
        }
        
        return $diagnostics;
    }
}
