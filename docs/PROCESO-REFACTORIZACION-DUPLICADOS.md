# 🔄 Proceso de Refactorización de Duplicados

## Principio Fundamental

**SIEMPRE verificar que no existe código similar en el plugin antes de crear métodos nuevos.**

## Proceso en 3 Pasos

### Paso 1: Búsqueda Exhaustiva

Antes de crear cualquier método helper, realizar estas búsquedas:

1. **Buscar helpers existentes**:
   ```bash
   glob_file_search: **/Helpers/*.php
   glob_file_search: **/Utils*.php
   ```

2. **Buscar métodos similares por nombre**:
   ```bash
   grep -r "function.*[nombre_similar]" includes/
   ```

3. **Búsqueda semántica en el código**:
   ```bash
   codebase_search: "¿Qué métodos existen para [funcionalidad similar]?"
   ```

4. **Revisar clases relacionadas**:
   - Revisar traits que la clase usa
   - Revisar clases padre si existe herencia
   - Revisar helpers relacionados por dominio

### Paso 2: Análisis de Reutilización

Para cada método encontrado, evaluar:

1. **¿Es accesible desde la clase actual?**
   - ¿Es `public` o `protected`?
   - ¿Requiere dependencias que la clase actual tiene?

2. **¿Puede adaptarse?**
   - ¿Solo necesita parámetros adicionales?
   - ¿Puede extenderse sin modificar el original?

3. **¿Es del mismo dominio?**
   - ¿Misma responsabilidad?
   - ¿Mismo contexto de uso?

### Paso 3: Decisión y Documentación

#### Opción A: Reutilizar Existente ✅

Si se encuentra código reutilizable:
- **Refactorizar para usar el método existente**
- **Documentar la decisión** en el código:
  ```php
  // ✅ REFACTORIZADO: Usa [Clase]::[método]() existente en lugar de duplicar
  ```

#### Opción B: Crear Nuevo (Justificado) ⚠️

Si NO existe código reutilizable:
- **Documentar por qué se crea nuevo**:
  ```php
  /**
   * NOTA: Se verificó que no existe un helper general reutilizable:
   * - [método similar] en [clase] es privado/específico de [contexto]
   * - No hay métodos en [helpers] para [funcionalidad]
   * - Este método es [privado/público] y usa la infraestructura existente
   */
  ```

## Ejemplos Aplicados

### ✅ Ejemplo 1: Reutilización (Duplicado #4)

**Antes**: Crear método `buildErrorResponse()` nuevo
**Después**: Refactorizar para usar `ResponseFactory::error()` existente

**Documentación**:
```php
// ✅ REFACTORIZADO: Usa ResponseFactory existente en lugar de crear método nuevo
$response = ResponseFactory::error(...);
```

### ⚠️ Ejemplo 2: Creación Justificada (Duplicado #11)

**Búsqueda realizada**:
- ✅ Revisado `MainPluginAccessor::logMainPluginError()` - privado y específico
- ✅ Revisado `Logger`, `Utils` - no tienen helpers para logging de excepciones
- ✅ Revisado helpers de error handling - no hay helpers generales

**Decisión**: Crear `logException()` privado porque:
- No existe helper general reutilizable
- Es específico de BatchProcessor
- Usa infraestructura existente (`LoggerBasic`)

**Documentación añadida**:
```php
/**
 * NOTA: Se verificó que no existe un helper general reutilizable en el plugin:
 * - logMainPluginError() en MainPluginAccessor es privado y específico de ese trait
 * - No hay métodos en Logger/Utils para logging estructurado de excepciones
 * - Este método es privado de BatchProcessor y usa la infraestructura existente
 */
```

## Checklist Pre-Creación

Antes de crear cualquier método helper nuevo:

- [ ] Búsqueda en `includes/Helpers/`
- [ ] Búsqueda en clases relacionadas (traits, padres)
- [ ] Búsqueda semántica en codebase
- [ ] Verificación de accesibilidad (public/protected)
- [ ] Evaluación de adaptabilidad
- [ ] Documentación de decisión

## Mejoras Futuras

Considerar para futuras refactorizaciones:

1. **Extraer helpers generales** cuando se repita el patrón en múltiples clases
2. **Crear helpers en `Utils.php`** para funcionalidades reutilizables en todo el plugin
3. **Proponer mejoras** a helpers existentes si pueden generalizarse

