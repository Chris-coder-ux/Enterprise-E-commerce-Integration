<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor centralizado de políticas de reintentos inteligentes
 *
 * Esta clase maneja la configuración y lógica de reintentos para diferentes tipos
 * de errores y operaciones, permitiendo una gestión flexible y personalizable
 * de las estrategias de reintento en toda la aplicación.
 *
 * CARACTERÍSTICAS PRINCIPALES:
 * - Políticas predefinidas para diferentes tipos de errores y operaciones
 * - Sistema de herencia y sobrescritura de políticas
 * - Caché integrado para mejorar el rendimiento
 * - Soporte para configuración dinámica vía opciones de WordPress
 * - Jerarquía clara de configuración (global > tipo de operación/error > personalizado)
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
class RetryPolicyManager
{
    /**
     * Número máximo de reintentos por defecto
     * 
     * @var int
     * @since 1.5.0
     */
    private const DEFAULT_MAX_ATTEMPTS = 3;
    
    /**
     * Retraso base en segundos por defecto
     * 
     * @var int
     * @since 1.5.0
     */
    private const DEFAULT_BASE_DELAY = 2;
    
    /**
     * Retraso máximo en segundos por defecto
     * 
     * @var int
     * @since 1.5.0
     */
    private const DEFAULT_MAX_DELAY = 30;
    
    /**
     * Factor de incremento del retraso por defecto
     * 
     * @var float
     * @since 1.5.0
     */
    private const DEFAULT_BACKOFF_FACTOR = 2.0;
    
    /**
     * Máximo jitter en milisegundos por defecto
     * 
     * @var int
     * @since 1.5.0
     */
    private const DEFAULT_JITTER_MAX_MS = 1000;
    
    /**
     * @var Logger Instancia del logger para registrar eventos
     * @since 1.5.0
     */
    private Logger $logger;
    
    /**
     * Caché de políticas para mejorar el rendimiento
     * 
     * @var array<string, array> Almacena las políticas ya calculadas
     * @since 1.5.0
     */
    private array $config_cache = [];

    /**
     * POLÍTICAS DE REINTENTOS POR TIPO DE ERROR
     * Definen estrategias específicas para diferentes tipos de errores
     */
    private const ERROR_POLICIES = [
        // Errores de red y conectividad (agresivos)
        'network' => [
            'max_attempts' => 5,
            'base_delay' => 1,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'description' => 'Errores de conectividad de red'
        ],
        'timeout' => [
            'max_attempts' => 4,
            'base_delay' => 2,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'description' => 'Timeouts de conexión'
        ],
        'ssl' => [
            'max_attempts' => 3,
            'base_delay' => 3,
            'backoff_factor' => 1.8,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'description' => 'Errores de certificados SSL'
        ],
        
        // Errores del servidor (moderados)
        'server_error' => [
            'max_attempts' => 3,
            'base_delay' => 5,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'description' => 'Errores 5xx del servidor'
        ],
        'rate_limit' => [
            'max_attempts' => 3,
            'base_delay' => 10,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'description' => 'Límites de tasa excedidos'
        ],
        
        // Errores del cliente (conservadores)
        'client_error' => [
            'max_attempts' => 1,
            'base_delay' => 0,
            'backoff_factor' => 1.0,
            'max_delay' => 30,
            'jitter_enabled' => false,
            'description' => 'Errores 4xx del cliente'
        ],
        'validation' => [
            'max_attempts' => 0,
            'base_delay' => 0,
            'backoff_factor' => 1.0,
            'max_delay' => 30,
            'jitter_enabled' => false,
            'description' => 'Errores de validación (no reintentables)'
        ],
        
        // Errores de concurrencia (especiales)
        'concurrency' => [
            'max_attempts' => 3,
            'base_delay' => 2,
            'backoff_factor' => 1.5,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'description' => 'Conflictos de concurrencia'
        ],
        
        // Errores de memoria (limitados)
        'memory' => [
            'max_attempts' => 1,
            'base_delay' => 5,
            'backoff_factor' => 1.0,
            'max_delay' => 30,
            'jitter_enabled' => false,
            'description' => 'Errores de memoria (reintento único)'
        ]
    ];

