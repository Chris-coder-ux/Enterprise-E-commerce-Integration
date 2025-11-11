#!/usr/bin/env php
<?php
/**
 * Script para detectar lógica duplicada en archivos PHP
 * 
 * Detecta:
 * - Secuencias de llamadas a métodos duplicadas
 * - Bloques de código similares
 * - Patrones repetidos
 * 
 * Uso:
 *   php scripts/detect-duplicate-code.php [archivo.php]
 *   php scripts/detect-duplicate-code.php includes/Core/BatchProcessor.php
 *   php scripts/detect-duplicate-code.php includes/
 * 
 * @package MiIntegracionApi
 * @version 1.0.0
 */

class DuplicateCodeDetector
{
    /**
     * Configuración del detector
     */
    private array $config = [
        'min_sequence_length' => 3,      // Mínimo de líneas para considerar duplicado
        'min_similarity' => 0.85,        // Similitud mínima (85%)
        'ignore_comments' => true,       // Ignorar comentarios en comparación
        'ignore_whitespace' => true,     // Ignorar espacios en blanco
        'detect_method_calls' => true,   // Detectar llamadas a métodos duplicadas
        'detect_blocks' => true,         // Detectar bloques de código duplicados
        'context_lines' => 2,            // Líneas de contexto a mostrar
    ];
    
    /**
     * Resultados encontrados
     */
    private array $duplicates = [];
    
    /**
     * Estadísticas
     */
    private array $stats = [
        'files_analyzed' => 0,
        'lines_analyzed' => 0,
        'duplicates_found' => 0,
        'potential_savings' => 0,
    ];
    
    /**
     * Constructor
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge($this->config, $config);
    }
    
    /**
     * Analiza un archivo o directorio
     */
    public function analyze(string $path): array
    {
        if (is_file($path)) {
            $this->analyzeFile($path);
        } elseif (is_dir($path)) {
            $this->analyzeDirectory($path);
        } else {
            throw new Exception("Ruta no válida: {$path}");
        }
        
        return $this->duplicates;
    }
    
