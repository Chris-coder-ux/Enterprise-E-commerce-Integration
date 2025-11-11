# 📋 Plan de Mejora: TTL por Endpoint

## 📊 Análisis del Estado Actual

### ✅ Lo que Existe:
1. **Configuración de TTL por Endpoint** (`mi_integracion_api_cache_config`)
   - Ubicación: `includes/Admin/CachePageView.php`
   - Formato: `['endpoint_name' => ['enabled' => 1, 'ttl' => 3600]]`
   - Endpoints configurados: GetArticulosWS, GetImagenesArticulosWS, GetCondicionesTarifaWS, etc.

2. **Método de Cálculo Automático** (`calculate_auto_ttl()`)
   - Ubicación: `includes/Admin/CachePageView.php` línea 177
   - Calcula TTL basándose en latencia y tipo de endpoint

3. **CacheManager con Soporte de TTL**
   - Método `set()` acepta parámetro `$ttl` (línea 531)
   - Usa `default_ttl` si no se especifica

### ❌ Lo que Falta:
1. **Integración en ApiConnector**
   - `makeRequest()` no usa caché actualmente
   - No lee configuración de TTL por endpoint
   - No almacena respuestas en caché

2. **Integración en Endpoints Específicos**
   - Clase `Base` tiene `CACHE_EXPIRATION` constante pero no se usa dinámicamente
   - Endpoints no leen configuración de TTL

3. **Método Helper para Obtener TTL**
   - No existe método centralizado para obtener TTL por endpoint
   - Cada componente tendría que implementar su propia lógica

---

## 🎯 Objetivos del Plan

1. ✅ Crear método centralizado para obtener TTL por endpoint
2. ✅ Integrar TTL por endpoint en `ApiConnector`
3. ✅ Integrar TTL por endpoint en endpoints específicos (clase `Base`)
4. ✅ Mantener compatibilidad con código existente
5. ✅ Aplicar TTL automáticamente cuando se almacena en caché

---

## 📝 Plan de Implementación

### Fase 1: Crear Método Helper Centralizado

#### 1.1 Agregar método en CacheManager

**Archivo**: `includes/CacheManager.php`

**Ubicación**: Después del método `getGlobalCacheSizeLimit()` (aprox. línea 4614)

```php
/**
 * ✅ NUEVO: Obtiene el TTL configurado para un endpoint específico.
 * 
 * Lee la configuración de TTL por endpoint desde las opciones de WordPress
 * y devuelve el TTL configurado, o el TTL por defecto si no está configurado.
 * 
 * @param   string  $endpoint    Nombre del endpoint (ej: 'GetArticulosWS')
 * @return  int     TTL en segundos
 * @since   1.0.0
 * 
 * @see     mi_integracion_api_cache_config Opción de WordPress que almacena la configuración
 */
public function getEndpointTTL(string $endpoint): int
{
    // Obtener configuración de TTL por endpoint
    $cache_config = get_option('mi_integracion_api_cache_config', []);
    
    // Verificar si el endpoint está configurado y habilitado
    if (isset($cache_config[$endpoint])) {
        $endpoint_config = $cache_config[$endpoint];
        
        // Verificar si está habilitado
        if (isset($endpoint_config['enabled']) && $endpoint_config['enabled'] == 1) {
            // Verificar si tiene TTL configurado
            if (isset($endpoint_config['ttl']) && is_numeric($endpoint_config['ttl'])) {
                $ttl = (int) $endpoint_config['ttl'];
                
                // Validar rango (mínimo 60 segundos, máximo 86400 segundos = 24 horas)
                $ttl = max(60, min(86400, $ttl));
                
                $this->logger->debug('TTL por endpoint obtenido', [
                    'endpoint' => $endpoint,
                    'ttl_seconds' => $ttl,
                    'ttl_hours' => round($ttl / 3600, 2),
                    'source' => 'endpoint_config'
                ]);
                
                return $ttl;
            }
        } else {
            // Endpoint deshabilitado en configuración
            $this->logger->debug('Endpoint deshabilitado en configuración de caché', [
                'endpoint' => $endpoint
            ]);
            return 0; // Retornar 0 indica que no debe cachearse
        }
    }
    
    // No hay configuración específica, usar TTL por defecto
    $default_ttl = $this->default_ttl;
    
    $this->logger->debug('Usando TTL por defecto para endpoint', [
        'endpoint' => $endpoint,
        'ttl_seconds' => $default_ttl,
        'ttl_hours' => round($default_ttl / 3600, 2),
        'source' => 'default'
    ]);
    
    return $default_ttl;
}
```

