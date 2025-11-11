# 📊 Guía para Sincronización Larga y Análisis de Memoria

Esta guía te ayudará a realizar una sincronización más larga y analizar el uso de memoria del sistema.

---

## 🚀 Pasos para Realizar la Sincronización

### 1. Preparación

1. **Asegúrate de tener suficiente espacio en disco** para el log de debug
2. **Verifica la configuración de memoria de PHP**:
   ```bash
   php -i | grep memory_limit
   ```
   - Recomendado: mínimo 512MB, idealmente 1GB o más

3. **Revisa el tamaño actual del log**:
   ```bash
   # WordPress guarda el log en wp-content/debug.log
   ls -lh wp-content/debug.log
   
   # O si estás en el directorio del plugin:
   ls -lh ../../wp-content/debug.log
   ```

### 2. Iniciar la Sincronización

1. **Ve al panel de administración de WordPress**
2. **Inicia una sincronización completa** (no solo 1 lote)
3. **Deja que se ejecute** durante varios lotes
4. **NO canceles la sincronización** (para análisis completo)

### 3. Monitoreo Durante la Ejecución

Puedes monitorear el uso de memoria en tiempo real con:

```bash
# En una terminal separada, monitorea el log de WordPress
tail -f wp-content/debug.log | grep -i "memory"

# O desde el directorio del plugin:
tail -f ../../wp-content/debug.log | grep -i "memory"

# O monitorea procesos PHP
watch -n 2 'ps aux | grep php | grep -v grep'
```

### 4. Qué Observar en el Log

Busca estas entradas importantes:

#### ✅ Señales Positivas
- `Procesamiento por lotes completado` - Cada lote exitoso
- `Limpieza de memoria completada` - Limpiezas automáticas
- `usage_percentage` menor a 70% - Uso saludable

#### ⚠️ Señales de Advertencia
- `usage_percentage` entre 70-85% - Monitorear
- `Detención preventiva por memoria crítica` - Requiere atención
- `Tiempo de ejecución aumentado` - Normal, pero verificar

#### 🔴 Señales Críticas
- `usage_percentage` mayor a 85% - Crítico
- `Maximum execution time exceeded` - Timeout
- `Detención crítica durante procesamiento` - Problema grave

---

## 📊 Análisis Post-Sincronización

### 1. Ejecutar el Script de Análisis

Después de completar la sincronización, ejecuta:

```bash
php scripts/analyze-memory-usage.php
```

O especifica un archivo de log específico:

```bash
php scripts/analyze-memory-usage.php /ruta/al/debug.log
```

### 2. Revisar el Reporte Generado

El script genera un reporte en: `docs/MEMORY-USAGE-REPORT.md`

Este reporte incluye:
- ✅ Estadísticas generales de memoria
- ✅ Evolución temporal del uso de memoria
- ✅ Análisis por lote
- ✅ Efectividad de las limpiezas de memoria
- ✅ Advertencias de timeout
- ✅ Recomendaciones personalizadas

### 3. Interpretar los Resultados

#### Uso de Memoria Saludable ✅
- **Menos del 50%**: Excelente, sistema muy optimizado
- **50-70%**: Bueno, dentro de límites aceptables
- **70-85%**: Aceptable, pero monitorear

#### Uso de Memoria Preocupante ⚠️
- **85-95%**: Requiere optimización
- **Más del 95%**: Crítico, riesgo de fallo

#### Tendencias a Observar
- **Aumento gradual**: Normal durante procesamiento
- **Aumento rápido**: Posible fuga de memoria
- **Decremento después de limpiezas**: Sistema funcionando correctamente
- **Sin decremento**: Las limpiezas no son efectivas

---

## 🔍 Métricas Clave a Revisar

### 1. Memoria por Producto

Fórmula: `Memoria Final / Productos Procesados`

**Interpretación**:
- **< 1 MB/producto**: Excelente
- **1-2 MB/producto**: Bueno
- **> 2 MB/producto**: Considerar optimización

