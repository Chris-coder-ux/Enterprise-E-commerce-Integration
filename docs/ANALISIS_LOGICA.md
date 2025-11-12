# 🔍 Herramientas de Análisis de Lógica

## 📋 Resumen

Este proyecto tiene configuradas **múltiples herramientas** para analizar la lógica del código PHP y detectar errores, problemas de tipos, null safety, y otros problemas lógicos.

## 🛠️ Herramientas Disponibles

### 1. **PHPStan** ✅ (Recomendado para análisis rápido)

**¿Qué analiza?**
- ✅ Errores de tipos (type errors)
- ✅ Null safety (posibles null references)
- ✅ Métodos y funciones no definidas
- ✅ Propiedades no definidas
- ✅ Argumentos incorrectos
- ✅ Retornos incorrectos
- ✅ Problemas con arrays e iterables

**Configuración:**
- Archivo: `phpstan.neon`
- Nivel: **5** (medio-alto)
- Paths: `includes/`, `admin/`, `api_connector/`

**Uso:**
```bash
# Análisis completo
composer phpstan

# O directamente
vendor/bin/phpstan analyse -c phpstan.neon
```

**Ventajas:**
- ⚡ Rápido
- 🎯 Buen equilibrio entre detección y falsos positivos
- 📊 Niveles configurables (0-9)

---

### 2. **Psalm** ⭐ (Recomendado para análisis profundo)

**¿Qué analiza?**
- ✅ **Todo lo de PHPStan** +
- ✅ Análisis de lógica más avanzado
- ✅ Detección de código muerto (unused code)
- ✅ Análisis de flujo de control
- ✅ Detección de condiciones imposibles
- ✅ Análisis de null safety más estricto
- ✅ Detección de tipos mixtos (mixed types)
- ✅ Análisis de arrays y objetos más preciso

**Configuración:**
- Archivo: `phpsalm.xml`
- Modo: `totallyTyped="true"` (análisis estricto)
- PHP Version: 8.1

**Uso:**
```bash
# Análisis completo (solo errores)
composer psalm

# Análisis con información adicional
composer psalm:show-info

# O directamente
vendor/bin/psalm --config=phpsalm.xml
```

**Ventajas:**
- 🔍 Análisis más profundo que PHPStan
- 🎯 Mejor detección de errores lógicos
- 🧹 Detecta código no utilizado
- 📈 Análisis de flujo de control avanzado

**Desventajas:**
- ⏱️ Más lento que PHPStan
- ⚠️ Puede generar más falsos positivos

---

### 3. **Análisis Combinado** 🚀 (Recomendado para CI/CD)

**Uso:**
```bash
# Ejecuta PHPStan y Psalm en secuencia
composer analyze:logic
```

**Ventajas:**
- ✅ Cobertura completa de análisis
- ✅ Detecta problemas que una sola herramienta podría pasar por alto
- ✅ Ideal para pre-commit hooks o CI/CD

---

## 📊 Comparación de Herramientas

| Característica | PHPStan | Psalm |
|---------------|---------|-------|
| **Velocidad** | ⚡⚡⚡ Rápido | ⚡⚡ Medio |
| **Profundidad** | ⭐⭐⭐ Medio | ⭐⭐⭐⭐⭐ Muy Profundo |
| **Null Safety** | ✅✅ Bueno | ✅✅✅ Excelente |
| **Código Muerto** | ❌ No | ✅✅ Sí |
| **Flujo de Control** | ✅✅ Bueno | ✅✅✅ Excelente |
| **Falsos Positivos** | ✅✅ Pocos | ⚠️ Algunos |
| **Configuración** | ✅✅ Fácil | ✅✅ Fácil |

---

## 🎯 Recomendaciones de Uso

### Para Desarrollo Diario
```bash
# Análisis rápido antes de commit
composer phpstan
```

### Para Análisis Profundo
```bash
# Análisis completo antes de release
composer analyze:logic
```

### Para CI/CD
```bash
# En tu pipeline de CI/CD
composer analyze:logic
```

---

## 🔧 Configuración Avanzada

### PHPStan - Aumentar Nivel

Para análisis más estricto, edita `phpstan.neon`:

```yaml
parameters:
    level: 8  # Máximo nivel (más estricto)
```

### Psalm - Ajustar Reglas

Para ajustar qué errores detectar, edita `phpsalm.xml`:

```xml
<issueHandlers>
    <!-- Cambiar errorLevel de "error" a "warning" o "info" -->
    <PossiblyNullReference errorLevel="warning"/>
</issueHandlers>
```

---

## 📝 Ejemplos de Problemas Detectados

### PHPStan detecta:
```php
// ❌ Error: Call to undefined method
$user->getEmai(); // Typo: debería ser getEmail()

// ❌ Error: Null reference
$user = null;
echo $user->name; // PHPStan detecta posible null

// ❌ Error: Tipo incorrecto
function sum(int $a, int $b): int {
    return $a + $b;
}
sum("1", "2"); // PHPStan detecta string en lugar de int
```

### Psalm detecta (además de lo anterior):
```php
// ❌ Error: Código muerto
function unusedFunction() {
    return true;
}
// Nunca se llama

// ❌ Error: Condición imposible
if ($x > 10 && $x < 5) {
    // Psalm detecta que esto nunca puede ser true
}

// ❌ Error: Null safety avanzado
function getName(?User $user): string {
    return $user->name; // Psalm detecta que $user puede ser null
}
```

---

## 🚀 Integración con Codacy

Ambas herramientas están integradas con **Codacy**:

- ✅ **PHPStan** se ejecuta automáticamente en cada commit
- ✅ **Lizard** (complejidad ciclomática) también está configurado
- ✅ Los resultados aparecen en el dashboard de Codacy

---

## 📚 Recursos Adicionales

- [PHPStan Documentation](https://phpstan.org/user-guide/getting-started)
- [Psalm Documentation](https://psalm.dev/docs/)
- [Codacy Dashboard](https://app.codacy.com)

---

## ✅ Checklist de Uso

- [ ] Ejecutar `composer phpstan` antes de cada commit
- [ ] Ejecutar `composer analyze:logic` antes de cada release
- [ ] Revisar resultados en Codacy después de cada push
- [ ] Corregir errores críticos inmediatamente
- [ ] Documentar supresiones de errores cuando sea necesario

