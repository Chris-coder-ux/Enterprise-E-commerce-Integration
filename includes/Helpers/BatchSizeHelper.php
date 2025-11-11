<?php declare(strict_types=1);
/**
 * Clase auxiliar para la gestión centralizada del tamaño de lote (batch size).
 *
 * Proporciona métodos para obtener, validar y calcular rangos de tamaño de lote
 * para diferentes entidades (productos, clientes, pedidos, etc.). Sirve como punto único
 * de verdad para todas las operaciones relacionadas con batch size en la integración.
 *
 * @package MiIntegracionApi\Helpers
 * @since 2.6.0
 */

namespace MiIntegracionApi\Helpers;

defined('ABSPATH') || exit;

class BatchSizeHelper {

    /**
     * Valores predeterminados para el tamaño de lote por entidad.
     *
     * @var array<string,int>
     */
    const DEFAULT_BATCH_SIZES = [
        'productos' => 20,
        'products' => 20, // Alias para productos
        'clientes' => 50,
        'customers' => 50, // Alias para clientes
        'pedidos' => 50,
        'orders' => 50, // Alias para pedidos
        'precios' => 20,
        'prices' => 20, // Alias para precios
    ];

    /**
     * Límites de tamaño de lote por entidad (mínimo y máximo).
     *
     * @var array<string,array{min:int,max:int}>
     */
    const BATCH_SIZE_LIMITS = [
        'productos' => ['min' => 1, 'max' => 200],
        'products' => ['min' => 1, 'max' => 200],
        'clientes' => ['min' => 1, 'max' => 200],
        'customers' => ['min' => 1, 'max' => 200],
        'pedidos' => ['min' => 1, 'max' => 100],
        'orders' => ['min' => 1, 'max' => 100],
        'precios' => ['min' => 1, 'max' => 500],
        'prices' => ['min' => 1, 'max' => 500],
    ];

    /**
     * Mapeo de nombres de entidades para estandarización.
     *
     * @var array<string,string>
     */
    const ENTITY_MAPPINGS = [
        'products' => 'productos',
        'customers' => 'clientes',
        'orders' => 'pedidos',
        'prices' => 'precios',
    ];

    /**
     * Nombre base del prefijo de las opciones de WordPress para tamaño de lote.
     *
     * @var string
     */
    const OPTION_PREFIX = 'mi_integracion_api_batch_size_';

    /**
     * Obtiene el tamaño de lote para una entidad específica.
     *
     * Prioriza el valor de la base de datos, luego una constante de override (solo para productos),
     * y finalmente el valor por defecto definido en la clase.
     *
     * @param string   $entity         Nombre de la entidad (productos, clientes, pedidos, etc.).
     * @param int|null $override_value Valor opcional para sobreescribir temporalmente.
     * @return int Tamaño de lote validado y corregido.
     */
    public static function getBatchSize(string $entity, ?int $override_value = null): int {
        // Normalizar el nombre de la entidad
        $entity = self::normalizeEntityName($entity);
        
        // Si se proporciona un valor de sobreescritura, validarlo y usarlo
        if ($override_value !== null) {
            return self::validateBatchSize($entity, $override_value);
        }
        
        // PRIORIDAD 1: Obtener el valor configurado en el dashboard (base de datos)
        $option_name = self::OPTION_PREFIX . $entity;
        $batch_size = get_option($option_name, null);
        
        // Si hay valor en la base de datos, usarlo (el dashboard tiene prioridad)
        if ($batch_size !== null && $batch_size > 0) {
            return self::validateBatchSize($entity, $batch_size);
        }
        
        // PRIORIDAD 2: Verificar constante MIAPI_OVERRIDE_BATCH_SIZE para productos
        if ($entity === 'productos' && defined('MIAPI_OVERRIDE_BATCH_SIZE')) {
            $batch_size = (int) MIAPI_OVERRIDE_BATCH_SIZE;
            return self::validateBatchSize($entity, $batch_size);
        }
        
        // PRIORIDAD 3: Usar valor por defecto
        $batch_size = self::DEFAULT_BATCH_SIZES[$entity] ?? 20;
        
        // Validar y devolver el valor
        return self::validateBatchSize($entity, $batch_size);
    }
    
    /**
     * Establece el tamaño de lote para una entidad específica y lo guarda en la base de datos.
     *
     * También actualiza la opción 'products' si la entidad es 'productos' para compatibilidad.
     *
     * @param string $entity     Nombre de la entidad.
     * @param int    $batch_size Tamaño de lote a establecer.
     * @return bool True si la operación fue exitosa, false en caso contrario.
     */
    public static function setBatchSize(string $entity, int $batch_size): bool {
        // Normalizar el nombre de la entidad
        $entity = self::normalizeEntityName($entity);
        
        // Validar el valor
        $batch_size = self::validateBatchSize($entity, $batch_size);
        
        // Actualizar la opción
        $option_name = self::OPTION_PREFIX . $entity;
        $result = update_option($option_name, $batch_size);
        
        // Para mantener compatibilidad con código antiguo, actualizar también la opción 'products' si se actualiza 'productos'
        if ($entity === 'productos') {
            update_option(self::OPTION_PREFIX . 'products', $batch_size);
        }
        
        return $result;
    }

    /**
     * Valida y corrige un tamaño de lote según los límites definidos para la entidad.
     *
     * @param string $entity     Nombre de la entidad.
     * @param int    $batch_size Tamaño de lote a validar.
     * @return int Tamaño de lote corregido y dentro de los límites.
     */
    public static function validateBatchSize(string $entity, $batch_size): int {
        // Asegurar que el valor sea numérico
        $batch_size = intval($batch_size);
        
        // Obtener límites para la entidad
        $limits = self::BATCH_SIZE_LIMITS[$entity] ?? ['min' => 1, 'max' => 200];
        
        // Aplicar límites
        $batch_size = max($limits['min'], min($limits['max'], $batch_size));
        
        return $batch_size;
    }
    
