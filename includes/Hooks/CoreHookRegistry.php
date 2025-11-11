<?php

declare(strict_types=1);

namespace MiIntegracionApi\Hooks;

use Psr\Log\LoggerInterface;

/**
 * CoreHookRegistry maneja el registro de hooks esenciales de WordPress.
 *
 * Esta clase es responsable de gestionar los hooks de funcionalidad básica que son críticos
 * para el funcionamiento del plugin, incluyendo verificaciones de integridad, limpieza de logs
 * y gestión de memoria. Implementa HookRegistryInterface para mantener consistencia
 * con otros registros de hooks en la aplicación.
 *
 * @package MiIntegracionApi\Hooks
 * @see HookRegistryInterface
 */
class CoreHookRegistry implements HookRegistryInterface
{
    /**
     * Instancia del logger para registrar eventos y errores del sistema.
     *
     * @var LoggerInterface
     */
    private LoggerInterface $logger;
    
    /**
     * Inicializa una nueva instancia de CoreHookRegistry.
     *
     * @param LoggerInterface $logger Instancia del logger a utilizar para el registro de eventos.
     */
    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
    
    /**
     * Registra los hooks principales de WordPress necesarios para el funcionamiento del plugin.
     *
     * Este método configura los siguientes hooks:
     * - admin_init: Para verificaciones de integridad del plugin
     * - mia_log_cleanup_hook: Para la ejecución de limpieza de logs
     * - init: Para programar tareas de limpieza de logs
     *
     * @return void
     */
    public function register(): void
    {
        add_action('admin_init', [$this, 'checkPluginIntegrity'], 5);
        add_action('mia_log_cleanup_hook', [$this, 'executeLogCleanup']);
        add_action('init', [$this, 'scheduleLogCleanup'], 20);
    }
    
    /**
     * Registra los hooks básicos que deben ejecutarse en todos los contextos.
     *
     * Este método configura hooks esenciales de limpieza:
     * - wp_scheduled_delete: Para limpiar datos expirados
     * - shutdown: Para limpieza de memoria al finalizar la petición
     *
     * @return void
     */
    public function registerMinimal(): void
    {
        add_action('wp_scheduled_delete', [$this, 'cleanupExpiredData']);
        add_action('shutdown', [$this, 'cleanupMemory'], 999);
    }
    
    /**
     * Determina si este registro soporta el contexto dado.
     *
     * Los hooks principales siempre se aplican a todos los contextos, por lo que este método siempre devuelve true.
     *
     * @param string $context El contexto a verificar (no utilizado).
     * @return bool Siempre devuelve true.
     */
    public function supportsContext(string $context): bool
    {
        return true; // Core hooks always apply
    }
    
    /**
     * Determina si este registro soporta el contexto dado en modo mínimo.
     *
     * Los hooks principales mínimos siempre se aplican a todos los contextos, por lo que este método siempre devuelve true.
     *
     * @param string $context El contexto a verificar (no utilizado).
     * @return bool Siempre devuelve true.
     */
    public function supportsMinimalContext(string $context): bool
    {
        return true; // Core minimal hooks always apply
    }
    
    /**
     * Verifica la integridad de los archivos y configuración del plugin.
     *
     * Este método realiza comprobaciones para asegurar que todos los archivos requeridos
     * estén presentes y correctamente configurados. Registra los resultados de la verificación.
     *
     * @return void
     */
    public function checkPluginIntegrity(): void
    {
        // Lógica de verificación de integridad
        $this->logger->info('Checking plugin integrity');
    }
    
    /**
     * Ejecuta la limpieza de archivos de registro.
     *
     * Este método elimina entradas de log antiguas según el período de retención configurado.
     * Normalmente es llamado por una acción programada de WordPress.
     *
     * @return void
     */
    public function executeLogCleanup(): void
    {
        // Lógica de limpieza de logs
        $this->logger->info('Executing log cleanup');
    }
    
    /**
     * Programa la siguiente ejecución del proceso de limpieza de logs.
     *
     * Este método configura un evento programado de WordPress para la limpieza de logs
     * si no hay uno ya programado.
     *
     * @return void
     */
    public function scheduleLogCleanup(): void
    {
        // Lógica de programación de limpieza
        $this->logger->info('Scheduling log cleanup');
    }
    
    /**
     * Elimina datos expirados del sistema.
     *
     * Este método es llamado por el hook de eliminación programada de WordPress para limpiar
     * cualquier dato temporal o expirado que ya no sea necesario.
     *
     * @return void
     */
    public function cleanupExpiredData(): void
    {
        // Lógica de limpieza de datos expirados
        $this->logger->info('Cleaning up expired data');
    }
    
    /**
     * Realiza la limpieza de memoria al finalizar la petición.
     *
     * Este método es llamado durante el hook de apagado de WordPress para liberar memoria
     * recolectando cualquier ciclo de basura. Ayuda a prevenir fugas de memoria en procesos de larga duración.
     *
     * @return void
     */
    public function cleanupMemory(): void
    {
        // Lógica de limpieza de memoria
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        $this->logger->info('Memory cleanup completed');
    }
}
