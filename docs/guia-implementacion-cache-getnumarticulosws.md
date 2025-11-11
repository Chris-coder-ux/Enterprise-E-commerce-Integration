# Guía de Implementación Paso a Paso: Caché para GetNumArticulosWS

## 📋 Pre-requisitos

### Verificaciones Obligatorias ANTES de Iniciar

#### ✅ Verificación 1: Leer Contexto Existen

**Archivo**: `includes/Core/BatchProcessor.php`

1. **Leer línea 2121-2126** - Código actual:
```php
// 1.1 GetNumArticulosWS - CANTIDAD TOTAL (CRÍTICO)
$total_productos_response = $this->apiConnector->get('GetNumArticulosWS');
if (!$total_productos_response->isSuccess()) {
    throw new Exception('Error crítico obteniendo cantidad total de productos: ' . $total_productos_response->getMessage());
}
$batch_data['total_productos'] = $total_productos_response->getData();
```

2. **Leer línea 2325-2339** - Patrón a seguir (GetCategoriasWS):
```php
$batch_data['categorias'] = $this->getCachedGlobalData('categorias', function() {
    $categorias_response = $this->apiConnector->get('GetCategoriasWS');
    
    if (!$categorias_response->isSuccess()) {
        $this->getLogger()->error('Error obteniendo categorías de API', [...]);
        return [];
    }
    
    return $categorias_response->getData();
}, $this->getGlobalDataTTL('categorias'));
```

3. **Leer línea 2463-2510** - Verificar que `getCachedGlobalData()` existe y funciona

4. **Leer línea 2515-2528** - Verificar método `getGlobalDataTTL()`

#### ✅ Verificación 2: Verificar Uso de Datos

**Leer línea 2750** para entender cómo se usa `batch_data['total_productos']`:
```php
'total_productos_disponibles' => $batch_data['total_productos'] ?? 0,
```

**Conclusión**: El código acepta cualquier formato (usa null coalescing), pero debe mantener compatibilidad.

#### ✅ Verificación 3: Verificar CacheConfig

**Archivo**: `includes/Core/CacheConfig.php`  
**Línea**: 187

Verificar que existe:
```php
'GetNumArticulosWS' => 'dynamic_data',  // = 1 hora (3600 segundos)
```

Y que el método existe en línea 165:
```php
public static function get_endpoint_cache_ttl(string $endpoint_name): int
```

---

## 🔧 Implementación Paso a Paso

### Paso 1: Modificar `prepare_complete_batch_data()`

**Archivo**: `includes/Core/BatchProcessor.php`  
**Línea**: 2121-2126  
**Acción**: Reemplazar código

#### 🔍 Contexto ANTES (Leer 5 líneas antes y después):

```php
2116|        try {
2117|            // ApiCallOptimizer eliminado - usando llamadas directas a API
2118|            
2119|            // === PASO 1: OBTENER DATOS CRÍTICOS ===
2120|            
2121|            // 1.1 GetNumArticulosWS - CANTIDAD TOTAL (CRÍTICO)
2122|            $total_productos_response = $this->apiConnector->get('GetNumArticulosWS');
2123|            if (!$total_productos_response->isSuccess()) {
2124|                throw new Exception('Error crítico obteniendo cantidad total de productos: ' . $total_productos_response->getMessage());
2125|            }
2126|            $batch_data['total_productos'] = $total_productos_response->getData();
2127|
2128|            // 1.2 GetStockArticulosWS - STOCK SIMPLIFICADO CON CACHÉ
```

#### ✏️ Modificación:

**Reemplazar líneas 2121-2126 con**:

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

#### ✅ Verificación POST-MODIFICACIÓN:

1. ✅ Verificar que la línea 2128 sigue siendo `// 1.2 GetStockArticulosWS...`
2. ✅ Verificar que no hay errores de sintaxis
3. ✅ Verificar que el formato de datos es compatible:
   - Antes: `$total_productos_response->getData()` → array
   - Después: `getCachedGlobalData()` → retorna lo mismo que el callback, que es `$response->getData()` → array
   - **Conclusión**: ✅ Compatible

---

### Paso 2: Agregar Configuración de TTL