**Beneficios**:
- ✅ Método centralizado y reutilizable
- ✅ Validación de TTL (rango 60-86400 segundos)
- ✅ Logging detallado para debugging
- ✅ Soporte para endpoints deshabilitados (retorna 0)

---

### Fase 2: Integrar en ApiConnector (Opcional - Si se implementa caché en ApiConnector)

#### 2.1 Agregar caché en makeRequest()

**Archivo**: `includes/Core/ApiConnector.php`

**Ubicación**: Al inicio de `makeRequest()`, antes de hacer la solicitud HTTP

```php
private function makeRequest(string $method, string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
    // ... código existente de validación ...
    
    // ✅ NUEVO: Verificar caché antes de hacer la solicitud (solo para GET)
    if ($method === 'GET' && $this->cache_enabled) {
        $cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        
        // Generar clave de caché única basada en endpoint y parámetros
        $cache_key = $this->generateCacheKey($endpoint, $params);
        
        // Intentar obtener de caché
        $cached_response = $cache_manager->get($cache_key);
        
        if ($cached_response !== false) {
            $this->logger->debug('Respuesta obtenida de caché', [
                'endpoint' => $endpoint,
                'cache_key' => $cache_key
            ]);
            return $cached_response;
        }
    }
    
    // ... código existente de makeRequest ...
    
    // ✅ NUEVO: Almacenar respuesta en caché después de obtenerla (solo para GET exitosos)
    if ($method === 'GET' && $this->cache_enabled && $http_code === 200) {
        $cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        
        // Obtener TTL específico para el endpoint
        $endpoint_ttl = $cache_manager->getEndpointTTL($endpoint);
        
        // Solo cachear si TTL > 0 (endpoint habilitado)
        if ($endpoint_ttl > 0) {
            $cache_key = $this->generateCacheKey($endpoint, $params);
            $cache_manager->set($cache_key, $json_data, $endpoint_ttl);
            
            $this->logger->debug('Respuesta almacenada en caché', [
                'endpoint' => $endpoint,
                'cache_key' => $cache_key,
                'ttl_seconds' => $endpoint_ttl
            ]);
        }
    }
    
    return $json_data;
}

/**
 * ✅ NUEVO: Genera una clave de caché única basada en endpoint y parámetros.
 * 
 * @param   string  $endpoint    Nombre del endpoint
 * @param   array   $params      Parámetros de la solicitud
 * @return  string  Clave de caché única
 */
private function generateCacheKey(string $endpoint, array $params): string
{
    // Ordenar parámetros para consistencia
    ksort($params);
    
    // Generar hash de parámetros
    $params_hash = md5(json_encode($params));
    
    // Construir clave: api_{endpoint}_{hash_params}
    return "api_{$endpoint}_{$params_hash}";
}
```

**Nota**: Esta fase es opcional porque actualmente `ApiConnector` no implementa caché. Si se decide implementar, este sería el lugar.

---

### Fase 3: Integrar en Endpoints Específicos (Clase Base)

#### 3.1 Modificar clase Base para usar TTL dinámico

**Archivo**: `includes/Endpoints/Base.php`

**Ubicación**: Agregar método helper después de `process_verial_response()` (aprox. línea 200)

