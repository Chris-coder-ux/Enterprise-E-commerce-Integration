# Plan de Acción Detallado: Implementar Caché para GetNumArticulosWS

## 📋 Resumen Ejecutivo

**Objetivo**: Implementar caché con TTL para `GetNumArticulosWS` en `BatchProcessor::prepare_complete_batch_data()` para evitar llamadas HTTP redundantes que saturan el servidor API.

**Solución**: Usar el patrón existente `getCachedGlobalData()` que ya se utiliza para otros endpoints globales como `GetCategoriasWS`, `GetFabricantesWS`, etc.

**Impacto Esperado**: Reducir de 26+ llamadas HTTP a 1 llamada durante la sincronización de 1300 productos (26 batches × 50 productos/batch).

---

## 🔍 Auditoría de Contexto - Verificaciones Previas

### Verificación 1: Ubicación del Código a Modificar

✅ **Archivo**: `includes/Core/BatchProcessor.php`
✅ **Método**: `prepare_complete_batch_data()`
✅ **Línea actual**: 2122-2126
✅ **Patrón existente**: Líneas 2325-2389 (otros endpoints usando `getCachedGlobalData()`)

### Verificación 2: Uso de `batch_data['total_productos']`

**Ubicaciones encontradas**:
1. ✅ **Línea 2126**: Asignación directa (`$batch_data['total_productos'] = ...`)
2. ✅ **Línea 2750**: Uso en resumen (`'total_productos_disponibles' => $batch_data['total_productos'] ?? 0`)
3. ❌ **Línea 2657**: NO es uso de `batch_data['total_productos']`, es variable local `count($productos)`

**Verificación de duplicidades**:
- ✅ Solo hay 1 lugar donde se ASIGNA `batch_data['total_productos']`
- ✅ Solo hay 1 lugar donde se LEE `batch_data['total_productos']`
- ✅ No hay conflictos con otros usos

### Verificación 3: Configuración de TTL Existente

**En `CacheConfig.php`**:
- ✅ `GetNumArticulosWS` → `'dynamic_data'` → 1 hora (3600 segundos)
- ✅ Configurable mediante `CacheConfig::get_endpoint_cache_ttl('GetNumArticulosWS')`

**En `getGlobalDataTTL()`** (línea 2515):
- ✅ Método existe y funciona
- ✅ NO tiene `'total_productos'` configurado aún
- ✅ Usa default de 3600 si no encuentra el tipo

### Verificación 4: Patrón de Implementación Existente

**Endpoints que ya usan `getCachedGlobalData()`**:
1. ✅ `GetCategoriasWS` (línea 2325-2339)
2. ✅ `GetFabricantesWS` (línea 2341-2344)
3. ✅ `GetColeccionesWS` (línea 2346-2349)
4. ✅ `GetCursosWS` (línea 2351-2354)
5. ✅ `GetAsignaturasWS` (línea 2356-2359)
6. ✅ `GetCategoriasWebWS` (línea 2362-2384)
7. ✅ `GetCamposConfigurablesArticulosWS` (línea 2386-2389)

**Patrón identificado**:
```php
$batch_data['tipo_dato'] = $this->getCachedGlobalData('tipo_dato', function() {
    $response = $this->apiConnector->get('EndpointWS');
    if (!$response->isSuccess()) {
        return []; // o throw Exception según criticidad
    }
    return $response->getData();
}, $this->getGlobalDataTTL('tipo_dato'));
```

### Verificación 5: Otros Usos de GetNumArticulosWS

**Archivos que usan `GetNumArticulosWS`**:

1. ✅ **`BatchProcessor.php` línea 2122**: **ESTE ES EL OBJETIVO**
   - Contexto: Preparación de batch
   - Sin parámetros
   - **DEBE usar caché**

2. ⚠️ **`Sync_Manager.php` línea 2262**: `count_verial_products()`
   - Contexto: Sincronización incremental/con filtros
   - Con parámetros `fecha` y `hora`
   - **NO debe usar el mismo caché** (diferentes parámetros = diferentes resultados)
   - Ya tiene su propia lógica de conteo

3. ✅ **`GetNumArticulosWS.php` (endpoint REST)**: 
   - Ya tiene caché implementado (línea 163: `get_cached_data()`)
   - Contexto diferente (REST API)
   - **No afecta** a nuestro cambio

