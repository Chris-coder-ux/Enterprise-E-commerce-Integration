<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor de reintentos para operaciones de sincronización
 *
 * Esta clase proporciona un sistema robusto de reintentos con políticas configurables
 * para manejar fallos temporales en operaciones de sincronización. Implementa
 * estrategias avanzadas de backoff exponencial con jitter para evitar la saturación
 * de servicios durante interrupciones.
 *
 * CARACTERÍSTICAS PRINCIPALES:
 * - Múltiples estrategias de reintento configurables
 * - Soporte para políticas específicas por tipo de operación/error
 * - Backoff exponencial con jitter aleatorio
 * - Métricas y estadísticas de reintentos
 * - Integración con el sistema de logging
 *
 * @package    MiIntegracionApi\Core
 * @subpackage Retry
 * @category   Core
 * @author     Equipo de Desarrollo <soporte@verialerp.com>
 * @license    GPL-2.0+ http://www.gnu.org/licenses/gpl-2.0.txt
 * @link       https://www.verialerp.com
 * @since      1.5.0
 * @version    1.2.0
 */
class RetryManager
{
    /**
     * Instancia del logger para registrar eventos del sistema
     *
     * @var Logger
     * @since 1.5.0
     */
    private Logger $logger;

    /**
     * Contador de reintentos por operación
     *
     * @var array<string, int>
     * @since 1.5.0
     */
    private array $retryCounts = [];

    /**
     * Gestor de políticas de reintento
     *
     * @var RetryPolicyManager
     * @since 1.5.0
     */
    private RetryPolicyManager $policy_manager;

    /**
     * Constructor de la clase
     *
     * Inicializa las dependencias necesarias para el gestor de reintentos.
     * Crea una nueva instancia del logger y del gestor de políticas.
     *
     * @since 1.5.0
     */
    public function __construct()
    {
        $this->logger = new Logger('retry_manager');
        $this->policy_manager = new RetryPolicyManager();
    }

    /**
     * Ejecuta una operación con reintentos inteligentes
     *
     * Este método ejecuta la operación proporcionada y maneja automáticamente
     * los reintentos según la política configurada para el tipo de operación.
     * Implementa backoff exponencial con jitter para evitar saturación.
     *
     * @param callable $operation Operación a ejecutar (debe devolver mixed)
     * @param string $operationId Identificador único para rastrear la operación
     * @param array<string, mixed> $context Contexto adicional para logging y políticas
     * @param string $operationType Tipo de operación (ej: 'api_calls', 'database_operations')
     * @return mixed Resultado de la operación si tiene éxito
     * @throws SyncError Si la operación falla después de los reintentos configurados
     * @since 1.5.0
     *
     * @example
     * $result = $retryManager->executeWithRetry(
     *     function() use ($api, $params) {
     *         return $api->call('endpoint', $params);
     *     },
     *     'api_endpoint_call',
     *     ['params' => $params],
     *     'api_calls'
     * );
     */
    public function executeWithRetry(callable $operation, string $operationId, array $context = [], string $operationType = 'api_calls'): mixed
    {
        // Verificar si el sistema de reintentos está habilitado
        if (!$this->policy_manager->isEnabled()) {
            try {
                return $operation();
            } catch (SyncError $e) {
                throw $e;
            }
        }

        $attempt = 1;
        $lastError = null;
        
        // Obtener política de reintentos para el tipo de operación
        $policy = $this->policy_manager->getOperationPolicy($operationType, $context);
        $maxAttempts = $policy['max_attempts'];

        if ($maxAttempts <= 0) {
            // No reintentos configurados, ejecutar una sola vez
            try {
                return $operation();
            } catch (SyncError $e) {
                throw $e;
            }
        }

        while ($attempt <= $maxAttempts) {
            try {
                $result = $operation();
                $this->resetRetryCount($operationId);
                return $result;

            } catch (SyncError $e) {
                $lastError = $e;

                if (!$e->isRetryable()) {
                    $this->logger->error(
                        "Error no reintentable en operación {$operationId}",
                        array_merge($context, [
                            'error' => $e->getMessage(),
                            'code' => $e->getCode(),
                            'attempt' => $attempt,
                            'operation_type' => $operationType
                        ])
                    );
                    throw $e;
                }

                $this->incrementRetryCount($operationId);
                
                // Calcular retraso usando política inteligente
                $delay = $this->calculateIntelligentDelay($attempt, $e, $policy);

                $this->logger->warning(
                    "Reintentando operación {$operationId}",
                    array_merge($context, [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'next_attempt' => $delay,
                        'retry_count' => $this->getRetryCount($operationId),
                        'operation_type' => $operationType,
                        'policy' => $policy
                    ])
                );

                sleep($delay);
                $attempt++;
            }
        }

        $this->logger->error(
            "Operación {$operationId} falló después de {$maxAttempts} intentos",
            array_merge($context, [
                'last_error' => $lastError?->getMessage(),
                'last_error_code' => $lastError?->getCode(),
                'retry_count' => $this->getRetryCount($operationId),
                'operation_type' => $operationType,
                'policy' => $policy
            ])
        );

        throw $lastError ?? new SyncError(
            "Operación {$operationId} falló después de {$maxAttempts} intentos",
            SyncError::API_ERROR,
            $context
        );
    }

