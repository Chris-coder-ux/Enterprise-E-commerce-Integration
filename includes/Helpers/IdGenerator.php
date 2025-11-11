<?php declare(strict_types=1);

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * ✅ HELPER CENTRALIZADO: Generador de identificadores únicos para todo el sistema
 * 
 * Proporciona métodos estáticos configurables para generar IDs únicos y consistentes
 * en todo el plugin, eliminando duplicación y hardcodeos.
 * 
 * **🎯 CARACTERÍSTICAS:**
 * - **Configurabilidad total**: Todos los formatos son ajustables vía WordPress options
 * - **Unicidad garantizada**: Usa microtime + entropía para evitar colisiones
 * - **Contexto enriquecido**: Incluye información relevante para debugging
 * - **Compatibilidad**: Fallbacks seguros para diferentes entornos
 * - **Performance**: Métodos optimizados sin overhead innecesario
 * 
 * @package MiIntegracionApi\Helpers
 * @since 2.2.0
 */
class IdGenerator
{
    /**
     * ✅ CONFIGURACIÓN POR DEFECTO
     * Valores utilizados cuando no hay configuración específica en WordPress options
     */
    private const DEFAULT_CONFIG = [
        'batch_prefix' => 'batch',
        'operation_prefix' => 'op',
        'transaction_prefix' => 'tx',
        'separator' => '_',
        'padding_length' => 3,
        'use_microtime' => true,
        'include_context' => true,
        'hash_algorithm' => 'md5'
    ];

    /**
     * ✅ GENERA ID DE LOTE (BATCH) con formato configurable y contexto enriquecido
     * 
     * Formatos soportados:
     * - Con número: "batch_1735689123_001" 
     * - Sin número: "batch_1735689123"
     * - Con contexto: "batch_productos_1735689123_001"
     * - Con hash: "batch_a1b2c3d4"
     * 
     * @param string $context Contexto del lote (ej: 'productos', 'clientes')
     * @param int $batchNumber Número del lote (0 = sin número)
     * @param array $options Opciones adicionales para personalización
     * 
     * @return string ID único del lote
     * 
     * @example
     * ```php
     * $id = IdGenerator::generateBatchId('productos', 1);     // "batch_productos_1735689123_001"
     * $id = IdGenerator::generateBatchId('productos');        // "batch_productos_1735689123"
     * $id = IdGenerator::generateBatchId();                   // "batch_1735689123"
     * ```
     */
    public static function generateBatchId(string $context = '', int $batchNumber = 0, array $options = []): string
    {
        $config = self::getConfig('batch', $options);
        
        // Construir prefijo con contexto si está disponible
        $prefix = $config['prefix'];
        if (!empty($context) && $config['include_context']) {
            $prefix .= $config['separator'] . self::sanitizeContext($context);
        }
        
        // Generar timestamp base
        $timestamp = $config['use_microtime'] 
            ? (int)(microtime(true) * 1000) // Microsegundos para mayor unicidad
            : time();
        
        // Construir ID base
        $id = $prefix . $config['separator'] . $timestamp;
        
        // Añadir número de lote si se especifica
        if ($batchNumber > 0) {
            $paddedNumber = str_pad(
                (string)$batchNumber, 
                $config['padding_length'], 
                '0', 
                STR_PAD_LEFT
            );
            $id .= $config['separator'] . $paddedNumber;
        }
        
        return $id;
    }

    /**
     * ✅ GENERA ID DE OPERACIÓN para tracking de procesos y transacciones
     * 
     * @param string $operation Tipo de operación (ej: 'sync', 'import', 'export')
     * @param array $context Contexto adicional para el ID
     * @param array $options Opciones de configuración
     * 
     * @return string ID único de operación
     * 
     * @example
     * ```php
     * $id = IdGenerator::generateOperationId('sync');                    // "op_sync_1735689123456"
     * $id = IdGenerator::generateOperationId('import', ['entity' => 'productos']); // "op_import_1735689123456"
     * ```
     */
    public static function generateOperationId(string $operation = '', array $context = [], array $options = []): string
    {
        $config = self::getConfig('operation', $options);
        
        // Construir prefijo con operación
        $prefix = $config['prefix'];
        if (!empty($operation)) {
            $prefix .= $config['separator'] . self::sanitizeContext($operation);
        }
        
        // Usar microtime para operaciones (mayor precisión necesaria)
        $timestamp = (int)(microtime(true) * 1000000); // Microsegundos
        
        $id = $prefix . $config['separator'] . $timestamp;
        
        // Añadir hash del contexto si está disponible
        if (!empty($context) && $config['include_context']) {
            $contextHash = substr(
                hash($config['hash_algorithm'], serialize($context)), 
                0, 
                8
            );
            $id .= $config['separator'] . $contextHash;
        }
        
        return $id;
    }

