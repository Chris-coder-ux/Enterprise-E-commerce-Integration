# 🧹 Propuestas de Mejora: Limpieza Proactiva Durante Sincronización

## 📋 Resumen Ejecutivo

Análisis y propuestas de mejora para optimizar la limpieza proactiva de caché durante el proceso de sincronización, integrando el nuevo sistema de caché hot/cold y mejorando la eficiencia.

---

## 🎯 Mejoras Propuestas

### 1. **Integración de Migración Hot→Cold Durante Sincronización**

**Problema Actual:**
- La migración hot→cold solo se ejecuta en `clean_expired_cache()`
- No se aprovecha durante sincronizaciones largas para liberar memoria

**Solución:**
- Ejecutar migración hot→cold durante limpiezas periódicas de sincronización
- Migrar datos de baja frecuencia a cold cache para liberar espacio en hot cache

**Beneficios:**
- Libera memoria de hot cache durante sincronizaciones largas
- Mejora rendimiento al mantener solo datos frecuentes en memoria
- Reduce presión sobre límite global de caché

---

### 2. **Optimización de Frecuencia Basada en Métricas de Memoria**

**Problema Actual:**
- Frecuencia fija: cada 10 productos (ImageSyncManager)
- No se adapta al uso real de memoria

**Solución:**
- Frecuencia adaptativa basada en:
  - Uso de memoria actual (%)
  - Tasa de crecimiento de memoria
  - Tamaño de caché actual vs límite global

**Implementación:**
```php
// Frecuencia adaptativa:
// - Memoria < 60%: cada 20 productos
// - Memoria 60-80%: cada 10 productos
// - Memoria > 80%: cada 5 productos
// - Memoria > 90%: cada producto + limpieza agresiva
```

**Beneficios:**
- Reduce overhead cuando hay memoria disponible
- Aumenta frecuencia cuando es necesario
- Mejora eficiencia general

---

### 3. **Coordinación de Limpiezas para Evitar Duplicación**

**Problema Actual:**
- Múltiples sistemas ejecutan limpiezas similares:
  - `clearMemoryPeriodically()` (cada 10 productos)
  - `clearBatchCache()` (después de cada batch)
  - `executeResourceCleanup()` (entre lotes)
  - `clearBatchSpecificData()` (cada lote)

**Solución:**
- Sistema centralizado de coordinación
- Flags para evitar ejecuciones duplicadas
- Priorización de limpiezas según contexto

**Beneficios:**
- Reduce overhead de limpiezas duplicadas
- Mejora rendimiento general
- Logging más claro

---

### 4. **Limpieza Adaptativa Según Uso de Memoria**

**Problema Actual:**
- Limpieza siempre ejecuta las mismas acciones
- No diferencia entre situaciones de alta/baja presión de memoria

**Solución:**
- Niveles de limpieza adaptativos:
  - **Ligera**: Solo garbage collection (memoria < 60%)
  - **Moderada**: GC + wp_cache_flush (memoria 60-80%)
  - **Agresiva**: GC + cache flush + migración hot→cold (memoria > 80%)
  - **Crítica**: Todo + evicción LRU + limpieza cold cache (memoria > 90%)

**Beneficios:**
- Eficiencia mejorada en situaciones normales
- Respuesta rápida en situaciones críticas
- Balance entre rendimiento y limpieza

---

### 5. **Preservar Datos Hot Cache en Limpieza Selectiva**

**Problema Actual:**
- `clearBatchSpecificData()` limpia por patrones sin considerar hot/cold
- Puede eliminar datos frecuentemente accedidos (hot cache)

**Solución:**
- Verificar `access_frequency` antes de limpiar
- Preservar datos con frecuencia >= 'medium' (hot cache)
- Limpiar solo datos con frecuencia < 'medium' (cold cache o candidatos)

**Beneficios:**
- Mantiene datos calientes en memoria
- Mejora hit rate de caché
- Reduce necesidad de re-fetch de datos frecuentes

---

### 6. **Limpieza de Cold Cache Durante Sincronización**

**Problema Actual:**
- Cold cache solo se limpia en `clean_expired_cache()`
- No se limpia durante sincronizaciones largas

**Solución:**
- Limpiar cold cache expirado durante limpiezas periódicas
- Priorizar limpieza de cold cache cuando memoria > 80%
- Integrar con rotación de caché por ventana de tiempo

**Beneficios:**
- Libera espacio en disco
- Reduce tamaño total de caché
- Mejora rendimiento de acceso a archivos

---

### 7. **Integración con LRU Durante Sincronización**

**Problema Actual:**
- LRU solo se ejecuta en `checkAndEvictIfNeeded()`
- No se aprovecha durante sincronizaciones para liberar memoria proactivamente

**Solución:**
- Ejecutar evicción LRU preventiva cuando:
  - Memoria > 75% durante sincronización
  - Tamaño de caché > 80% del límite global
  - Cada N lotes procesados (configurable)

**Beneficios:**
- Previene alcanzar límite de caché
- Libera memoria antes de que sea crítico
- Mejora estabilidad durante sincronizaciones largas

---

## 🔧 Implementación Propuesta

### Prioridad Alta (Impacto Alto, Esfuerzo Medio)

1. **Integración Hot→Cold durante sincronización** ⭐⭐⭐
2. **Frecuencia adaptativa basada en memoria** ⭐⭐⭐
3. **Preservar datos hot cache** ⭐⭐

### Prioridad Media (Impacto Medio, Esfuerzo Bajo)

4. **Coordinación de limpiezas** ⭐⭐
5. **Limpieza adaptativa según memoria** ⭐

### Prioridad Baja (Impacto Bajo, Esfuerzo Bajo)

6. **Limpieza de cold cache durante sync** ⭐
7. **LRU preventivo durante sync** ⭐

---

## 📊 Métricas Esperadas

- **Reducción de uso de memoria**: 15-25% durante sincronizaciones
- **Mejora de hit rate**: 10-15% al preservar hot cache
- **Reducción de overhead**: 20-30% con coordinación de limpiezas
- **Mejora de estabilidad**: Menos timeouts y errores de memoria

---

## 🚀 Plan de Implementación

1. **Fase 1**: Integración hot→cold + frecuencia adaptativa
2. **Fase 2**: Preservar hot cache + coordinación
3. **Fase 3**: Limpieza adaptativa + cold cache + LRU preventivo