    /**
     * Ejecuta una operación con reintentos basados en el tipo de error
     *
     * Similar a executeWithRetry pero utiliza políticas específicas basadas
     * en el tipo de error en lugar del tipo de operación. Útil cuando diferentes
     * errores requieren diferentes estrategias de reintento.
     *
     * @param callable $operation Operación a ejecutar (debe devolver mixed)
     * @param string $operationId Identificador único para rastrear la operación
     * @param array<string, mixed> $context Contexto adicional para logging y políticas
     * @param string $errorType Tipo de error esperado (ej: 'server_error', 'timeout')
     * @return mixed Resultado de la operación si tiene éxito
     * @throws SyncError Si la operación falla después de los reintentos configurados
     * @since 1.5.0
     *
     * @example
     * try {
     *     $result = $retryManager->executeWithErrorBasedRetry(
     *         function() use ($service) { return $service->fetchData(); },
     *         'fetch_data',
     *         [],
     *         'server_error'
     *     );
     * } catch (SyncError $e) {
     *     // Manejar error después de reintentos
     * }
     */
    public function executeWithErrorBasedRetry(callable $operation, string $operationId, array $context = [], string $errorType = 'server_error'): mixed
    {
        // Verificar si el sistema de reintentos está habilitado
        if (!$this->policy_manager->isEnabled()) {
            try {
                return $operation();
            } catch (SyncError $e) {
                throw $e;
            }
        }

        $attempt = 1;
        $lastError = null;
        
        // Obtener política de reintentos para el tipo de error
        $policy = $this->policy_manager->getErrorPolicy($errorType, $context);
        $maxAttempts = $policy['max_attempts'];

        if ($maxAttempts <= 0) {
            // No reintentos configurados, ejecutar una sola vez
            try {
                return $operation();
            } catch (SyncError $e) {
                throw $e;
            }
        }

        while ($attempt <= $maxAttempts) {
            try {
                $result = $operation();
                $this->resetRetryCount($operationId);
                return $result;

            } catch (SyncError $e) {
                $lastError = $e;

                if (!$e->isRetryable()) {
                    $this->logger->error(
                        "Error no reintentable en operación {$operationId}",
                        array_merge($context, [
                            'error' => $e->getMessage(),
                            'code' => $e->getCode(),
                            'attempt' => $attempt,
                            'error_type' => $errorType
                        ])
                    );
                    throw $e;
                }

                $this->incrementRetryCount($operationId);
                
                // Calcular retraso usando política inteligente
                $delay = $this->calculateIntelligentDelay($attempt, $e, $policy);

                $this->logger->warning(
                    "Reintentando operación {$operationId} por error tipo '{$errorType}'",
                    array_merge($context, [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'next_attempt' => $delay,
                        'retry_count' => $this->getRetryCount($operationId),
                        'error_type' => $errorType,
                        'policy' => $policy
                    ])
                );

                sleep($delay);
                $attempt++;
            }
        }

        $this->logger->error(
            "Operación {$operationId} falló después de {$maxAttempts} intentos por error tipo '{$errorType}'",
            array_merge($context, [
                'last_error' => $lastError?->getMessage(),
                'last_error_code' => $lastError?->getCode(),
                'retry_count' => $this->getRetryCount($operationId),
                'error_type' => $errorType,
                'policy' => $policy
            ])
        );

        throw $lastError ?? new SyncError(
            "Operación {$operationId} falló después de {$maxAttempts} intentos por error tipo '{$errorType}'",
            SyncError::API_ERROR,
            $context
        );
    }

    /**
     * Calcula el retraso para el siguiente reintento usando política inteligente
     *
     * Aplica un algoritmo de backoff exponencial con jitter aleatorio para calcular
     * el tiempo de espera antes del próximo intento. Respeta los límites configurados
     * tanto en la política como en la configuración global del sistema.
     *
     * @param int $attempt Número de intento actual (comenzando en 1)
     * @param SyncError $error Error que desencadenó el reintento
     * @param array{
     *     base_delay?: int,
     *     backoff_factor?: float,
     *     max_delay?: int,
     *     jitter_enabled?: bool,
     *     jitter_max_ms?: int
     * } $policy Configuración de la política de reintentos
     * @return int Retraso en segundos hasta el próximo intento
     * @since 1.5.0
     * @access private
     */
    private function calculateIntelligentDelay(int $attempt, SyncError $error, array $policy): int
    {
        // Obtener configuración de la política
        $baseDelay = $policy['base_delay'] ?? 2;
        $backoffFactor = $policy['backoff_factor'] ?? 2.0;
        $maxDelay = $policy['max_delay'] ?? 30;
        $jitterEnabled = $policy['jitter_enabled'] ?? true;
        $jitterMaxMs = $policy['jitter_max_ms'] ?? 1000;

        // Calcular retraso base con backoff exponencial
        $delay = $baseDelay * ($backoffFactor ** ($attempt - 1));

        // Aplicar jitter si está habilitado
        if ($jitterEnabled) {
            $jitterSeconds = rand(0, $jitterMaxMs) / 1000;
            $delay += $jitterSeconds;
        }

        // Aplicar límite máximo
        $delay = min($delay, $maxDelay);

        // Aplicar límite global desde configuración
        $globalMaxDelay = (int) get_option('mia_retry_max_delay', 30);
        $delay = min($delay, $globalMaxDelay);

        return (int) $delay;
    }

