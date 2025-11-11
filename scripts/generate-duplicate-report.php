#!/usr/bin/env php
<?php
/**
 * Genera un reporte en Markdown de código duplicado
 * 
 * Uso:
 *   php scripts/generate-duplicate-report.php includes/Core/BatchProcessor.php
 * 
 * @package MiIntegracionApi
 */

// Incluir el detector de duplicados
require_once __DIR__ . '/detect-duplicate-code.php';

// Verificar argumentos
if ($argc < 2) {
    echo "❌ Error: Debes especificar un archivo.\n\n";
    echo "Uso:\n";
    echo "  php {$argv[0]} <archivo.php>\n\n";
    echo "Ejemplo:\n";
    echo "  php {$argv[0]} includes/Core/BatchProcessor.php\n\n";
    exit(1);
}

$filepath = $argv[1];

// Verificar que el archivo existe
if (!file_exists($filepath)) {
    echo "❌ Error: El archivo no existe: {$filepath}\n";
    exit(1);
}

$outputFile = 'DUPLICATE-CODE-REPORT.md';

echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════════╗\n";
echo "║             GENERADOR DE REPORTE DE CÓDIGO DUPLICADO (MD)                   ║\n";
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
    
    echo "🔍 Analizando: {$filepath}\n";
    $startTime = microtime(true);
    $duplicates = $detector->analyze($filepath);
    $endTime = microtime(true);
    $stats = $detector->getStats();
    
    echo "✅ Análisis completado en " . round($endTime - $startTime, 2) . " segundos\n";
    echo "📊 Duplicados encontrados: {$stats['duplicates_found']}\n";
    echo "📝 Generando reporte Markdown...\n\n";
    
    // Generar contenido Markdown
    $markdown = generateMarkdownReport($filepath, $duplicates, $stats);
    
    // Guardar archivo
    file_put_contents($outputFile, $markdown);
    
    echo "✅ Reporte generado exitosamente: {$outputFile}\n";
    echo "📄 Tamaño: " . number_format(strlen($markdown)) . " caracteres\n";
    echo "📋 Total de duplicados: {$stats['duplicates_found']}\n";
    echo "💾 Líneas que se pueden ahorrar: {$stats['potential_savings']}\n\n";
    
} catch (Exception $e) {
    echo "❌ Error: {$e->getMessage()}\n";
    echo "   Archivo: {$e->getFile()}\n";
    echo "   Línea: {$e->getLine()}\n";
    exit(1);
}

/**
 * Genera el contenido del reporte en Markdown
 */