    /**
     * ✅ GENERA ID DE TRANSACCIÓN para sistemas de logging y debugging
     * 
     * @param array $options Opciones de configuración
     * 
     * @return string ID único de transacción
     * 
     * @example
     * ```php
     * $id = IdGenerator::generateTransactionId(); // "tx_1735689123456789_a1b2c3d4"
     * ```
     */
    public static function generateTransactionId(array $options = []): string
    {
        $config = self::getConfig('transaction', $options);
        
        // Para transacciones, siempre usar máxima precisión
        $timestamp = (int)(microtime(true) * 1000000);
        
        // Añadir entropía adicional para transacciones
        $entropy = '';
        if (function_exists('random_bytes')) {
            try {
                $entropy = bin2hex(random_bytes(4));
            } catch (\Exception $e) {
                // Fallback a entropía menos segura
                $entropy = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
            }
        } else {
            $entropy = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
        }
        
        return $config['prefix'] . $config['separator'] . $timestamp . $config['separator'] . $entropy;
    }

    /**
     * ✅ GENERA ID COMPATIBLE con uniqid() pero más robusto
     * 
     * @param string $prefix Prefijo para el ID
     * @param bool $moreEntropy Añadir entropía adicional
     * 
     * @return string ID único compatible
     * 
     * @example
     * ```php
     * $id = IdGenerator::generateCompatibleId('batch_', true); // "batch_507f1f77bcf86cd799439011_a1b2c3d4"
     * ```
     */
    public static function generateCompatibleId(string $prefix = '', bool $moreEntropy = true): string
    {
        if (function_exists('wp_generate_uuid4')) {
            // Usar UUID de WordPress si está disponible
            $uuid = str_replace('-', '', wp_generate_uuid4());
            return $prefix . substr($uuid, 0, 13) . ($moreEntropy ? substr($uuid, 13, 8) : '');
        }
        
        // Fallback compatible con uniqid()
        return uniqid($prefix, $moreEntropy);
    }

    /**
     * ✅ GENERA HASH ÚNICO para cualquier dato (útil para caché, identificadores)
     * 
     * @param mixed $data Datos para generar el hash
     * @param string $algorithm Algoritmo de hash a usar
     * @param int $length Longitud del hash (0 = completo)
     * 
     * @return string Hash único
     * 
     * @example
     * ```php
     * $hash = IdGenerator::generateHash(['productos', 'batch', 123]); // "a1b2c3d4e5f6..."
     * $hash = IdGenerator::generateHash($data, 'sha256', 8);          // "a1b2c3d4"
     * ```
     */
    public static function generateHash($data, string $algorithm = 'md5', int $length = 0): string
    {
        if (is_array($data) || is_object($data)) {
            $data = serialize($data);
        }
        
        $hash = hash($algorithm, (string)$data);
        
        return $length > 0 ? substr($hash, 0, $length) : $hash;
    }

    /**
     * ✅ OBTIENE CONFIGURACIÓN MERGEABLE desde WordPress options
     * 
     * @param string $type Tipo de ID (batch, operation, transaction)
     * @param array $overrides Sobreescribir configuración específica
     * 
     * @return array Configuración completa
     */
    private static function getConfig(string $type, array $overrides = []): array
    {
        $baseConfig = self::DEFAULT_CONFIG;
        
        // Obtener configuración específica del tipo desde WordPress options
        $optionKey = "mia_id_generator_{$type}_config";
        $savedConfig = function_exists('get_option') ? get_option($optionKey, []) : [];
        
        // Asegurar que savedConfig sea un array
        if (!is_array($savedConfig)) {
            $savedConfig = [];
        }
        
        // Mergear configuraciones: default -> saved -> overrides
        $config = array_merge($baseConfig, $savedConfig, $overrides);
        
        // Mapear claves específicas del tipo
        $typeKey = $type . '_prefix';
        if (isset($config[$typeKey])) {
            $config['prefix'] = $config[$typeKey];
        } else {
            $config['prefix'] = $config[array_key_first(array_filter(
                array_keys($config), 
                fn($key) => str_ends_with($key, '_prefix')
            )) ?: $type . '_prefix'] ?? $baseConfig[$type . '_prefix'] ?? 'id';
        }
        
        return $config;
    }

