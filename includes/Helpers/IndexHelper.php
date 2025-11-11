<?php
/**
 * Helper centralizado para indexación de datos de API
 *
 * Elimina la duplicación masiva de métodos de indexación en BatchProcessor
 * proporcionando una interfaz unificada y configurable para indexar cualquier
 * tipo de datos de la API de Verial.
 *
 * @package MiIntegracionApi\Helpers
 * @since 2.2.0
 * @author Mi Integración API Team
 */

namespace MiIntegracionApi\Helpers;

/**
 * Helper para indexación genérica de datos de API
 * 
 * Proporciona métodos centralizados para convertir arrays de datos de API
 * en arrays indexados por ID para acceso rápido, eliminando duplicación
 * de código en múltiples métodos de indexación.
 * 
 * **Características:**
 * - Configuración flexible de campos y claves
 * - Validación robusta de datos de entrada
 * - Logging contextual para debugging
 * - Soporte para múltiples formatos de API
 * - Fallbacks inteligentes para compatibilidad
 * 
 * @example
 * ```php
 * // Indexar categorías
 * $indexed = IndexHelper::indexApiData($categorias, [
 *     'wrapper_key' => 'Categorias',
 *     'id_fields' => ['Id', 'ID', 'ID_Categoria'],
 *     'name_fields' => ['Nombre'],
 *     'entity_type' => 'categorias'
 * ]);
 * 
 * // Indexar colecciones (formato diferente)
 * $indexed = IndexHelper::indexApiData($colecciones, [
 *     'wrapper_key' => 'Valores',
 *     'id_fields' => ['Id', 'ID'],
 *     'name_fields' => ['Valor'],
 *     'entity_type' => 'colecciones'
 * ]);
 * ```
 */
class IndexHelper
{
    /**
     * Configuraciones predefinidas para diferentes tipos de entidades de la API Verial
     * 
     * @var array
     */
    private static array $entityConfigs = [
        'categorias' => [
            'wrapper_key' => 'Categorias',
            'id_fields' => ['Id', 'ID', 'ID_Categoria'],
            'name_fields' => ['Nombre'],
            'description' => 'Categorías de artículos'
        ],
        'fabricantes' => [
            'wrapper_key' => 'Fabricantes', 
            'id_fields' => ['Id', 'ID'],
            'name_fields' => ['Nombre'],
            'description' => 'Fabricantes y editores'
        ],
        'colecciones' => [
            'wrapper_key' => 'Valores',
            'id_fields' => ['Id', 'ID'],
            'name_fields' => ['Valor'],
            'description' => 'Colecciones de libros'
        ],
        'cursos' => [
            'wrapper_key' => 'Valores',
            'id_fields' => ['Id', 'ID'],
            'name_fields' => ['Valor'],
            'description' => 'Cursos académicos'
        ],
        'asignaturas' => [
            'wrapper_key' => 'Valores',
            'id_fields' => ['Id', 'ID'],
            'name_fields' => ['Valor'],
            'description' => 'Asignaturas académicas'
        ]
    ];

    /**
     * Indexa datos de API de forma genérica y configurable
     * 
     * Método principal que convierte arrays de datos de API en arrays indexados
     * por ID para acceso rápido. Maneja diferentes formatos de respuesta de la
     * API de Verial de forma unificada.
     * 
     * @param array|mixed $data Datos de entrada (respuesta de API)
     * @param array $config Configuración de indexación:
     *   - 'wrapper_key' (string): Clave que contiene el array de datos
     *   - 'id_fields' (array): Campos posibles para el ID (en orden de prioridad)
     *   - 'name_fields' (array): Campos posibles para el nombre (en orden de prioridad)
     *   - 'entity_type' (string): Tipo de entidad para logging
     *   - 'logger' (Logger|null): Instancia de logger para debugging
     * 
     * @return array Array indexado [id => nombre, ...]
     * 
     * @example
     * ```php
     * $categorias = ['Categorias' => [
     *     ['Id' => 1, 'Nombre' => 'Libros'],
     *     ['Id' => 2, 'Nombre' => 'Juguetes']
     * ]];
     * 
     * $indexed = IndexHelper::indexApiData($categorias, [
     *     'wrapper_key' => 'Categorias',
     *     'id_fields' => ['Id', 'ID'],
     *     'name_fields' => ['Nombre'],
     *     'entity_type' => 'categorias'
     * ]);
     * // Resultado: [1 => 'Libros', 2 => 'Juguetes']
     * ```
     */
    public static function indexApiData($data, array $config): array
    {
        $indexed = [];
        
        // Validación de entrada
        if (!is_array($data)) {
            self::logIndexingWarning('Datos de entrada no son un array', $config, [
                'input_type' => gettype($data)
            ]);
            return $indexed;
        }

        // Extraer configuración
        $wrapperKey = $config['wrapper_key'] ?? null;
        $idFields = $config['id_fields'] ?? ['Id', 'ID'];
        $nameFields = $config['name_fields'] ?? ['Nombre', 'Valor'];
        $entityType = $config['entity_type'] ?? 'unknown';
        
        // Extraer array de datos de la respuesta API
        $dataArray = self::extractDataArray($data, $wrapperKey);
        
        if (empty($dataArray)) {
            // No hay datos para indexar
            return $indexed;
        }

        // Indexar elementos
        $processedCount = 0;
        $skippedCount = 0;
        
        foreach ($dataArray as $index => $item) {
            if (!is_array($item)) {
                $skippedCount++;
                continue;
            }
            
            // Obtener ID usando campos de prioridad
            $id = self::extractFieldValue($item, $idFields);
            $name = self::extractFieldValue($item, $nameFields);
            
            // Validar y procesar
            if (!empty($id) && !empty($name)) {
                $indexed[(int)$id] = trim($name);
                $processedCount++;
            } else {
                $skippedCount++;
                
                // Log detallado para elementos problemáticos (solo primeros 3)
                // Elemento inválido omitido
            }
        }

        // Log de resultado final
        if (isset($config['logger']) && $config['logger'] !== null) {
            $config['logger']->info("Indexación completada para {$entityType}", [
                'total_input' => count($dataArray),
                'processed' => $processedCount,
                'skipped' => $skippedCount,
                'success_rate' => count($dataArray) > 0 ? round(($processedCount / count($dataArray)) * 100, 1) : 0
            ]);
        }

        return $indexed;
    }