    /**
     * Normaliza el nombre de una entidad según el mapeo definido en la clase.
     *
     * @param string $entity Nombre de la entidad.
     * @return string Nombre normalizado de la entidad.
     */
    public static function normalizeEntityName(string $entity): string {
        return self::ENTITY_MAPPINGS[$entity] ?? $entity;
    }
    
    /**
     * Calcula el rango para procesar un lote basado en un índice de inicio y el tamaño del lote.
     *
     * @param int $start_index Índice de inicio (cero-basado).
     * @param int $batch_size  Tamaño del lote.
     * @return array{inicio:int,fin:int} Arreglo asociativo con claves 'inicio' y 'fin'.
     */
    public static function calculateRange(int $start_index, int $batch_size): array {
        $inicio = $start_index + 1; // Convertir a índice 1-basado para la API
        $fin = $inicio + $batch_size - 1;
        
        return [
            'inicio' => $inicio,
            'fin' => $fin
        ];
    }
    
    /**
     * Calcula el tamaño efectivo del lote basado en valores de inicio y fin (ambos 1-basado).
     *
     * @param int $inicio Valor de inicio (1-basado).
     * @param int $fin    Valor de fin (1-basado).
     * @return int Tamaño efectivo del lote.
     */
    public static function calculateEffectiveBatchSize(int $inicio, int $fin): int {
        return $fin - $inicio + 1;
    }
    
    /**
     * Determina si un tamaño de lote es válido para su uso (mayor a cero).
     *
     * @param int $batch_size Tamaño de lote a verificar.
     * @return bool True si es válido, false en caso contrario.
     */
    public static function isValidBatchSize(int $batch_size): bool {
        return $batch_size > 0;
    }
    
    /**
     * Divide un array en lotes del tamaño especificado.
     *
     * @param array $items      Array a dividir en lotes.
     * @param int   $batch_size Tamaño de cada lote.
     * @return array[] Array de lotes (cada uno es un array).
     */
    public static function chunkItems(array $items, int $batch_size): array {
        // Validar tamaño del lote
        $batch_size = max(1, $batch_size);
        
        // Dividir en lotes
        return array_chunk($items, $batch_size);
    }
    
    /**
     * Obtiene batch size optimizado según memoria disponible
     * 
     * @param int $base_batch_size Tamaño base del lote
     * @param string $entity Entidad a procesar
     * @param callable|null $memory_usage_callback Función para obtener uso de memoria
     * @param bool $respect_user_limit Si true, nunca excede el batch_size base (respeta límite del usuario)
     * @return int Batch size optimizado
     */
    public static function getMemoryOptimizedBatchSize(int $base_batch_size, string $entity, ?callable $memory_usage_callback = null, bool $respect_user_limit = true): int
    {
        try {
            // Obtener uso de memoria actual
            $memory_data = null;
            if ($memory_usage_callback && is_callable($memory_usage_callback)) {
                $memory_response = $memory_usage_callback();
                if ($memory_response && method_exists($memory_response, 'isSuccess') && $memory_response->isSuccess()) {
                    $memory_data = $memory_response->getData();
                }
            }
            
            // Si no se puede obtener memoria, usar batch size base
            if (!$memory_data) {
                return self::validateBatchSize($entity, $base_batch_size);
            }
            
            $memory_percent = $memory_data['percent'] ?? 0;
            $current_memory_mb = $memory_data['current_mb'] ?? 0;
            $limit_mb = $memory_data['limit_mb'] ?? 0;
            
            // Calcular factor de ajuste según memoria disponible
            $adjustment_factor = self::calculateMemoryAdjustmentFactor($memory_percent, $current_memory_mb, $limit_mb);
            
            // Aplicar ajuste al batch size
            $optimized_batch_size = max(1, (int)($base_batch_size * $adjustment_factor));
            
            // ✅ CORRECCIÓN: Si se debe respetar el límite del usuario, nunca exceder el batch_size base
            if ($respect_user_limit && $optimized_batch_size > $base_batch_size) {
                $optimized_batch_size = $base_batch_size;
            }
            
            // Validar límites de la entidad
            return self::validateBatchSize($entity, $optimized_batch_size);
            
        } catch (\Exception $e) {
            // En caso de error, usar batch size base validado
            return self::validateBatchSize($entity, $base_batch_size);
        }
    }
    
    /**
     * Calcula factor de ajuste según memoria disponible
     * 
     * @param float $memory_percent Porcentaje de memoria usado
     * @param float $current_memory_mb Memoria actual en MB
     * @param float $limit_mb Límite de memoria en MB
     * @return float Factor de ajuste (0.1 a 2.0)
     */
    private static function calculateMemoryAdjustmentFactor(float $memory_percent, float $current_memory_mb, float $limit_mb): float
    {
        // Memoria crítica (>80%): Reducir batch size significativamente
        if ($memory_percent > 80) {
            return 0.2; // 20% del batch size original
        }
        
        // Memoria alta (60-80%): Reducir batch size moderadamente
        if ($memory_percent > 60) {
            return 0.5; // 50% del batch size original
        }
        
        // Memoria media (40-60%): Mantener batch size original
        if ($memory_percent > 40) {
            return 1.0; // 100% del batch size original
        }
        
        // Memoria baja (<40%): Aumentar batch size moderadamente
        if ($memory_percent > 20) {
            return 1.5; // 150% del batch size original
        }
        
        // Memoria muy baja (<20%): Aumentar batch size significativamente
        return 2.0; // 200% del batch size original
    }
}