**Conclusión**: Solo 1 lugar necesita modificación.

### Verificación 6: Formato de Datos Esperado

**Estructura actual**:
```php
$total_productos_response = $this->apiConnector->get('GetNumArticulosWS');
$batch_data['total_productos'] = $total_productos_response->getData();
```

**Estructura esperada del API**:
- `SyncResponseInterface` → `getData()` → Retorna array con `'Numero'` o directamente el número

**Verificación en código**:
- ✅ Línea 2750: `$batch_data['total_productos'] ?? 0` - usa null coalescing, acepta cualquier tipo
- ✅ Otros usos verifican `is_array()` antes de procesar

**Conclusión**: El formato de datos es compatible.

---

## 📝 Plan de Acción Detallado

### Fase 1: Preparación y Análisis ✅ COMPLETADA

- [x] Analizar arquitectura de caché existente
- [x] Identificar todos los usos de `GetNumArticulosWS`
- [x] Verificar compatibilidad de formato de datos
- [x] Documentar patrón existente en `getCachedGlobalData()`

### Fase 2: Modificación del Código

#### Tarea 2.1: Modificar `prepare_complete_batch_data()`

**Archivo**: `includes/Core/BatchProcessor.php`  
**Línea**: 2121-2126  
**Contexto antes de modificar**:

```2121:2126:includes/Core/BatchProcessor.php
// 1.1 GetNumArticulosWS - CANTIDAD TOTAL (CRÍTICO)
$total_productos_response = $this->apiConnector->get('GetNumArticulosWS');
if (!$total_productos_response->isSuccess()) {
    throw new Exception('Error crítico obteniendo cantidad total de productos: ' . $total_productos_response->getMessage());
}
$batch_data['total_productos'] = $total_productos_response->getData();
```

**Verificaciones previas a la modificación**:
- ✅ Verificar que `getCachedGlobalData()` existe y funciona (línea 2463)
- ✅ Verificar que el patrón es consistente con otros endpoints
- ✅ Verificar manejo de errores en otros endpoints (ej: GetCategoriasWS lanza return [] en error)

**Código después**:

```php
// 1.1 GetNumArticulosWS - CANTIDAD TOTAL (CRÍTICO) ✅ CON CACHÉ
$batch_data['total_productos'] = $this->getCachedGlobalData('total_productos', function() {
    $response = $this->apiConnector->get('GetNumArticulosWS');
    if (!$response->isSuccess()) {
        throw new Exception('Error crítico obteniendo cantidad total de productos: ' . $response->getMessage());
    }
    return $response->getData();
}, $this->getGlobalDataTTL('total_productos'));
```

**Verificaciones después de la modificación**:
- ✅ Verificar que la estructura de datos se mantiene (mismo formato de retorno)
- ✅ Verificar que el manejo de errores es consistente (throw Exception en error crítico)
- ✅ Verificar que `batch_data['total_productos']` tiene el mismo tipo de dato

#### Tarea 2.2: Agregar `'total_productos'` a `getGlobalDataTTL()`

**Archivo**: `includes/Core/BatchProcessor.php`  
**Línea**: ~2517 (dentro del array `$ttl_config`)  
**Contexto antes de modificar**:

```2515:2528:includes/Core/BatchProcessor.php
private function getGlobalDataTTL(string $data_type): int 
{
    $ttl_config = [
        'categorias' => 3600,    // 1 hora - cambia poco
        'fabricantes' => 7200,   // 2 horas - casi estático
        'colecciones' => 7200,   // 2 horas - casi estático
        'cursos' => 14400,       // 4 horas - muy estático
        'asignaturas' => 14400,  // 4 horas - muy estático
        'campos_configurables' => 14400, // 4 horas - muy estático
        'categorias_web' => 3600 // 1 hora - cambia poco
    ];
    
    return $ttl_config[$data_type] ?? 3600; // Default 1 hora
}
```

**Verificaciones previas**:
- ✅ Verificar que `CacheConfig::get_endpoint_cache_ttl()` existe y retorna valor correcto
- ✅ Verificar que otros endpoints usan valores hardcodeados (no `CacheConfig`)
- ⚠️ **DECISIÓN**: Usar `CacheConfig` (como se recomienda) o valor hardcodeado (como otros)