    /**
     * POLÍTICAS DE REINTENTOS POR TIPO DE OPERACIÓN
     * Definen estrategias específicas para diferentes tipos de operaciones
     * OPTIMIZADO: Todas las políticas incluyen las claves necesarias para evitar warnings
     */
    private const OPERATION_POLICIES = [
        'sync_products' => [
            'max_attempts' => 3,
            'base_delay' => 2,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'priority' => 'medium',
            'description' => 'Sincronización de productos'
        ],
        'sync_orders' => [
            'max_attempts' => 4,
            'base_delay' => 1,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'priority' => 'high',
            'description' => 'Sincronización de pedidos (crítico)'
        ],
        'sync_customers' => [
            'max_attempts' => 3,
            'base_delay' => 2,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'priority' => 'medium',
            'description' => 'Sincronización de clientes'
        ],
        'api_calls' => [
            'max_attempts' => 5,
            'base_delay' => 1,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'priority' => 'low',
            'description' => 'Llamadas API generales'
        ],
        'ssl_operations' => [
            'max_attempts' => 3,
            'base_delay' => 3,
            'backoff_factor' => 1.8,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'priority' => 'medium',
            'description' => 'Operaciones SSL'
        ],
        'batch_operations' => [
            'max_attempts' => 2,
            'base_delay' => 5,
            'backoff_factor' => 2.0,
            'max_delay' => 30,
            'jitter_enabled' => true,
            'priority' => 'low',
            'description' => 'Operaciones por lotes'
        ]
    ];

    /**
     * Constructor de la clase
     * 
     * Inicializa las dependencias necesarias para el gestor de políticas.
     * Crea una nueva instancia del logger para el registro de eventos.
     *
     * @since 1.5.0
     */
    public function __construct()
    {
        $this->logger = new Logger('retry-policy');
    }

    /**
     * Obtiene la política de reintentos para un tipo de error específico
     * 
     * Este método devuelve la configuración de reintentos para un tipo de error dado,
     * combinando la configuración base con cualquier personalización específica.
     * Los resultados se almacenan en caché para mejorar el rendimiento.
     *
     * @param string $error_type Tipo de error (network, timeout, ssl, etc.)
     * @param array<string, mixed> $context Contexto adicional para logging
     * @return array{
     *     max_attempts: int,
     *     base_delay: int,
     *     backoff_factor: float,
     *     max_delay: int,
     *     jitter_enabled: bool,
     *     description: string,
     *     priority?: string
     * } Política de reintentos configurada
     * @since 1.5.0
     *
     * @example
     * $policy = $policyManager->getErrorPolicy('timeout');
     * // Devuelve: ['max_attempts' => 4, 'base_delay' => 2, ...]
     */
    public function getErrorPolicy(string $error_type, array $context = []): array
    {
        $cache_key = "error_policy_{$error_type}";
        
        if (!isset($this->config_cache[$cache_key])) {
            $this->config_cache[$cache_key] = $this->buildErrorPolicy($error_type);
        }

        $policy = $this->config_cache[$cache_key];
        

        return $policy;
    }

    /**
     * Obtiene la política de reintentos para un tipo de operación específico
     * 
     * Devuelve la configuración de reintentos para un tipo de operación dado,
     * combinando la configuración base con cualquier personalización específica.
     * Los resultados se almacenan en caché para mejorar el rendimiento.
     *
     * @param string $operation_type Tipo de operación (sync_products, sync_orders, etc.)
     * @param array<string, mixed> $context Contexto adicional para logging
     * @return array{
     *     max_attempts: int,
     *     base_delay: int,
     *     backoff_factor: float,
     *     max_delay: int,
     *     jitter_enabled: bool,
     *     priority: string,
     *     description: string
     * } Política de reintentos configurada
     * @since 1.5.0
     *
     * @example
     * $policy = $policyManager->getOperationPolicy('sync_products');
     * // Devuelve: ['max_attempts' => 3, 'base_delay' => 2, ...]
     */
    public function getOperationPolicy(string $operation_type, array $context = []): array
    {
        $cache_key = "operation_policy_{$operation_type}";
        
        if (!isset($this->config_cache[$cache_key])) {
            $this->config_cache[$cache_key] = $this->buildOperationPolicy($operation_type);
        }

        $policy = $this->config_cache[$cache_key];
        

        return $policy;
    }