```php
/**
 * ✅ NUEVO: Obtiene el TTL configurado para este endpoint.
 * 
 * Lee la configuración de TTL por endpoint desde CacheManager.
 * Si no hay configuración específica, usa la constante CACHE_EXPIRATION.
 * 
 * @return  int     TTL en segundos
 * @since   1.0.0
 */
protected function getEndpointTTL(): int
{
    if (!defined('static::ENDPOINT_NAME') || empty(static::ENDPOINT_NAME)) {
        // Si no hay nombre de endpoint, usar constante
        return static::CACHE_EXPIRATION;
    }
    
    try {
        $cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        $endpoint_ttl = $cache_manager->getEndpointTTL(static::ENDPOINT_NAME);
        
        // Si retorna 0, significa que está deshabilitado, usar constante como fallback
        if ($endpoint_ttl === 0) {
            return static::CACHE_EXPIRATION;
        }
        
        return $endpoint_ttl;
    } catch (\Exception $e) {
        // En caso de error, usar constante como fallback
        if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('endpoint-cache');
            $logger->warning('Error obteniendo TTL por endpoint, usando constante', [
                'endpoint' => static::ENDPOINT_NAME,
                'error' => $e->getMessage(),
                'fallback_ttl' => static::CACHE_EXPIRATION
            ]);
        }
        return static::CACHE_EXPIRATION;
    }
}
```

**Beneficios**:
- ✅ Compatible con código existente (usa constante como fallback)
- ✅ Manejo de errores robusto
- ✅ Logging para debugging

---

### Fase 4: Integrar en BatchProcessor

#### 4.1 Modificar getCachedGlobalData() para usar TTL por endpoint

**Archivo**: `includes/Core/BatchProcessor.php`

**Ubicación**: Modificar método `getCachedGlobalData()` (línea 2748)

**Cambio**: En lugar de usar TTL hardcodeado, obtener TTL según tipo de dato/endpoint

```php
private function getCachedGlobalData(string $data_type, callable $fetch_callback, int $ttl = 3600): array
{
    $cache_manager = CacheManager::get_instance();
    
    // ✅ MEJORADO: Obtener TTL específico para el tipo de dato si es un endpoint conocido
    $endpoint_mapping = [
        'categorias' => 'GetCategoriasWS',
        'fabricantes' => 'GetFabricantesWS',
        'articulos' => 'GetArticulosWS',
        'imagenes' => 'GetImagenesArticulosWS',
        'condiciones_tarifa' => 'GetCondicionesTarifaWS',
        'num_articulos' => 'GetNumArticulosWS'
    ];
    
    // Si el tipo de dato mapea a un endpoint, usar TTL del endpoint
    if (isset($endpoint_mapping[$data_type])) {
        $endpoint_ttl = $cache_manager->getEndpointTTL($endpoint_mapping[$data_type]);
        if ($endpoint_ttl > 0) {
            $ttl = $endpoint_ttl;
        }
    }
    
    // ... resto del código existente ...
}
```

---

### Fase 5: Actualizar Documentación y Tests

#### 5.1 Documentar el nuevo sistema

- Actualizar `docs/ANALISIS-SISTEMAS-CACHE.md` marcando TTL por endpoint como implementado
- Agregar ejemplos de uso en comentarios PHPDoc
- Crear guía de uso en `docs/manual-usuario/`

#### 5.2 Crear tests (opcional)

- Test unitario para `CacheManager::getEndpointTTL()`
- Test de integración verificando que se aplica TTL correcto
- Test de fallback cuando no hay configuración

---

## 🔄 Flujo de Ejecución Propuesto

### Escenario 1: Llamada desde Endpoint Específico

```
1. Usuario llama a endpoint REST (ej: GetArticulosWS)
2. Endpoint ejecuta execute_restful()
3. Endpoint llama a connector->get('GetArticulosWS', $params)
4. Si hay caché, obtener TTL: cacheManager->getEndpointTTL('GetArticulosWS')
5. Almacenar respuesta con TTL específico: cacheManager->set($key, $data, $ttl)
```

### Escenario 2: Llamada Directa desde ApiConnector

```
1. Código llama directamente a apiConnector->get('GetArticulosWS', $params)
2. ApiConnector.makeRequest() verifica caché
3. Si cache miss, hace solicitud HTTP
4. Obtiene TTL: cacheManager->getEndpointTTL('GetArticulosWS')
5. Almacena respuesta con TTL específico
```