**Archivo**: `includes/Core/BatchProcessor.php`  
**Línea**: ~2517 (dentro del array `$ttl_config`)  
**Acción**: Agregar entrada al array

#### 🔍 Contexto ANTES (Leer 3 líneas antes y después):

```php
2515|    private function getGlobalDataTTL(string $data_type): int 
2516|    {
2517|        $ttl_config = [
2518|            'categorias' => 3600,    // 1 hora - cambia poco
2519|            'fabricantes' => 7200,   // 2 horas - casi estático
2520|            'colecciones' => 7200,   // 2 horas - casi estático
2521|            'cursos' => 14400,       // 4 horas - muy estático
2522|            'asignaturas' => 14400,  // 4 horas - muy estático
2523|            'campos_configurables' => 14400, // 4 horas - muy estático
2524|            'categorias_web' => 3600 // 1 hora - cambia poco
2525|        ];
2526|        
2527|        return $ttl_config[$data_type] ?? 3600; // Default 1 hora
2528|    }
```

#### ✏️ Modificación:

**Opción RECOMENDADA (usar CacheConfig)**:

1. **Agregar al inicio del array `$ttl_config`** (después de línea 2517):
```php
$ttl_config = [
    'total_productos' => \MiIntegracionApi\Core\CacheConfig::get_endpoint_cache_ttl('GetNumArticulosWS'), // ✅ Consulta CacheConfig
    'categorias' => 3600,    // 1 hora - cambia poco
    // ... resto sin cambios ...
];
```

2. **Modificar línea 2527** para usar CacheConfig en el default:
```php
return $ttl_config[$data_type] ?? \MiIntegracionApi\Core\CacheConfig::get_default_ttl();
```

**Opción ALTERNATIVA (valor hardcodeado, consistente con otros)**:

Solo agregar al array:
```php
$ttl_config = [
    'total_productos' => 3600,     // 1 hora - según CacheConfig::get_endpoint_cache_ttl('GetNumArticulosWS')
    'categorias' => 3600,
    // ... resto sin cambios ...
];
```

#### ✅ Verificación POST-MODIFICACIÓN:

1. ✅ Verificar que el array tiene comas correctas
2. ✅ Verificar que el namespace `\MiIntegracionApi\Core\CacheConfig` es correcto
3. ✅ Verificar que el método `get_endpoint_cache_ttl()` existe en CacheConfig
4. ✅ Verificar sintaxis PHP (paréntesis balanceados, comas, etc.)

---

### Paso 3: Verificaciones de Integridad

#### Verificación 3.1: Formato de Datos

**Test mental**:
- Antes: `$batch_data['total_productos']` = resultado de `$response->getData()`
- Después: `$batch_data['total_productos']` = resultado de `getCachedGlobalData()` que internamente retorna lo que retorna el callback, que es `$response->getData()`
- **Conclusión**: ✅ Mismo formato

**Verificar línea 2750**: 
```php
'total_productos_disponibles' => $batch_data['total_productos'] ?? 0,
```
- El `?? 0` maneja cualquier caso (null, false, 0)
- **Conclusión**: ✅ Compatible

#### Verificación 3.2: Manejo de Errores

**Antes**: Lanza `Exception` inmediatamente si `!$response->isSuccess()`

**Después**: 
- Si hay error en el callback, lanza `Exception`
- `getCachedGlobalData()` no captura la Exception, se propaga
- **Conclusión**: ✅ Mismo comportamiento

#### Verificación 3.3: Consistencia con Otros Endpoints

**Comparar con GetCategoriasWS** (línea 2325-2339):
- ✅ Usa `getCachedGlobalData()` ✅
- ✅ Usa `getGlobalDataTTL()` ✅
- ⚠️ Diferencia: GetCategoriasWS retorna `[]` en error, GetNumArticulosWS lanza Exception
- **Conclusión**: Es apropiado mantener Exception porque es "CRÍTICO" según el comentario

#### Verificación 3.4: No Afectar Otros Usos