    /**
     * Construye una política de reintentos para un tipo de error
     * 
     * Combina la política base con cualquier personalización específica
     * y aplica los límites globales configurados.
     *
     * @param string $error_type Tipo de error (network, timeout, etc.)
     * @return array{
     *     max_attempts: int,
     *     base_delay: int,
     *     backoff_factor: float,
     *     max_delay: int,
     *     jitter_enabled: bool,
     *     description: string
     * } Política de reintentos configurada
     * @since 1.5.0
     * @access private
     */
    private function buildErrorPolicy(string $error_type): array
    {
        // Obtener política base desde constantes
        $base_policy = self::ERROR_POLICIES[$error_type] ?? self::ERROR_POLICIES['server_error'];
        
        // Obtener configuración personalizada desde WordPress
        $custom_policy = $this->getCustomErrorPolicy($error_type);
        
        // Combinar política base con configuración personalizada
        $policy = array_merge($base_policy, $custom_policy);
        
        // Aplicar límites globales
        $policy['max_attempts'] = min($policy['max_attempts'], $this->getGlobalMaxAttempts());
        $policy['max_delay'] = min($policy['max_delay'], $this->getGlobalMaxDelay());
        
        return $policy;
    }

    /**
     * Construye una política de reintentos para un tipo de operación
     * 
     * Combina la política base con cualquier personalización específica
     * y aplica los límites globales configurados.
     *
     * @param string $operation_type Tipo de operación (sync_products, etc.)
     * @return array{
     *     max_attempts: int,
     *     base_delay: int,
     *     backoff_factor: float,
     *     max_delay: int,
     *     jitter_enabled: bool,
     *     priority: string,
     *     description: string
     * } Política de reintentos configurada
     * @since 1.5.0
     * @access private
     */
    private function buildOperationPolicy(string $operation_type): array
    {
        // Obtener política base desde constantes
        $base_policy = self::OPERATION_POLICIES[$operation_type] ?? self::OPERATION_POLICIES['api_calls'];
        
        // Obtener configuración personalizada desde WordPress
        $custom_policy = $this->getCustomOperationPolicy($operation_type);
        
        // Combinar política base con configuración personalizada
        $policy = array_merge($base_policy, $custom_policy);
        
        // Aplicar límites globales
        $policy['max_attempts'] = min($policy['max_attempts'], $this->getGlobalMaxAttempts());
        $policy['max_delay'] = min($policy['max_delay'], $this->getGlobalMaxDelay());
        
        return $policy;
    }

    /**
     * Obtiene configuración personalizada para un tipo de error
     * 
     * Recupera la configuración personalizada para un tipo de error específico
     * desde las opciones de WordPress.
     *
     * @param string $error_type Tipo de error (network, timeout, etc.)
     * @return array<string, mixed> Configuración personalizada
     * @since 1.5.0
     * @access private
     */
    private function getCustomErrorPolicy(string $error_type): array
    {
        $policy_key = "mia_retry_policy_{$error_type}";
        $policy_level = get_option($policy_key, 'moderate');
        
        return $this->getPolicyByLevel($policy_level);
    }

    /**
     * Obtiene configuración personalizada para un tipo de operación
     * 
     * Recupera la configuración personalizada para un tipo de operación específico
     * desde las opciones de WordPress.
     *
     * @param string $operation_type Tipo de operación (sync_products, etc.)
     * @return array<string, mixed> Configuración personalizada
     * @since 1.5.0
     * @access private
     */
    private function getCustomOperationPolicy(string $operation_type): array
    {
        $option_key = "mia_retry_{$operation_type}_max_attempts";
        $max_attempts = (int) get_option($option_key, 0);
        
        if ($max_attempts > 0) {
            return ['max_attempts' => $max_attempts];
        }
        
        return [];
    }