### Escenario 3: BatchProcessor con Datos Globales

```
1. BatchProcessor necesita categorías
2. Llama a getCachedGlobalData('categorias', $callback)
3. Mapea 'categorias' → 'GetCategoriasWS'
4. Obtiene TTL: cacheManager->getEndpointTTL('GetCategoriasWS')
5. Usa TTL específico en lugar de hardcoded
```

---

## ✅ Checklist de Implementación

### Fase 1: Método Helper
- [x] Agregar método `getEndpointTTL()` en `CacheManager`
- [x] Agregar validación de rango (60-86400 segundos)
- [x] Agregar logging detallado
- [x] Probar con diferentes configuraciones

### Fase 2: Integración ApiConnector (Opcional)
- [ ] Agregar verificación de caché en `makeRequest()`
- [ ] Agregar almacenamiento de caché después de respuesta
- [ ] Agregar método `generateCacheKey()`
- [ ] Probar con diferentes endpoints

### Fase 3: Integración Endpoints Base
- [x] Modificar método `get_cache_expiration()` en clase `Base`
- [x] Integrar uso de `CacheManager::getEndpointTTL()`
- [x] Mantener fallbacks para compatibilidad
- [x] Probar compatibilidad con código existente

### Fase 4: Integración BatchProcessor
- [x] Modificar `getGlobalDataTTL()` para usar TTL por endpoint
- [x] Agregar mapeo de tipos de dato a endpoints
- [x] Mantener fallbacks para compatibilidad
- [x] Probar con diferentes tipos de datos

### Fase 5: Documentación
- [x] Actualizar análisis de sistemas de caché
- [x] Agregar ejemplos de uso en código
- [ ] Crear guía de usuario (opcional)

---

## 🎯 Priorización

### Alta Prioridad (Implementar Primero):
1. ✅ **Fase 1**: Método helper centralizado
   - Es la base para todo lo demás
   - No rompe código existente
   - Fácil de testear

### Media Prioridad:
2. ✅ **Fase 3**: Integración en Endpoints Base
   - Afecta a múltiples endpoints
   - Mejora significativa en uso de caché
   - Compatible con código existente

3. ✅ **Fase 4**: Integración en BatchProcessor
   - Mejora eficiencia de sincronizaciones
   - Usa TTL correcto para datos globales

### Baja Prioridad (Opcional):
4. ⚠️ **Fase 2**: Integración en ApiConnector
   - Requiere implementar sistema de caché completo en ApiConnector
   - Puede ser complejo si hay muchas llamadas directas
   - Considerar si realmente se necesita

---

## 📊 Impacto Esperado

### Beneficios:
- ✅ **Optimización de Caché**: Cada endpoint usa TTL apropiado según su frecuencia de cambio
- ✅ **Reducción de Llamadas API**: Datos que cambian poco (categorías, fabricantes) se cachean más tiempo
- ✅ **Flexibilidad**: Administradores pueden ajustar TTL sin modificar código
- ✅ **Consistencia**: Un solo lugar para obtener TTL (método centralizado)

### Riesgos y Mitigación:
- ⚠️ **Riesgo**: Cambios en TTL pueden causar datos obsoletos
  - **Mitigación**: Validación de rango, logging, documentación clara
- ⚠️ **Riesgo**: Endpoints deshabilitados pueden causar confusión
  - **Mitigación**: Retornar 0 claramente documentado, usar fallback

---

## 🚀 Siguiente Paso

**Recomendación**: Comenzar con **Fase 1** (Método Helper Centralizado)

Esta fase:
- ✅ Es independiente y no rompe código existente
- ✅ Proporciona la base para todas las demás fases
- ✅ Es fácil de testear y validar
- ✅ Puede implementarse rápidamente

Una vez completada Fase 1, evaluar si continuar con Fase 3 y 4, o si Fase 2 (ApiConnector) es necesaria.

