# 🔍 Detector de Código Duplicado

Script PHP para detectar lógica duplicada en archivos y proyectos PHP.

## 📋 Características

- ✅ Detecta **secuencias de llamadas a métodos** duplicadas
- ✅ Identifica **bloques de código** similares
- ✅ Analiza **archivos individuales** o **directorios completos**
- ✅ Categoriza duplicados por **severidad** (crítico, alto, medio, bajo)
- ✅ **Optimizado** para archivos grandes (>8000 líneas)
- ✅ Ignora comentarios y espacios en blanco (configurable)
- ✅ Genera **reportes detallados** con estadísticas

## 🚀 Uso

### Analizar un archivo individual

```bash
php scripts/detect-duplicate-code.php includes/Core/BatchProcessor.php
```

### Analizar un directorio completo

```bash
php scripts/detect-duplicate-code.php includes/Core/
```

### Analizar todo el proyecto

```bash
php scripts/detect-duplicate-code.php includes/
```

### Con más memoria (para archivos muy grandes)

```bash
php -d memory_limit=1024M scripts/detect-duplicate-code.php includes/Core/BatchProcessor.php
```

## 📊 Ejemplo de Salida

```
╔══════════════════════════════════════════════════════════════════════════════╗
║                    DETECTOR DE CÓDIGO DUPLICADO                              ║
║                          Verial Integration Plugin                           ║
╚══════════════════════════════════════════════════════════════════════════════╝

🔍 Analizando: includes/Core/BatchProcessor.php

================================================================================
📊 REPORTE DE CÓDIGO DUPLICADO
================================================================================

📈 Estadísticas:
  • Archivos analizados: 1
  • Líneas analizadas: 8087
  • Duplicados encontrados: 676
  • Líneas que se pueden ahorrar: 3842

🔴 Severidad: CRITICAL (18 encontrados)
--------------------------------------------------------------------------------

  #1 - method_sequence
  Archivo: includes/Core/BatchProcessor.php
  Longitud: 5 líneas
  Ocurrencias: 2

  Secuencia de métodos:
    1. $this->applyDateBasedVisibility()
    2. $this->createDynamicAttributesFromAuxFields()
    3. $this->manageDynamicTaxClasses()
    4. $this->manageDynamicUnits()
    5. $this->manageOtherFields()

  Ubicaciones:
    Ocurrencia #1: líneas 3433-3446
    Ocurrencia #2: líneas 4968-4981
```

## ⚙️ Configuración

Puedes modificar el comportamiento del detector editando el array `$config` en el script:

```php
$config = [
    'min_sequence_length' => 3,      // Mínimo de líneas para considerar duplicado
    'min_similarity' => 0.85,        // Similitud mínima (85%)
    'ignore_comments' => true,       // Ignorar comentarios en comparación
    'ignore_whitespace' => true,     // Ignorar espacios en blanco
    'detect_method_calls' => true,   // Detectar llamadas a métodos duplicadas
    'detect_blocks' => true,         // Detectar bloques de código duplicados
];
```

## 🎯 Severidad de Duplicados

El script calcula la severidad basándose en:
- **Longitud del duplicado** (número de líneas)
- **Número de ocurrencias**

| Severidad | Fórmula | Descripción |
|-----------|---------|-------------|
| 🔴 **Critical** | score ≥ 50 | Requiere acción inmediata |
| 🟠 **High** | score ≥ 20 | Debería refactorizarse pronto |
| 🟡 **Medium** | score ≥ 10 | Considerar refactorizar |
| 🟢 **Low** | score < 10 | Baja prioridad |

**Fórmula de score**: `longitud × (ocurrencias - 1)`

## 💡 Estrategias de Refactorización

Cuando encuentres código duplicado, considera estas estrategias:

### 1. Extract Method (Extraer Método)

**Antes:**
```php
// En updateExistingProduct()
$this->applyDateBasedVisibility($product, $verial_product);
$this->createDynamicAttributesFromAuxFields($product, $verial_product);
$this->manageDynamicTaxClasses($product, $verial_product);

// En updateVerialProductMetadata()
$this->applyDateBasedVisibility($product, $verial_product);
$this->createDynamicAttributesFromAuxFields($product, $verial_product);
$this->manageDynamicTaxClasses($product, $verial_product);
```

**Después:**
```php
private function applyProductEnhancements(WC_Product $product, array $verial_product): void
{
    $this->applyDateBasedVisibility($product, $verial_product);
    $this->createDynamicAttributesFromAuxFields($product, $verial_product);
    $this->manageDynamicTaxClasses($product, $verial_product);
}

// Usar en ambos lugares:
$this->applyProductEnhancements($product, $verial_product);
```

### 2. Eliminar Duplicación Innecesaria

Si un método ya llama a otro que ejecuta el código duplicado, **elimina** la duplicación y usa el flujo existente.

### 3. Template Method Pattern

Para lógica similar pero con pequeñas variaciones, usa el patrón Template Method.

### 4. Composition over Duplication

Extrae la lógica común en una clase/trait compartida.

## 📈 Interpretación de Resultados

### Duplicados de Secuencias de Métodos

Indican que la misma secuencia de llamadas a métodos aparece múltiples veces. 

✅ **Solución típica**: Extraer a un método común.

### Duplicados de Bloques de Código

Indican que el mismo bloque de código (línea por línea) aparece múltiples veces.

✅ **Solución típica**: Extraer a una función/método reutilizable.

## 🛠️ Troubleshooting

### Error: Memory Limit Exhausted

Si el archivo es muy grande, aumenta el límite de memoria:

```bash
php -d memory_limit=2048M scripts/detect-duplicate-code.php archivo.php
```

### Demasiados duplicados falsos positivos

Ajusta la configuración:

```php
$config = [
    'min_sequence_length' => 5,  // Aumentar a 5 líneas mínimo
    'min_similarity' => 0.90,    // Aumentar similitud requerida a 90%
];
```

### Script muy lento

Para archivos extremadamente grandes, desactiva la detección de bloques:

```php
$config = [
    'detect_method_calls' => true,   // Mantener (más rápido)
    'detect_blocks' => false,        // Desactivar (más lento)
];
```

## 📝 Notas

- El script **no modifica** ningún archivo, solo los analiza
- Es seguro ejecutarlo en cualquier momento
- Los resultados son aproximados y requieren revisión manual
- Algunos "duplicados" pueden ser legítimos (ej: código generado, boilerplate)

## 🤝 Contribuir

Para mejorar el detector:

1. Ajusta los algoritmos de detección en `detectMethodCallSequences()` y `detectDuplicateBlocks()`
2. Añade nuevos tipos de detección (ej: detectar patrones de diseño duplicados)
3. Mejora el cálculo de severidad
4. Añade exportación de reportes a JSON/HTML

## 📚 Referencias

- [Principio DRY (Don't Repeat Yourself)](https://en.wikipedia.org/wiki/Don%27t_repeat_yourself)
- [Refactoring: Improving the Design of Existing Code](https://martinfowler.com/books/refactoring.html)
- [Code Smells](https://refactoring.guru/refactoring/smells)