    /**
     * Obtiene política por nivel de agresividad
     * 
     * Devuelve la configuración predefinida para un nivel de agresividad dado.
     * Los niveles disponibles son: 'aggressive', 'moderate', 'conservative' y 'none'.
     *
     * @param string $level Nivel de agresividad (aggressive|moderate|conservative|none)
     * @return array{
     *     max_attempts: int,
     *     base_delay: int,
     *     backoff_factor: float,
     *     max_delay: int,
     *     jitter_enabled: bool
     } Configuración del nivel
     * @since 1.5.0
     * @access private
     */
    private function getPolicyByLevel(string $level): array
    {
        $policies = [
            'aggressive' => [
                'max_attempts' => 5,
                'base_delay' => 1,
                'backoff_factor' => 2.0,
                'max_delay' => 30,
                'jitter_enabled' => true
            ],
            'moderate' => [
                'max_attempts' => 3,
                'base_delay' => 2,
                'backoff_factor' => 2.0,
                'max_delay' => 30,
                'jitter_enabled' => true
            ],
            'conservative' => [
                'max_attempts' => 2,
                'base_delay' => 3,
                'backoff_factor' => 1.5,
                'max_delay' => 30,
                'jitter_enabled' => false
            ],
            'none' => [
                'max_attempts' => 0,
                'base_delay' => 0,
                'backoff_factor' => 1.0,
                'max_delay' => 30,
                'jitter_enabled' => false
            ]
        ];
        
        return $policies[$level] ?? $policies['moderate'];
    }

    /**
     * Obtiene el número máximo de reintentos global
     * 
     * Recupera el límite global de reintentos desde las opciones de WordPress
     * o devuelve el valor por defecto si no está configurado.
     *
     * @return int Número máximo de reintentos permitidos
     * @since 1.5.0
     * @access private
     */
    private function getGlobalMaxAttempts(): int
    {
        return (int) get_option('mia_retry_default_max_attempts', self::DEFAULT_MAX_ATTEMPTS);
    }

    /**
     * Obtiene el retraso máximo global entre reintentos
     * 
     * Recupera el retraso máximo permitido entre reintentos desde las opciones
     * de WordPress o devuelve el valor por defecto si no está configurado.
     *
     * @return int Retraso máximo en segundos
     * @since 1.5.0
     * @access private
     */
    private function getGlobalMaxDelay(): int
    {
        return (int) get_option('mia_retry_max_delay', self::DEFAULT_MAX_DELAY);
    }

    /**
     * Verifica si el sistema de reintentos está habilitado globalmente
     * 
     * Comprueba la configuración en las opciones de WordPress para determinar
     * si el sistema de reintentos debe estar activo.
     *
     * @return bool True si el sistema de reintentos está habilitado, false en caso contrario
     * @since 1.5.0
     *
     * @example
     * if ($policyManager->isEnabled()) {
     *     // Ejecutar operación con reintentos
     * }
     */
    public function isEnabled(): bool
    {
        return (bool) get_option('mia_retry_system_enabled', true);
    }

    /**
     * Obtiene todas las políticas disponibles para depuración
     * 
     * Devuelve un array completo con todas las políticas configuradas,
     * incluyendo tanto las políticas de error como las de operación,
     * junto con la configuración global actual.
     *
     * @return array{
     *     errors: array<string, array>,
     *     operations: array<string, array>,
     *     global: array{
     *         enabled: bool,
     *         max_attempts: int,
     *         max_delay: int
     *     }
     * } Todas las políticas configuradas
     * @since 1.5.0
     *
     * @example
     * $allPolicies = $policyManager->getAllPolicies();
     * // Acceso a políticas específicas:
     * $timeoutPolicy = $allPolicies['errors']['timeout'];
     * $syncPolicy = $allPolicies['operations']['sync_products'];
     */
    public function getAllPolicies(): array
    {
        $policies = [];
        
        // Políticas de error
        foreach (array_keys(self::ERROR_POLICIES) as $error_type) {
            $policies['errors'][$error_type] = $this->getErrorPolicy($error_type);
        }
        
        // Políticas de operación
        foreach (array_keys(self::OPERATION_POLICIES) as $operation_type) {
            $policies['operations'][$operation_type] = $this->getOperationPolicy($operation_type);
        }
        
        // Configuración global
        $policies['global'] = [
            'enabled' => $this->isEnabled(),
            'max_attempts' => $this->getGlobalMaxAttempts(),
            'max_delay' => $this->getGlobalMaxDelay(),
            'backoff_factor' => (float) get_option('mia_retry_backoff_factor', self::DEFAULT_BACKOFF_FACTOR),
            'jitter_enabled' => (bool) get_option('mia_retry_jitter_enabled', true),
            'jitter_max_ms' => (int) get_option('mia_retry_jitter_max_ms', self::DEFAULT_JITTER_MAX_MS)
        ];
        
        return $policies;
    }

    /**
     * Limpia la caché de políticas
     * Útil para testing o cuando se cambia la configuración
     */
    public function clearCache(): void
    {
        $this->config_cache = [];
    }
}