function generateMarkdownReport(string $filepath, array $duplicates, array $stats): string
{
    $md = [];
    
    // Encabezado
    $md[] = "# 🔍 Reporte de Código Duplicado";
    $md[] = "";
    $md[] = "**Archivo analizado**: `{$filepath}`";
    $md[] = "**Fecha de análisis**: " . date('Y-m-d H:i:s');
    $md[] = "";
    
    // Resumen ejecutivo
    $md[] = "## 📊 Resumen Ejecutivo";
    $md[] = "";
    $md[] = "| Métrica | Valor |";
    $md[] = "|---------|-------|";
    $md[] = "| **Archivos analizados** | {$stats['files_analyzed']} |";
    $md[] = "| **Líneas analizadas** | " . number_format($stats['lines_analyzed']) . " |";
    $md[] = "| **Duplicados encontrados** | {$stats['duplicates_found']} |";
    $md[] = "| **Líneas que se pueden ahorrar** | {$stats['potential_savings']} |";
    $md[] = "";
    
    // Agrupar por severidad
    $bySeverity = [];
    foreach ($duplicates as $duplicate) {
        $severity = $duplicate['severity'];
        if (!isset($bySeverity[$severity])) {
            $bySeverity[$severity] = [];
        }
        $bySeverity[$severity][] = $duplicate;
    }
    
    // Ordenar por severidad
    $severityOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    uksort($bySeverity, fn($a, $b) => $severityOrder[$a] <=> $severityOrder[$b]);
    
    // Tabla de contenidos
    $md[] = "## 📑 Tabla de Contenidos";
    $md[] = "";
    
    foreach ($bySeverity as $severity => $dups) {
        $icon = match($severity) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
        };
        $count = count($dups);
        $severityLabel = strtoupper($severity);
        $md[] = "- [{$icon} {$severityLabel} ({$count} duplicados)](#-{$severity})";
    }
    
    $md[] = "";
    $md[] = "---";
    $md[] = "";
    
    // Detalles por severidad
    foreach ($bySeverity as $severity => $duplicatesList) {
        $icon = match($severity) {
            'critical' => '🔴',
            'high' => '🟠',
            'medium' => '🟡',
            'low' => '🟢',
        };
        
        $severityLabel = strtoupper($severity);
        $count = count($duplicatesList);
        
        $md[] = "## {$icon} {$severityLabel}";
        $md[] = "";
        $md[] = "**Total encontrados**: {$count}";
        $md[] = "";
        
        if ($severity === 'critical') {
            $md[] = "> ⚠️ **ACCIÓN INMEDIATA REQUERIDA** - Estos duplicados tienen alto impacto y deben ser refactorizados prioritariamente.";
            $md[] = "";
        } elseif ($severity === 'high') {
            $md[] = "> ⚡ **ALTA PRIORIDAD** - Deberían ser refactorizados en el corto plazo.";
            $md[] = "";
        }
        
        foreach ($duplicatesList as $index => $duplicate) {
            $num = $index + 1;
            $md[] = "### {$num}. " . ucfirst($duplicate['type']);
            $md[] = "";
            
            // Información básica
            $md[] = "**Archivo**: `{$duplicate['file']}`";
            $md[] = "**Longitud**: {$duplicate['length']} líneas";
            $md[] = "**Ocurrencias**: " . count($duplicate['occurrences']);
            $md[] = "";
            
            if ($duplicate['type'] === 'method_sequence') {
                // Secuencia de métodos
                $md[] = "**Secuencia de métodos**:";
                $md[] = "";
                $md[] = "```php";
                foreach ($duplicate['methods'] as $i => $method) {
                    $md[] = ($i + 1) . ". \$this->{$method}()";
                }
                $md[] = "```";
                $md[] = "";
                
                $md[] = "**Ubicaciones**:";
                $md[] = "";
                foreach ($duplicate['occurrences'] as $i => $location) {
                    $occNum = $i + 1;
                    $md[] = "- **Ocurrencia #{$occNum}**: líneas `{$location['start_line']}-{$location['end_line']}`";
                }
                $md[] = "";
                
                // Mostrar el código de cada ocurrencia
                $md[] = "<details>";
                $md[] = "<summary>Ver código completo</summary>";
                $md[] = "";
                
                foreach ($duplicate['occurrences'] as $i => $location) {
                    $occNum = $i + 1;
                    $md[] = "**Ocurrencia #{$occNum}** (líneas {$location['start_line']}-{$location['end_line']}):";
                    $md[] = "";
                    $md[] = "```php";
                    foreach ($location['code'] as $line) {
                        $md[] = $line;
                    }
                    $md[] = "```";
                    $md[] = "";
                }
                
                $md[] = "</details>";
                $md[] = "";
                
            } elseif ($duplicate['type'] === 'code_block') {
                // Bloque de código
                $md[] = "**Ubicaciones**:";
                $md[] = "";
                foreach ($duplicate['occurrences'] as $i => $location) {
                    $occNum = $i + 1;
                    $md[] = "- **Ocurrencia #{$occNum}**: líneas `{$location['start_line']}-{$location['end_line']}`";
                }
                $md[] = "";
                
                $md[] = "<details>";
                $md[] = "<summary>Ver código duplicado</summary>";
                $md[] = "";
                $md[] = "```php";
                $preview = array_slice($duplicate['content'], 0, min(10, count($duplicate['content'])));
                foreach ($preview as $line) {
                    $md[] = $line;
                }
                if (count($duplicate['content']) > 10) {
                    $md[] = "// ... (+" . (count($duplicate['content']) - 10) . " líneas más)";
                }
                $md[] = "```";
                $md[] = "";
                $md[] = "</details>";
                $md[] = "";
            }
            
            // Estrategia de refactorización sugerida
            $md[] = "**💡 Estrategia de refactorización sugerida**:";
            $md[] = "";
            
            if ($duplicate['type'] === 'method_sequence' && count($duplicate['occurrences']) === 2) {
                $md[] = "1. ✅ **Eliminar duplicación**: Una de estas secuencias ya se ejecuta dentro de la otra a través de un método intermediario";
                $md[] = "2. ✅ **Verificar flujo de llamadas**: Comprobar si el método que llama a la secuencia ya está siendo invocado";
                $md[] = "3. ✅ **Eliminar código redundante**: Eliminar la secuencia duplicada innecesaria";
            } elseif ($duplicate['type'] === 'method_sequence') {
                $md[] = "1. **Extract Method**: Crear un método privado que contenga esta secuencia";
                $md[] = "2. **Reemplazar**: Sustituir todas las ocurrencias por una llamada al nuevo método";
                $md[] = "3. **Documentar**: Añadir PHPDoc explicando el propósito del método";
            } else {
                $md[] = "1. **Extract Method/Function**: Extraer el bloque a un método reutilizable";
                $md[] = "2. **Parametrizar**: Identificar las diferencias y convertirlas en parámetros";
                $md[] = "3. **Reemplazar**: Sustituir todas las ocurrencias por llamadas al nuevo método";
            }
            $md[] = "";
            
            // Ejemplo de refactorización (solo para críticos)
            if ($severity === 'critical' && $duplicate['type'] === 'method_sequence') {
                $md[] = "<details>";
                $md[] = "<summary>Ejemplo de refactorización</summary>";
                $md[] = "";
                $md[] = "**Antes** (código duplicado):";
                $md[] = "";
                $md[] = "```php";
                foreach ($duplicate['methods'] as $method) {
                    $md[] = "\$this->{$method}(\$product, \$verial_product);";
                }
                $md[] = "```";
                $md[] = "";
                $md[] = "**Después** (refactorizado):";
                $md[] = "";
                $md[] = "```php";
                $md[] = "// Crear método común";
                $md[] = "private function applyProductEnhancements(WC_Product \$product, array \$verial_product): void";
                $md[] = "{";
                foreach ($duplicate['methods'] as $method) {
                    $md[] = "    \$this->{$method}(\$product, \$verial_product);";
                }
                $md[] = "}";
                $md[] = "";
                $md[] = "// Usar en ambos lugares:";
                $md[] = "\$this->applyProductEnhancements(\$product, \$verial_product);";
                $md[] = "```";
                $md[] = "";
                $md[] = "</details>";
                $md[] = "";
            }
            
            $md[] = "---";
            $md[] = "";
        }
    }
    
    // Recomendaciones finales
    $md[] = "## 💡 Recomendaciones Generales";
    $md[] = "";
    
    $critical = count($bySeverity['critical'] ?? []);
    $high = count($bySeverity['high'] ?? []);
    $total = $stats['duplicates_found'];
    $savings = $stats['potential_savings'];
    
    if ($critical > 0 || $high > 0) {
        $md[] = "### ⚠️ Acción Inmediata Requerida";
        $md[] = "";
        
        if ($critical > 0) {
            $md[] = "- **{$critical} duplicados críticos** 🔴 deben ser refactorizados **inmediatamente**";
        }
        
        if ($high > 0) {
            $md[] = "- **{$high} duplicados de alta prioridad** 🟠 deberían ser refactorizados **en el corto plazo**";
        }
        
        $md[] = "";
    }
    
    $md[] = "### 📈 Impacto Potencial";
    $md[] = "";
    $md[] = "Al refactorizar estos duplicados podrías:";
    $md[] = "";
    $md[] = "- ✅ Reducir **{$savings} líneas** de código";
    $md[] = "- ✅ Mejorar la **mantenibilidad** del código";
    $md[] = "- ✅ Reducir la probabilidad de **bugs** por inconsistencias";
    $md[] = "- ✅ Facilitar las **futuras modificaciones**";
    $md[] = "- ✅ Cumplir con el principio **DRY** (Don't Repeat Yourself)";
    $md[] = "";
    
    $md[] = "### 🛠️ Estrategias de Refactorización";
    $md[] = "";
    $md[] = "1. **Extract Method**: Extraer código común a un método reutilizable";
    $md[] = "2. **Template Method Pattern**: Para lógica similar con pequeñas variaciones";
    $md[] = "3. **Composition**: Usar composición en lugar de duplicación";
    $md[] = "4. **Traits/Clases compartidas**: Para funcionalidad común entre clases";
    $md[] = "";
    
    $md[] = "### ✅ Checklist de Refactorización";
    $md[] = "";
    $md[] = "- [ ] Priorizar duplicados críticos (🔴)";
    $md[] = "- [ ] Crear tests antes de refactorizar (si no existen)";
    $md[] = "- [ ] Refactorizar un duplicado a la vez";
    $md[] = "- [ ] Ejecutar tests después de cada refactorización";
    $md[] = "- [ ] Actualizar documentación si es necesario";
    $md[] = "- [ ] Hacer commit por cada refactorización completada";
    $md[] = "";
    
    // Pie de página
    $md[] = "---";
    $md[] = "";
    $md[] = "**Generado por**: Detector de Código Duplicado v1.0";
    $md[] = "**Fecha**: " . date('Y-m-d H:i:s');
    $md[] = "**Archivo analizado**: `{$filepath}`";
    $md[] = "";
    
    return implode("\n", $md);
}