    /**
     * Analiza un directorio recursivamente
     */
    private function analyzeDirectory(string $dir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $this->analyzeFile($file->getPathname());
            }
        }
    }
    
    /**
     * Analiza un archivo PHP
     */
    private function analyzeFile(string $filepath): void
    {
        if (!file_exists($filepath)) {
            echo "⚠️  Archivo no encontrado: {$filepath}\n";
            return;
        }
        
        $content = file_get_contents($filepath);
        $lines = file($filepath, FILE_IGNORE_NEW_LINES);
        
        $this->stats['files_analyzed']++;
        $this->stats['lines_analyzed'] += count($lines);
        
        echo "🔍 Analizando: {$filepath}\n";
        
        // Detectar secuencias de llamadas a métodos
        if ($this->config['detect_method_calls']) {
            $this->detectMethodCallSequences($filepath, $lines);
        }
        
        // Detectar bloques de código duplicados
        if ($this->config['detect_blocks']) {
            $this->detectDuplicateBlocks($filepath, $lines);
        }
    }
    
    /**
     * Detecta secuencias de llamadas a métodos duplicadas
     */
    private function detectMethodCallSequences(string $filepath, array $lines): void
    {
        $methodCalls = [];
        
        // Extraer todas las llamadas a métodos con su línea
        foreach ($lines as $lineNum => $line) {
            if (preg_match('/\$this->([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/', $line, $matches)) {
                $methodCalls[] = [
                    'line' => $lineNum + 1,
                    'method' => $matches[1],
                    'full_line' => trim($line),
                ];
            }
        }
        
        // Buscar secuencias repetidas
        $sequences = $this->findRepeatedSequences($methodCalls);
        
        foreach ($sequences as $sequence) {
            if ($sequence['count'] > 1 && $sequence['length'] >= $this->config['min_sequence_length']) {
                $this->duplicates[] = [
                    'type' => 'method_sequence',
                    'file' => $filepath,
                    'occurrences' => $sequence['locations'],
                    'length' => $sequence['length'],
                    'methods' => $sequence['methods'],
                    'severity' => $this->calculateSeverity($sequence['length'], $sequence['count']),
                ];
                
                $this->stats['duplicates_found']++;
                $this->stats['potential_savings'] += ($sequence['length'] * ($sequence['count'] - 1));
            }
        }
    }
    
    /**
     * Encuentra secuencias repetidas de llamadas a métodos
     */
    private function findRepeatedSequences(array $methodCalls): array
    {
        $sequences = [];
        $minLength = $this->config['min_sequence_length'];
        $maxLength = min(15, count($methodCalls)); // Máximo 15 métodos en secuencia
        
        // Buscar secuencias de diferentes longitudes
        for ($length = $minLength; $length <= $maxLength; $length++) {
            for ($i = 0; $i <= count($methodCalls) - $length; $i++) {
                $sequence = array_slice($methodCalls, $i, $length);
                $methodNames = array_column($sequence, 'method');
                $sequenceKey = implode('→', $methodNames);
                
                if (!isset($sequences[$sequenceKey])) {
                    $sequences[$sequenceKey] = [
                        'methods' => $methodNames,
                        'length' => $length,
                        'count' => 0,
                        'locations' => [],
                    ];
                }
                
                $sequences[$sequenceKey]['count']++;
                $sequences[$sequenceKey]['locations'][] = [
                    'start_line' => $sequence[0]['line'],
                    'end_line' => $sequence[count($sequence) - 1]['line'],
                    'code' => array_column($sequence, 'full_line'),
                ];
            }
        }
        
        // Filtrar secuencias que aparecen solo una vez
        return array_filter($sequences, fn($seq) => $seq['count'] > 1);
    }
    
    /**
     * Detecta bloques de código duplicados (optimizado para archivos grandes)
     */
    private function detectDuplicateBlocks(string $filepath, array $lines): void
    {
        $minLength = $this->config['min_sequence_length'];
        $maxLength = min(20, count($lines)); // Limitar búsqueda a bloques de máximo 20 líneas
        $blocks = [];
        
        // Normalizar líneas (eliminar comentarios y espacios si está configurado)
        $normalizedLines = [];
        foreach ($lines as $idx => $line) {
            $normalized = $line;
            
            if ($this->config['ignore_comments']) {
                $normalized = preg_replace('/\/\/.*$/', '', $normalized);
                $normalized = preg_replace('/\/\*.*?\*\//', '', $normalized);
            }
            
            if ($this->config['ignore_whitespace']) {
                $normalized = preg_replace('/\s+/', ' ', trim($normalized));
            }
            
            $normalizedLines[$idx] = $normalized;
        }
        
        // Buscar bloques duplicados solo para longitudes específicas (evitar explosión combinatoria)
        $lengthsToCheck = range($minLength, min($minLength + 5, $maxLength));
        
        foreach ($lengthsToCheck as $length) {
            $seenBlocks = [];
            
            for ($i = 0; $i <= count($normalizedLines) - $length; $i++) {
                $block = array_slice($normalizedLines, $i, $length, true);
                $blockKey = md5(implode("\n", $block));
                
                if (!isset($seenBlocks[$blockKey])) {
                    $seenBlocks[$blockKey] = [
                        'length' => $length,
                        'locations' => [],
                        'content' => array_slice($lines, $i, $length, true),
                    ];
                }
                
                $seenBlocks[$blockKey]['locations'][] = [
                    'start_line' => $i + 1,
                    'end_line' => $i + $length,
                ];
            }
            
            // Filtrar bloques que aparecen más de una vez
            foreach ($seenBlocks as $blockKey => $block) {
                $count = count($block['locations']);
                
                if ($count > 1) {
                    // Verificar que no sea solo líneas vacías o llaves
                    $nonEmptyLines = array_filter($block['content'], function($line) {
                        $trimmed = trim($line);
                        return !empty($trimmed) && $trimmed !== '{' && $trimmed !== '}' && $trimmed !== '//';
                    });
                    
                    if (count($nonEmptyLines) >= $minLength) {
                        $this->duplicates[] = [
                            'type' => 'code_block',
                            'file' => $filepath,
                            'occurrences' => $block['locations'],
                            'length' => $block['length'],
                            'content' => $block['content'],
                            'severity' => $this->calculateSeverity($block['length'], $count),
                        ];
                        
                        $this->stats['duplicates_found']++;
                        $this->stats['potential_savings'] += ($block['length'] * ($count - 1));
                    }
                }
            }
            
            // Liberar memoria
            unset($seenBlocks);
        }
        
        // Liberar memoria
        unset($normalizedLines);
    }
    
    /**
     * Calcula la severidad del duplicado
     */
    private function calculateSeverity(int $length, int $count): string
    {
        $score = $length * ($count - 1);
        
        if ($score >= 50) return 'critical';
        if ($score >= 20) return 'high';
        if ($score >= 10) return 'medium';
        return 'low';
    }
    
    /**
     * Genera reporte
     */
    public function generateReport(): void
    {
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "📊 REPORTE DE CÓDIGO DUPLICADO\n";
        echo str_repeat("=", 80) . "\n\n";
        
        // Estadísticas generales
        echo "📈 Estadísticas:\n";
        echo "  • Archivos analizados: {$this->stats['files_analyzed']}\n";
        echo "  • Líneas analizadas: {$this->stats['lines_analyzed']}\n";
        echo "  • Duplicados encontrados: {$this->stats['duplicates_found']}\n";
        echo "  • Líneas que se pueden ahorrar: {$this->stats['potential_savings']}\n\n";
        
        if (empty($this->duplicates)) {
            echo "✅ No se encontró código duplicado significativo.\n";
            return;
        }
        
        // Agrupar por severidad
        $bySeverity = [];
        foreach ($this->duplicates as $duplicate) {
            $severity = $duplicate['severity'];
            if (!isset($bySeverity[$severity])) {
                $bySeverity[$severity] = [];
            }
            $bySeverity[$severity][] = $duplicate;
        }
        
        // Ordenar por severidad
        $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
        uksort($bySeverity, fn($a, $b) => $severityOrder[$a] <=> $severityOrder[$b]);
        
        // Mostrar duplicados por severidad
        foreach ($bySeverity as $severity => $duplicates) {
            $icon = match($severity) {
                'critical' => '🔴',
                'high' => '🟠',
                'medium' => '🟡',
                'low' => '🟢',
            };
            
            echo "\n{$icon} Severidad: " . strtoupper($severity) . " (" . count($duplicates) . " encontrados)\n";
            echo str_repeat("-", 80) . "\n";
            
            foreach ($duplicates as $index => $duplicate) {
                $this->printDuplicate($duplicate, $index + 1);
            }
        }
        
        // Recomendaciones
        echo "\n" . str_repeat("=", 80) . "\n";
        echo "💡 RECOMENDACIONES\n";
        echo str_repeat("=", 80) . "\n\n";
        
        $critical = $bySeverity['critical'] ?? [];
        $high = $bySeverity['high'] ?? [];
        
        if (!empty($critical) || !empty($high)) {
            echo "⚠️  ACCIÓN INMEDIATA REQUERIDA:\n\n";
            
            if (!empty($critical)) {
                echo "  • " . count($critical) . " duplicados críticos deben ser refactorizados inmediatamente.\n";
            }
            
            if (!empty($high)) {
                echo "  • " . count($high) . " duplicados de alta prioridad deberían ser revisados.\n";
            }
            
            echo "\n";
            echo "  Estrategias de refactorización:\n";
            echo "  1. Extraer método común (Extract Method)\n";
            echo "  2. Usar composición en lugar de duplicación\n";
            echo "  3. Implementar patrón Template Method\n";
            echo "  4. Crear clase/trait compartida\n\n";
        } else {
            echo "✅ Los duplicados encontrados son de baja prioridad.\n";
            echo "   Considere refactorizarlos cuando sea conveniente.\n\n";
        }
    }
    
    /**
     * Imprime información de un duplicado
     */
    private function printDuplicate(array $duplicate, int $index): void
    {
        echo "\n  #{$index} - {$duplicate['type']}\n";
        echo "  Archivo: {$duplicate['file']}\n";
        echo "  Longitud: {$duplicate['length']} líneas\n";
        echo "  Ocurrencias: " . count($duplicate['occurrences']) . "\n\n";
        
        if ($duplicate['type'] === 'method_sequence') {
            echo "  Secuencia de métodos:\n";
            foreach ($duplicate['methods'] as $i => $method) {
                echo "    " . ($i + 1) . ". \$this->{$method}()\n";
            }
            echo "\n";
            
            echo "  Ubicaciones:\n";
            foreach ($duplicate['occurrences'] as $i => $location) {
                echo "    Ocurrencia #" . ($i + 1) . ": líneas {$location['start_line']}-{$location['end_line']}\n";
            }
        } elseif ($duplicate['type'] === 'code_block') {
            echo "  Ubicaciones:\n";
            foreach ($duplicate['occurrences'] as $i => $location) {
                echo "    Ocurrencia #" . ($i + 1) . ": líneas {$location['start_line']}-{$location['end_line']}\n";
            }
            
            echo "\n  Vista previa del código:\n";
            $preview = array_slice($duplicate['content'], 0, min(5, count($duplicate['content'])));
            foreach ($preview as $line) {
                echo "    " . $line . "\n";
            }
            if (count($duplicate['content']) > 5) {
                echo "    ... (+" . (count($duplicate['content']) - 5) . " líneas más)\n";
            }
        }
        
        echo "\n";
    }
    
    /**
     * Obtiene estadísticas
     */
    public function getStats(): array
    {
        return $this->stats;
    }
}