    /**
     * ✅ SANITIZA CONTEXTO para uso seguro en IDs
     * 
     * @param string $context Contexto a sanitizar
     * 
     * @return string Contexto sanitizado
     */
    private static function sanitizeContext(string $context): string
    {
        // Convertir a minúsculas y reemplazar caracteres no válidos
        $sanitized = strtolower($context);
        $sanitized = preg_replace('/[^a-z0-9]/', '', $sanitized);
        
        // Limitar longitud para evitar IDs demasiado largos
        return substr($sanitized, 0, 10);
    }

    /**
     * ✅ VALIDA SI UN ID TIENE FORMATO VÁLIDO
     * 
     * @param string $id ID a validar
     * @param string $expectedType Tipo esperado (batch, operation, transaction)
     * 
     * @return bool True si el formato es válido
     * 
     * @example
     * ```php
     * $valid = IdGenerator::validateIdFormat('batch_productos_123456', 'batch'); // true
     * $valid = IdGenerator::validateIdFormat('invalid_format', 'batch');         // false
     * ```
     */
    public static function validateIdFormat(string $id, string $expectedType = ''): bool
    {
        if (empty($id)) {
            return false;
        }
        
        // Validación básica: debe contener al menos un separador y timestamp numérico
        $parts = explode('_', $id);
        
        if (count($parts) < 2) {
            return false;
        }
        
        // Validar que al menos una parte contenga números (timestamp)
        $hasNumericPart = false;
        foreach ($parts as $part) {
            if (is_numeric($part) && strlen($part) >= 10) { // Timestamp mínimo
                $hasNumericPart = true;
                break;
            }
        }
        
        if (!$hasNumericPart) {
            return false;
        }
        
        // Validación específica del tipo si se proporciona
        if (!empty($expectedType)) {
            $config = self::getConfig($expectedType);
            return str_starts_with($id, $config['prefix'] . $config['separator']);
        }
        
        return true;
    }

    /**
     * ✅ EXTRAE INFORMACIÓN de un ID generado por este helper
     * 
     * @param string $id ID a analizar
     * 
     * @return array Información extraída del ID
     * 
     * @example
     * ```php
     * $info = IdGenerator::parseId('batch_productos_1735689123_001');
     * // ['prefix' => 'batch', 'context' => 'productos', 'timestamp' => 1735689123, 'number' => 1]
     * ```
     */
    public static function parseId(string $id): array
    {
        $parts = explode('_', $id);
        $info = [
            'original' => $id,
            'valid' => false,
            'prefix' => '',
            'context' => '',
            'timestamp' => null,
            'number' => null,
            'entropy' => ''
        ];
        
        if (count($parts) < 2) {
            return $info;
        }
        
        $info['prefix'] = $parts[0];
        $info['valid'] = true;
        
        // Analizar partes restantes
        for ($i = 1; $i < count($parts); $i++) {
            $part = $parts[$i];
            
            if (is_numeric($part)) {
                if (strlen($part) >= 10) {
                    // Probablemente un timestamp
                    $info['timestamp'] = (int)$part;
                } elseif (strlen($part) <= 3 && $info['timestamp'] !== null) {
                    // Probablemente un número de lote
                    $info['number'] = (int)$part;
                } else {
                    // Entropía numérica
                    $info['entropy'] = $part;
                }
            } else {
                if (empty($info['context'])) {
                    // Primera parte no numérica es el contexto
                    $info['context'] = $part;
                } else {
                    // Partes adicionales son entropía
                    $info['entropy'] .= $part;
                }
            }
        }
        
        return $info;
    }
}
