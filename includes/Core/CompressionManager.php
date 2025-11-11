<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory;
use MiIntegracionApi\Logging\Core\LoggerBasic;

/**
 * Gestor centralizado de compresión de datos
 * 
 * Maneja múltiples algoritmos de compresión (GZIP, LZ4, ZSTD) con selección
 * inteligente basada en el tipo y tamaño de los datos.
 * 
 * @package MiIntegracionApi\Core
 * @since 2.3.0
 */
class CompressionManager
{
    private LoggerBasic $logger;
    private ?SyncMetrics $metrics = null;

    public function __construct()
    {
        $this->logger = new LoggerBasic('compression_manager');
        $this->metrics = new SyncMetrics();
    }

    /**
     * Comprime datos con algoritmo específico o selección automática
     * 
     * @param mixed $data Datos a comprimir
     * @param string $algorithm Algoritmo de compresión (auto, gzip, lz4, zstd)
     * @return mixed Datos comprimidos o false si falla
     */
    public function compressData(mixed $data, string $algorithm = 'auto'): mixed
    {
        try {
            // Validar datos de entrada
            if ($data === null) {
                $this->logger->warning('Intento de comprimir datos nulos');
                return false;
            }

            $originalSize = $this->calculateDataSize($data);
            
            // Solo comprimir si es mayor a 1MB
            if ($originalSize < 1024 * 1024) {
                $this->logger->debug('Datos no comprimidos - tamaño menor a 1MB', [
                    'size_bytes' => $originalSize,
                    'size_mb' => $this->safe_round($originalSize / (1024 * 1024), 2)
                ]);
                return false;
            }
            
            // Seleccionar algoritmo automáticamente si no se especifica
            if ($algorithm === 'auto') {
                $algorithm = $this->selectBestCompressionAlgorithm($data, $originalSize);
            }
            
            $this->logger->info('Iniciando compresión de datos', [
                'algorithm' => $algorithm,
                'original_size_bytes' => $originalSize,
                'original_size_mb' => $this->safe_round($originalSize / (1024 * 1024), 2),
                'data_type' => gettype($data)
            ]);
            
            $compressionResponse = $this->executeCompression($data, $algorithm);
            
            if ($compressionResponse->isSuccess()) {
                $compressionResult = $compressionResponse->getData();
                // Registrar métricas de compresión
                $this->metrics->recordCompressionMetrics($originalSize, $compressionResult);
                
                $this->logger->info('Compresión completada exitosamente', [
                    'algorithm' => $algorithm,
                    'compression_ratio' => $this->safe_round($compressionResult['compression_ratio'], 3),
                    'space_saved_mb' => $compressionResult['space_saved_mb'],
                    'compression_time' => $this->safe_round($compressionResult['compression_time'], 4)
                ]);
                
                return $compressionResult['compressed_data'];
            }
            
            $this->logger->error('Error en compresión de datos', [
                'algorithm' => $algorithm,
                'error_message' => $compressionResponse->getMessage(),
                'original_size_bytes' => $originalSize
            ]);
            
            return false;
            
        } catch (\Exception $e) {
            $this->logger->error('Excepción durante compresión de datos', [
                'error' => $e->getMessage(),
                'algorithm' => $algorithm,
                'data_type' => gettype($data),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    /**
     * Ejecuta compresión con algoritmo específico
     * 
     * @param mixed $data Datos a comprimir
     * @param string $algorithm Algoritmo de compresión
     * @return SyncResponseInterface Resultado de la compresión
     */
    public function executeCompression(mixed $data, string $algorithm): SyncResponseInterface
    {
        try {
            // Validar parámetros
            if (empty($algorithm)) {
                return ResponseFactory::validationError(
                    'El algoritmo de compresión no puede estar vacío',
                    ['algorithm' => $algorithm]
                );
            }

            if ($data === null) {
                return ResponseFactory::validationError(
                    'Los datos a comprimir no pueden ser nulos',
                    ['data_type' => gettype($data)]
                );
            }

            $startTime = microtime(true);
            $originalSize = $this->calculateDataSize($data);
            
            // Verificar que los datos no estén vacíos
            if ($originalSize === 0) {
                return ResponseFactory::error(
                    'Los datos a comprimir están vacíos',
                    400,
                    ['data_type' => gettype($data), 'original_size' => $originalSize]
                );
            }
            
            // Ejecutar compresión según el algoritmo
            switch ($algorithm) {
                case 'gzip':
                    $compressedData = $this->compressWithGzip($data);
                    break;
                    
                case 'lz4':
                    $compressedData = $this->compressWithLz4($data);
                    break;
                    
                case 'zstd':
                    $compressedData = $this->compressWithZstd($data);
                    break;
                    
                default:
                    return ResponseFactory::error(
                        'Algoritmo de compresión no soportado: ' . $algorithm,
                        400,
                        [
                            'algorithm' => $algorithm, 
                            'supported_algorithms' => ['gzip', 'lz4', 'zstd'],
                            'error_code' => 'unsupported_algorithm'
                        ]
                    );
            }
            
            if ($compressedData === false) {
                return ResponseFactory::error(
                    'Error al comprimir datos con algoritmo: ' . $algorithm,
                    500,
                    [
                        'algorithm' => $algorithm, 
                        'data_type' => gettype($data),
                        'original_size' => $originalSize,
                        'error_code' => 'compression_failed'
                    ]
                );
            }
            
            $endTime = microtime(true);
            $compressionTime = $endTime - $startTime;
            $compressedSize = strlen($compressedData);
            
            // Verificar que la compresión fue efectiva
            if ($compressedSize >= $originalSize) {
                $this->logger->warning('Compresión no efectiva - tamaño comprimido mayor o igual al original', [
                    'algorithm' => $algorithm,
                    'original_size' => $originalSize,
                    'compressed_size' => $compressedSize,
                    'compression_ratio' => $compressedSize / $originalSize
                ]);
            }
            
            return ResponseFactory::success(
                [
                    'compressed_data' => $compressedData,
                    'algorithm' => $algorithm,
                    'compression_ratio' => $this->safe_round($compressedSize / $originalSize, 4),
                    'space_saved_mb' => $this->safe_round(($originalSize - $compressedSize) / (1024 * 1024), 2),
                    'compression_time' => $this->safe_round($compressionTime, 4),
                    'original_size_bytes' => $originalSize,
                    'compressed_size_bytes' => $compressedSize,
                    'efficiency_percent' => $this->safe_round((1 - ($compressedSize / $originalSize)) * 100, 2)
                ],
                'Compresión ejecutada correctamente',
                [
                    'operation' => 'executeCompression',
                    'algorithm' => $algorithm,
                    'timestamp' => time()
                ]
            );
            
        } catch (\Exception $e) {
            $this->logger->error('Excepción durante executeCompression', [
                'error' => $e->getMessage(),
                'algorithm' => $algorithm,
                'data_type' => gettype($data),
                'trace' => $e->getTraceAsString()
            ]);
            
            return ResponseFactory::fromException($e, [
                'algorithm' => $algorithm,
                'operation' => 'executeCompression'
            ]);
        }
    }

    /**
     * Selecciona el mejor algoritmo de compresión para los datos
     * 
     * @param mixed $data Datos a comprimir
     * @param int $originalSize Tamaño original en bytes
     * @return string Mejor algoritmo de compresión
     */
    public function selectBestCompressionAlgorithm(mixed $data, int $originalSize): string
    {
        // Para strings largos, priorizar algoritmos rápidos
        if (is_string($data)) {
            if (strlen($data) > 10 * 1024 * 1024) { // >10MB
                return 'lz4'; // Muy rápido, buena compresión
            } else {
                return 'gzip'; // Balance entre velocidad y compresión
            }
        }
        
        // Para arrays y objetos, priorizar compresión efectiva
        if (is_array($data) || is_object($data)) {
            if ($originalSize > 50 * 1024 * 1024) { // >50MB
                return 'zstd'; // Máxima compresión
            } else {
                return 'gzip'; // Balance general
            }
        }
        
        return 'gzip'; // Algoritmo por defecto
    }

    /**
     * Comprime datos con GZIP
     * 
     * @param mixed $data Datos a comprimir
     * @return bool|array Datos comprimidos o false si falla
     */
    private function compressWithGzip(mixed $data): bool|array
    {
        $serializedData = serialize($data);
        $compressedData = gzcompress($serializedData, 6); // Nivel 6 (balance)
        
        if ($compressedData === false) {
            return false;
        }
        
        return [
            'compression_type' => 'gzip',
            'compression_level' => 6,
            'original_data' => $data,
            'compressed_data' => $compressedData,
            'metadata' => [
                'algorithm' => 'gzip',
                'level' => 6,
                'compressed_at' => time()
            ]
        ];
    }

    /**
     * Comprime datos con LZ4
     * 
     * @param mixed $data Datos a comprimir
     * @return array|bool Datos comprimidos o false si falla
     */
    private function compressWithLz4(mixed $data): array|bool
    {
        // Verificar si LZ4 está disponible
        if (!function_exists('lz4_compress')) {
            // Fallback a gzip si LZ4 no está disponible
            return $this->compressWithGzip($data);
        }
        
        $serializedData = serialize($data);
        $compressedData = lz4_compress($serializedData);
        
        if ($compressedData === false) {
            return false;
        }
        
        return [
            'compression_type' => 'lz4',
            'compression_level' => 'default',
            'original_data' => $data,
            'compressed_data' => $compressedData,
            'metadata' => [
                'algorithm' => 'lz4',
                'level' => 'default',
                'compressed_at' => time()
            ]
        ];
    }

    /**
     * Comprime datos con ZSTD
     * 
     * @param mixed $data Datos a comprimir
     * @return array|bool Datos comprimidos o false si falla
     */
    private function compressWithZstd(mixed $data): array|bool
    {
        // Verificar si Zstd está disponible
        if (!function_exists('zstd_compress')) {
            // Fallback a gzip si Zstd no está disponible
            return $this->compressWithGzip($data);
        }
        
        $serializedData = serialize($data);
        $compressedData = zstd_compress($serializedData, 3); // Nivel 3 (balance)
        
        if ($compressedData === false) {
            return false;
        }
        
        return [
            'compression_type' => 'zstd',
            'compression_level' => 3,
            'original_data' => $data,
            'compressed_data' => $compressedData,
            'metadata' => [
                'algorithm' => 'zstd',
                'level' => 3,
                'compressed_at' => time()
            ]
        ];
    }

    /**
     * Calcula el tamaño de los datos en bytes con overhead de memoria
     * 
     * @param mixed $data Datos a medir
     * @param bool $includeOverhead Incluir overhead de memoria (por defecto true)
     * @return int Tamaño en bytes
     */
    private function calculateDataSize(mixed $data, bool $includeOverhead = true): int
    {
        $baseSize = $this->calculateBaseSize($data);
        
        if (!$includeOverhead) {
            return $baseSize;
        }
        
        // Calcular overhead de memoria
        $overhead = $this->calculateMemoryOverhead($data, $baseSize);
        
        return $baseSize + $overhead;
    }

    /**
     * Calcula el tamaño base de los datos sin overhead
     * 
     * @param mixed $data Datos del transient
     * @return int Tamaño base en bytes
     */
    private function calculateBaseSize(mixed $data): int
    {
        if (is_null($data)) {
            return 0;
        }
        
        if (is_string($data)) {
            return strlen($data);
        }
        
        if (is_numeric($data)) {
            return 8; // Tamaño aproximado de un número en PHP
        }
        
        if (is_bool($data)) {
            return 1;
        }
        
        if (is_array($data)) {
            $size = 0;
            foreach ($data as $key => $value) {
                $size += strlen($key) + $this->calculateBaseSize($value);
            }
            return $size;
        }
        
        if (is_object($data)) {
            // Para objetos, serializar y calcular tamaño
            return strlen(serialize($data));
        }
        
        return 0;
    }

    /**
     * Calcula el overhead de memoria considerando PHP y WordPress
     * 
     * @param mixed $data Datos del transient
     * @param int $baseSize Tamaño base calculado
     * @return int Overhead en bytes
     */
    private function calculateMemoryOverhead(mixed $data, int $baseSize): int
    {
        $overhead = 0;
        
        // Overhead básico de PHP
        $overhead += 64; // Estructura básica de variable
        
        // Overhead de serialización para WordPress
        if (is_array($data) || is_object($data)) {
            $overhead += $baseSize * 0.1; // 10% de overhead por serialización
        }
        
        // Overhead de referencias y punteros
        if (is_array($data)) {
            $overhead += count($data) * 16; // 16 bytes por elemento del array
        }
        
        // Overhead de strings largos
        if (is_string($data) && strlen($data) > 1024) {
            $overhead += 32; // Buffer adicional para strings grandes
        }
        
        // Overhead de objetos
        if (is_object($data)) {
            $overhead += 128; // Estructura de objeto PHP
            $overhead += strlen(get_class($data)) * 2; // Nombre de clase
        }
        
        return $overhead;
    }

    /**
     * Redondea un número de forma segura
     * 
     * @param float|int|null $num Número a redondear
     * @param int $precision Precisión decimal
     * @return float Número redondeado
     */
    private function safe_round(float|int|null $num, int $precision = 0): float
    {
        // Si el número es nulo o no es numérico, devolver 0.0
        if ($num === null || !is_numeric($num)) {
            return 0.0;
        }

        // Convertir a float y redondear directamente
        return round((float)$num, $precision);
    }

    /**
     * Comprime datos para almacenamiento en caché (con base64)
     * 
     * @param mixed $data Datos a comprimir
     * @return string Datos comprimidos y codificados en base64
     */
    public function compressForCache(mixed $data): string
    {
        try {
            if (function_exists('gzcompress')) {
                $serialized = serialize($data);
                $compressed = gzcompress($serialized, 6); // Nivel 6 de compresión
                return base64_encode($compressed);
            }
            
            // Fallback sin compresión
            return serialize($data);
            
        } catch (\Exception $e) {
            $this->logger->error('Error comprimiendo datos para caché', [
                'error' => $e->getMessage(),
                'data_type' => gettype($data)
            ]);
            
            // Fallback seguro
            return serialize($data);
        }
    }

    /**
     * Descomprime datos desde caché (con base64)
     * 
     * @param string $compressedData Datos comprimidos y codificados
     * @return mixed Datos descomprimidos
     */
    public function decompressFromCache(string $compressedData): mixed
    {
        try {
            if (function_exists('gzuncompress')) {
                try {
                    $decoded = base64_decode($compressedData);
                    $uncompressed = gzuncompress($decoded);
                    return unserialize($uncompressed);
                } catch (\Exception $e) {
                    // Fallback: intentar deserializar directamente
                    return unserialize($compressedData);
                }
            }
            
            // Fallback sin compresión
            return unserialize($compressedData);
            
        } catch (\Exception $e) {
            $this->logger->error('Error descomprimiendo datos desde caché', [
                'error' => $e->getMessage(),
                'data_length' => strlen($compressedData)
            ]);
            
            // Fallback seguro
            return false;
        }
    }

    /**
     * Obtiene estadísticas de compresión
     * 
     * @return array Estadísticas de compresión
     */
    public function getCompressionStats(): array
    {
        try {
            $history = get_option('mia_compression_metrics', []);
            
            if (empty($history)) {
                return [
                    'total_compressions' => 0,
                    'average_compression_ratio' => 0,
                    'total_space_saved_mb' => 0,
                    'most_used_algorithm' => 'none',
                    'average_compression_time' => 0
                ];
            }
            
            $totalCompressions = count($history);
            $totalCompressionRatio = 0;
            $totalSpaceSaved = 0;
            $totalCompressionTime = 0;
            $algorithmCounts = [];
            
            foreach ($history as $entry) {
                $totalCompressionRatio += $entry['compression_ratio'] ?? 0;
                $totalSpaceSaved += $entry['space_saved_mb'] ?? 0;
                $totalCompressionTime += $entry['compression_time'] ?? 0;
                
                $algorithm = $entry['algorithm'] ?? 'unknown';
                $algorithmCounts[$algorithm] = ($algorithmCounts[$algorithm] ?? 0) + 1;
            }
            
            $mostUsedAlgorithm = 'none';
            $maxCount = 0;
            foreach ($algorithmCounts as $algorithm => $count) {
                if ($count > $maxCount) {
                    $maxCount = $count;
                    $mostUsedAlgorithm = $algorithm;
                }
            }
            
            return [
                'total_compressions' => $totalCompressions,
                'average_compression_ratio' => $this->safe_round($totalCompressionRatio / $totalCompressions, 4),
                'total_space_saved_mb' => $this->safe_round($totalSpaceSaved, 2),
                'most_used_algorithm' => $mostUsedAlgorithm,
                'average_compression_time' => $this->safe_round($totalCompressionTime / $totalCompressions, 4),
                'algorithm_distribution' => $algorithmCounts
            ];
            
        } catch (\Exception $e) {
            $this->logger->error('Error obteniendo estadísticas de compresión', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'total_compressions' => 0,
                'average_compression_ratio' => 0,
                'total_space_saved_mb' => 0,
                'most_used_algorithm' => 'none',
                'average_compression_time' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
}