// ============================================================================
// EJECUCIÓN DEL SCRIPT
// ============================================================================

// Verificar argumentos
if ($argc < 2) {
    echo "❌ Error: Debes especificar un archivo o directorio.\n\n";
    echo "Uso:\n";
    echo "  php {$argv[0]} <archivo.php|directorio>\n\n";
    echo "Ejemplos:\n";
    echo "  php {$argv[0]} includes/Core/BatchProcessor.php\n";
    echo "  php {$argv[0]} includes/Core/\n";
    echo "  php {$argv[0]} includes/\n\n";
    exit(1);
}

$path = $argv[1];

// Verificar que la ruta existe
if (!file_exists($path)) {
    echo "❌ Error: La ruta no existe: {$path}\n";
    exit(1);
}

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║                    DETECTOR DE CÓDIGO DUPLICADO                              ║\n";
echo "║                          Verial Integration Plugin                           ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

try {
    // Configuración
    $config = [
        'min_sequence_length' => 3,
        'min_similarity' => 0.85,
        'ignore_comments' => true,
        'ignore_whitespace' => true,
        'detect_method_calls' => true,
        'detect_blocks' => true,
    ];
    
    // Crear detector y analizar
    $detector = new DuplicateCodeDetector($config);
    
    $startTime = microtime(true);
    $detector->analyze($path);
    $endTime = microtime(true);
    
    // Generar reporte
    $detector->generateReport();
    
    // Tiempo de ejecución
    $executionTime = round($endTime - $startTime, 2);
    echo "⏱️  Tiempo de ejecución: {$executionTime} segundos\n\n";
    
} catch (Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    echo "   Archivo: {$e->getFile()}\n";
    echo "   Línea: {$e->getLine()}\n";
    exit(1);
}