    /**
     * Indexa datos usando configuración predefinida para entidades conocidas
     * 
     * Método de conveniencia que usa configuraciones predefinidas para los
     * tipos de entidades más comunes de la API de Verial.
     * 
     * @param array|mixed $data Datos de entrada
     * @param string $entityType Tipo de entidad ('categorias', 'fabricantes', etc.)
     * @param Logger|null $logger Logger opcional para debugging
     * 
     * @return array Array indexado [id => nombre, ...]
     * 
     * @throws \InvalidArgumentException Si el tipo de entidad no está configurado
     * 
     * @example
     * ```php
     * // Usar configuración predefinida
     * $indexed = IndexHelper::indexKnownEntity($categorias, 'categorias', $logger);
     * ```
     */
    public static function indexKnownEntity($data, string $entityType, $logger = null): array
    {
        if (!isset(self::$entityConfigs[$entityType])) {
            throw new \InvalidArgumentException("Tipo de entidad desconocido: {$entityType}");
        }

        $config = self::$entityConfigs[$entityType];
        $config['entity_type'] = $entityType;
        $config['logger'] = $logger;

        return self::indexApiData($data, $config);
    }

    /**
     * Obtiene las configuraciones disponibles para entidades conocidas
     * 
     * @return array Array de configuraciones disponibles
     */
    public static function getAvailableEntityTypes(): array
    {
        return array_keys(self::$entityConfigs);
    }

    /**
     * Obtiene la configuración para un tipo de entidad específico
     * 
     * @param string $entityType Tipo de entidad
     * @return array|null Configuración o null si no existe
     */
    public static function getEntityConfig(string $entityType): ?array
    {
        return self::$entityConfigs[$entityType] ?? null;
    }

    /**
     * Extrae el array de datos de la respuesta API
     * 
     * @param array $data Datos de entrada
     * @param string|null $wrapperKey Clave que contiene el array
     * @return array Array de datos extraído
     */
    private static function extractDataArray(array $data, ?string $wrapperKey): array
    {
        if ($wrapperKey === null) {
            return $data;
        }

        return isset($data[$wrapperKey]) && is_array($data[$wrapperKey]) 
            ? $data[$wrapperKey] 
            : $data;
    }

    /**
     * Extrae el valor de un campo usando múltiples nombres posibles
     * 
     * @param array $item Elemento de datos
     * @param array $fieldNames Nombres de campo en orden de prioridad
     * @return mixed|null Valor encontrado o null
     */
    private static function extractFieldValue(array $item, array $fieldNames)
    {
        foreach ($fieldNames as $fieldName) {
            if (isset($item[$fieldName]) && !empty($item[$fieldName])) {
                return $item[$fieldName];
            }
        }
        return null;
    }

    /**
     * Registra advertencias de indexación de forma consistente
     * 
     * @param string $message Mensaje de advertencia
     * @param array $config Configuración actual
     * @param array $context Contexto adicional
     */
    private static function logIndexingWarning(string $message, array $config, array $context = []): void
    {
        $logger = $config['logger'] ?? null;
        $entityType = $config['entity_type'] ?? 'unknown';
        
        if ($logger !== null) {
            $logger->warning("IndexHelper: {$message}", array_merge([
                'entity_type' => $entityType,
                'component' => 'IndexHelper'
            ], $context));
        }
    }
}