**Sync_Manager::count_verial_products()** (línea 2262):
- ✅ Usa `GetNumArticulosWS` pero con parámetros `fecha` y `hora`
- ✅ No usa `getCachedGlobalData()` (contexto diferente)
- ✅ Tiene su propia lógica de caché o no usa caché
- **Conclusión**: ✅ No se ve afectado

**GetNumArticulosWS endpoint REST**:
- ✅ Ya tiene caché implementado
- ✅ Contexto diferente (REST API vs batch processing)
- **Conclusión**: ✅ No se ve afectado

---

## 🧪 Testing Manual

### Test 1: Cache Miss (Primera Llamada)

**Escenario**: Primera sincronización después del cambio
**Resultado Esperado**:
1. ✅ Llamada HTTP a `GetNumArticulosWS` debe ejecutarse
2. ✅ Datos deben guardarse en caché
3. ✅ `batch_data['total_productos']` debe tener el valor correcto

**Cómo verificar**:
- Logs deben mostrar: `[CACHE] Cache miss para total_productos`
- Logs de API deben mostrar la llamada HTTP
- Verificar en logs que se guarda en caché

### Test 2: Cache Hit (Segunda Llamada)

**Escenario**: Segunda sincronización dentro del TTL
**Resultado Esperado**:
1. ✅ NO debe haber llamada HTTP a `GetNumArticulosWS`
2. ✅ Datos deben venir del caché
3. ✅ `batch_data['total_productos']` debe tener el valor correcto

**Cómo verificar**:
- Logs deben mostrar: `[CACHE] Cache hit para total_productos`
- NO debe aparecer llamada HTTP en logs de API
- Verificar tiempo de ejecución (debe ser más rápido)

### Test 3: Error Handling

**Escenario**: API retorna error
**Resultado Esperado**:
1. ✅ Debe lanzar Exception con mensaje descriptivo
2. ✅ NO debe guardar datos en caché
3. ✅ El batch debe marcarse como fallido

**Cómo verificar**:
- Exception debe contener: "Error crítico obteniendo cantidad total de productos: [mensaje]"
- NO debe aparecer en logs de caché guardando datos

---

## 📊 Checklist Final de Implementación

- [ ] **Preparación**:
  - [ ] ✅ Leer contexto completo (líneas 2121-2126 y 2325-2339)
  - [ ] ✅ Verificar que `getCachedGlobalData()` existe
  - [ ] ✅ Verificar que `getGlobalDataTTL()` existe
  - [ ] ✅ Verificar que `CacheConfig::get_endpoint_cache_ttl()` existe

- [ ] **Implementación**:
  - [ ] ✅ Modificar línea 2121-2126 con código nuevo
  - [ ] ✅ Agregar `'total_productos'` a `getGlobalDataTTL()`
  - [ ] ✅ Verificar sintaxis PHP (sin errores)
  - [ ] ✅ Verificar que comas y paréntesis están correctos

- [ ] **Verificación Post-Implementación**:
  - [ ] ✅ Formato de datos compatible verificado
  - [ ] ✅ Manejo de errores verificado
  - [ ] ✅ Otros usos NO afectados verificado
  - [ ] ✅ Consistencia con patrón existente verificado

- [ ] **Testing**:
  - [ ] ✅ Test Cache Miss ejecutado y verificado
  - [ ] ✅ Test Cache Hit ejecutado y verificado
  - [ ] ✅ Test Error Handling ejecutado y verificado

---

## 🚨 Errores Comunes a Evitar

1. **❌ NO agregar `use` statement innecesario**
   - CacheConfig ya está disponible globalmente o se usa fully qualified name

2. **❌ NO cambiar el formato de datos**
   - Mantener `$response->getData()` tal cual

3. **❌ NO modificar el manejo de errores**
   - Mantener `throw new Exception(...)` para errores críticos

4. **❌ NO duplicar código de caché**
   - Usar `getCachedGlobalData()` existente, NO crear nuevo método

5. **❌ NO modificar otros lugares**
   - Solo modificar línea 2122 y `getGlobalDataTTL()`
   - NO tocar Sync_Manager ni endpoint REST

---

**Versión**: 1.0  
**Última Actualización**: 2025-01-29  
**Estado**: ✅ Listo para Ejecución