    /**
     * Calcula el retraso para el siguiente reintento (método legacy)
     *
     * Método mantenido por compatibilidad con versiones anteriores.
     * Implementa una versión más simple del cálculo de retraso sin soporte
     * para políticas avanzadas.
     *
     * @param int $attempt Número de intento actual (comenzando en 1)
     * @param int $baseDelay Retraso base en segundos
     * @return int Retraso en segundos hasta el próximo intento
     * @deprecated 1.5.0 Usar calculateIntelligentDelay() en su lugar
     * @codeCoverageIgnore
     * @see RetryManager::calculateIntelligentDelay() Método de reemplazo
     */
    private function calculateDelay(int $attempt, int $baseDelay): int
    {
        // Implementación de exponential backoff con jitter (legacy)
        $maxDelay = (int) get_option('mia_retry_max_delay', 30);
        $delay = min(
            $baseDelay * (2 ** ($attempt - 1)) + rand(0, 1000) / 1000,
            $maxDelay
        );

        return (int) $delay;
    }

    /**
     * Incrementa el contador de reintentos para una operación específica
     *
     * Actualiza el contador interno de reintentos para la operación identificada.
     * Si es la primera vez que se llama para esta operación, inicializa el contador.
     *
     * @param string $operationId Identificador único de la operación
     * @return void
     * @since 1.5.0
     * @access private
     */
    private function incrementRetryCount(string $operationId): void
    {
        if (!isset($this->retryCounts[$operationId])) {
            $this->retryCounts[$operationId] = 0;
        }
        $this->retryCounts[$operationId]++;
    }

    /**
     * Obtiene el número de reintentos realizados para una operación
     *
     * @param string $operationId Identificador único de la operación
     * @return int Número de reintentos realizados (0 si es el primer intento)
     * @since 1.5.0
     * @access private
     */
    private function getRetryCount(string $operationId): int
    {
        return $this->retryCounts[$operationId] ?? 0;
    }

    /**
     * Reinicia el contador de reintentos para una operación específica
     *
     * Elimina el registro de reintentos para la operación indicada, permitiendo
     * que futuros reintentos comiencen desde cero.
     *
     * @param string $operationId Identificador único de la operación
     * @return void
     * @since 1.5.0
     * @access private
     */
    private function resetRetryCount(string $operationId): void
    {
        unset($this->retryCounts[$operationId]);
    }

    /**
     * Obtiene estadísticas detalladas de los reintentos realizados
     *
     * Proporciona información agregada sobre todas las operaciones con reintentos,
     * incluyendo recuentos totales, tasas de éxito y configuración actual.
     *
     * @return array{
     *     retry_counts: array<string, int>,
     *     total_operations: int,
     *     total_retries: int,
     *     system_enabled: bool,
     *     policies: array,
     *     operations?: array<string, array{retry_count: int, success_rate: string}>
     * } Estadísticas detalladas de reintentos
     * @since 1.5.0
     *
     * @example
     * $stats = $retryManager->getRetryStats();
     * echo "Operaciones con reintentos: " . $stats['total_operations'];
     * echo "Reintentos totales: " . $stats['total_retries'];
     */
    public function getRetryStats(): array
    {
        $stats = [
            'retry_counts' => $this->retryCounts,
            'total_operations' => count($this->retryCounts),
            'total_retries' => array_sum($this->retryCounts),
            'system_enabled' => $this->policy_manager->isEnabled(),
            'policies' => $this->policy_manager->getAllPolicies()
        ];

        // Calcular estadísticas por operación
        foreach ($this->retryCounts as $operationId => $retryCount) {
            $stats['operations'][$operationId] = [
                'retry_count' => $retryCount,
                'success_rate' => $retryCount > 0 ? 'retried' : 'success_first_try'
            ];
        }

        return $stats;
    }

    /**
     * Limpia todas las estadísticas de reintentos
     *
     * Restablece todos los contadores internos de reintentos a cero.
     * Principalmente útil para pruebas unitarias o después de reiniciar el sistema.
     *
     * @return void
     * @since 1.5.0
     *
     * @example
     * // En una prueba PHPUnit
     * public function testRetryLogic() {
     *     $manager = new RetryManager();
     *     // Ejecutar pruebas...
     *     $manager->clearRetryStats(); // Limpiar para la siguiente prueba
     * }
     */
    public function clearRetryStats(): void
    {
        $this->retryCounts = [];
        $this->logger->debug('Estadísticas de reintentos limpiadas');
    }
} 