**Opción A: Usar CacheConfig (RECOMENDADA)**:
```php
$ttl_config = [
    'total_productos' => \MiIntegracionApi\Core\CacheConfig::get_endpoint_cache_ttl('GetNumArticulosWS'), // ✅ Usar CacheConfig
    'categorias' => 3600,    // 1 hora - cambia poco
    // ... resto ...
];

return $ttl_config[$data_type] ?? \MiIntegracionApi\Core\CacheConfig::get_default_ttl(); // ✅ Default desde CacheConfig
```

**Opción B: Valor hardcodeado (consistente con otros)**:
```php
$ttl_config = [
    'total_productos' => 3600,     // 1 hora - según CacheConfig::get_endpoint_cache_ttl('GetNumArticulosWS')
    'categorias' => 3600,
    // ... resto ...
];
```

**Recomendación**: **Opción A** para que respete la configuración de 1800 segundos si está configurada.

### Fase 3: Verificación y Testing

#### Tarea 3.1: Verificar Consistencia de Datos

**Verificaciones**:
- ✅ `getCachedGlobalData()` retorna array cuando hay datos
- ✅ `$batch_data['total_productos']` mantiene el mismo formato antes/después
- ✅ El uso en línea 2750 sigue funcionando (`$batch_data['total_productos'] ?? 0`)

#### Tarea 3.2: Verificar Manejo de Errores

**Escenarios a verificar**:
1. ✅ Error en API: Debe lanzar Exception (igual que antes)
2. ✅ Cache hit: Debe retornar datos sin llamar API
3. ✅ Cache miss: Debe llamar API y guardar en caché
4. ✅ Cache expirado: Debe refrescar desde API

#### Tarea 3.3: Verificar que No Hay Regresiones

**Endpoints a verificar que siguen funcionando**:
- ✅ GetCategoriasWS (usa mismo patrón)
- ✅ GetFabricantesWS (usa mismo patrón)
- ✅ Otros endpoints globales

---

## 🚨 Verificaciones de Duplicidad

### ⚠️ Posibles Duplicidades a Evitar

1. **NO duplicar lógica de caché**:
   - ❌ No crear nuevo método de caché
   - ✅ Usar `getCachedGlobalData()` existente

2. **NO duplicar configuración de TTL**:
   - ❌ No hardcodear TTL si ya existe en `CacheConfig`
   - ✅ Consultar `CacheConfig::get_endpoint_cache_ttl()` siempre

3. **NO duplicar manejo de errores**:
   - ✅ Mantener el mismo patrón de Exception que ya existe

4. **NO afectar otros usos de GetNumArticulosWS**:
   - ✅ `Sync_Manager::count_verial_products()` NO se modifica (usa parámetros diferentes)
   - ✅ Endpoint REST NO se modifica (ya tiene su caché)

---

## ✅ Checklist Final Pre-Implementación

Antes de modificar código, verificar:

- [x] ✅ Ubicación exacta del código a modificar identificada (línea 2122)
- [x] ✅ Patrón existente documentado (`getCachedGlobalData()`)
- [x] ✅ Todos los usos de `batch_data['total_productos']` identificados (2 usos: asignación y lectura)
- [x] ✅ Configuración de TTL verificada (`CacheConfig::get_endpoint_cache_ttl()`)
- [x] ✅ Otros usos de `GetNumArticulosWS` identificados y NO afectados
- [x] ✅ Formato de datos compatible verificado
- [x] ✅ No hay duplicidades en el código

---

## 📊 Métricas de Éxito

**Objetivos**:
1. ✅ Reducir llamadas HTTP a `GetNumArticulosWS` de 26+ a 1 por sincronización completa
2. ✅ Mantener funcionalidad existente sin regresiones
3. ✅ Respeta TTL configurado (1800 segundos si está configurado)
4. ✅ Consistente con patrón existente de otros endpoints

**Cómo medir**:
- Logs de caché en `getCachedGlobalData()` mostrarán cache hits
- Monitoreo de llamadas HTTP a API debe reducirse significativamente
- Logs de batch deben mostrar el mismo comportamiento que antes

---

**Fecha de Creación**: 2025-01-29  
**Versión**: 1.0  
**Estado**: ✅ Listo para Implementación