### 2. Eficiencia de Limpiezas

**Indicadores positivos**:
- Reducción de memoria después de cada limpieza
- Reducciones consistentes entre limpiezas
- Uso de memoria estable entre lotes

**Indicadores negativos**:
- Sin reducción después de limpiezas
- Aumento continuo a pesar de limpiezas
- Reducciones inconsistentes

### 3. Evolución Temporal

**Patrón saludable**:
```
Memoria Inicial → Aumento gradual → Limpieza → Reducción → Estable
```

**Patrón preocupante**:
```
Memoria Inicial → Aumento rápido → Limpieza → Sin reducción → Aumento continuo
```

### 4. Duración por Lote

**Tiempos esperados** (para 50 productos):
- **< 30 segundos**: Excelente
- **30-60 segundos**: Bueno
- **> 60 segundos**: Investigar optimización

---

## 📈 Casos de Uso para Pruebas

### Prueba Corta (Recomendada para empezar)
- **Lotes**: 5-10
- **Productos**: 250-500
- **Duración esperada**: 2-5 minutos
- **Objetivo**: Verificar comportamiento básico

### Prueba Media
- **Lotes**: 20-30
- **Productos**: 1,000-1,500
- **Duración esperada**: 10-20 minutos
- **Objetivo**: Analizar tendencias de memoria

### Prueba Larga
- **Lotes**: 50+
- **Productos**: 2,500+
- **Duración esperada**: 30+ minutos
- **Objetivo**: Detectar fugas de memoria y optimizaciones

---

## 🛠️ Troubleshooting

### Si el Uso de Memoria es Alto

1. **Reducir el tamaño del lote**:
   - Ir a configuración del plugin
   - Reducir `batch_size` de 50 a 25 o 30

2. **Aumentar límite de memoria de PHP**:
   ```php
   // En wp-config.php
   ini_set('memory_limit', '1024M');
   ```

3. **Verificar plugins conflictivos**:
   - Desactivar plugins no esenciales durante sincronización
   - Verificar si hay plugins que carguen datos en memoria

### Si Hay Timeouts

1. **Verificar que las mejoras están activas**:
   - Buscar en el log: `Tiempo de ejecución aumentado`
   - Verificar que `set_time_limit()` está funcionando

2. **Aumentar timeout de PHP**:
   ```php
   // En php.ini o .htaccess
   max_execution_time = 300
   ```

### Si Hay Fugas de Memoria

1. **Revisar logs de limpieza**:
   - Verificar que las limpiezas se ejecutan
   - Comprobar si las limpiezas reducen memoria

2. **Aumentar frecuencia de limpiezas**:
   - Modificar configuración de `MemoryManager`
   - Reducir umbral de limpieza

---

## 📝 Checklist Post-Sincronización

- [ ] Ejecutar script de análisis de memoria
- [ ] Revisar reporte generado
- [ ] Verificar que no hay advertencias críticas
- [ ] Confirmar que las limpiezas fueron efectivas
- [ ] Verificar que el uso de memoria se mantuvo estable
- [ ] Revisar si hay timeouts o errores
- [ ] Documentar cualquier anomalía encontrada

---

## 🎯 Resultados Esperados

### Después de una Sincronización Exitosa Deberías Ver:

1. **Uso de memoria estable**:
   - Oscilaciones menores a ±10MB entre lotes
   - Reducciones después de limpiezas
   - Sin aumento continuo

2. **Sin timeouts**:
   - No hay errores de "Maximum execution time exceeded"
   - Tiempos de ejecución consistentes

3. **Limpiezas efectivas**:
   - Reducciones de 5-20MB después de cada limpieza
   - Frecuencia adecuada de limpiezas (cada 1-2 lotes)

4. **Procesamiento consistente**:
   - Tiempos similares por lote
   - Sin errores en el procesamiento
   - Productos procesados correctamente

---

**Fecha de creación**: 2025-11-01  
**Última actualización**: 2025-11-01

