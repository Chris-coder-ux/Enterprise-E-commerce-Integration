# 🧪 Guía de Tests en Entorno de Desarrollo (Local)

Esta guía explica cómo ejecutar los tests de la arquitectura en dos fases usando **Local by Flywheel** (o similar).

## 📋 Requisitos Previos

1. **Local configurado**:
   - WordPress instalado y funcionando en Local
   - WooCommerce instalado y activo
   - Plugin Mi Integración API activo
   - Conexión a API de Verial configurada y funcionando

2. **Acceso a Local**:
   - Terminal de Local abierta (Open Site Shell)
   - O acceso a la terminal del sistema donde está Local
   - Permisos para ejecutar WP-CLI o scripts PHP

## 🚀 Ejecución de Tests en Local

### ⚡ Opción Más Fácil: Desde el Panel de Administración

Si Local no abre la shell, puedes ejecutar los tests directamente desde WordPress:

1. **Ir al panel de administración de WordPress**
2. **Navegar a**: `Mi Integración API → Tests de Desarrollo`
3. **Ejecutar Fase 1**: Clic en "🚀 Ejecutar Fase 1" (configura número de productos)
4. **Esperar resultados**: Los resultados se mostrarán en la misma página
5. **Ejecutar Fase 2**: Después de Fase 1, clic en "🚀 Ejecutar Fase 2"

**Ventajas**:
- ✅ No necesitas terminal
- ✅ Resultados visibles inmediatamente
- ✅ Interfaz amigable
- ✅ Verificación automática de resultados

---

### Test 1: Fase 1 - Sincronización de Imágenes

Este test verifica que las imágenes se sincronizan correctamente desde Verial y se guardan en la media library de WordPress.

#### Opción A: Desde el Panel de Administración (Recomendado si no tienes terminal)

Ver sección "Opción Más Fácil" arriba.

#### Opción B: Usando WP-CLI desde Local

1. **Abrir terminal de Local**:
   - En Local, haz clic en tu sitio
   - Clic en "Open Site Shell" o "Open Terminal"
   - O usa la terminal integrada de Local

2. **Navegar al directorio del plugin**:
   ```bash
   cd wp-content/plugins/mi-integracion-api
   ```

3. **Ejecutar el test**:
   ```bash
   # Test con 10 productos (default)
   wp eval-file scripts/test-desarrollo-fase1.php
   
   # O especificando número de productos y batch size
   wp eval-file scripts/test-desarrollo-fase1.php -- 10 10
   ```

#### Opción B: Desde línea de comandos PHP

1. **Abrir terminal de Local** (igual que arriba)

2. **Navegar al directorio del plugin**:
   ```bash
   cd wp-content/plugins/mi-integracion-api
   ```

3. **Ejecutar con PHP**:
   ```bash
   # Test con 10 productos (default)
   php scripts/test-desarrollo-fase1.php
   
   # O especificando parámetros
   php scripts/test-desarrollo-fase1.php 10 10
   ```

#### Opción C: Desde la terminal del sistema

Si prefieres usar la terminal del sistema (fuera de Local):

```bash
# Navegar a la ruta donde Local guarda los sitios
# En macOS: ~/Local Sites/nombre-del-sito/app/public
# En Windows: C:\Users\Usuario\Local Sites\nombre-del-sitio\app\public
# En Linux: ~/Local Sites/nombre-del-sitio/app/public

cd "~/Local Sites/nombre-del-sitio/app/public/wp-content/plugins/mi-integracion-api"
php scripts/test-desarrollo-fase1.php 10 10
```

#### Parámetros

- **Primer parámetro** (opcional): Número de productos a procesar (default: 10)
- **Segundo parámetro** (opcional): Tamaño de batch (default: 10)

#### Ejemplo de Salida

```
═══════════════════════════════════════════════════════════
🧪 TEST EN DESARROLLO: Fase 1 - Sincronización de Imágenes
═══════════════════════════════════════════════════════════

📋 Configuración del Test:
   - Productos a procesar: 10
   - Tamaño de batch: 10

✅ Componentes inicializados correctamente

🔍 Obteniendo IDs de productos desde Verial...
   - Total de productos encontrados: 1500
   - Productos para test: 10

🚀 Iniciando sincronización de imágenes...
─────────────────────────────────────
   Procesando producto ID: 123...
      ✅ Procesado: 3 imágenes (duplicados: 0)
   ...

─────────────────────────────────────
📊 RESULTADOS:
   - Productos procesados: 10
   - Errores: 0
   - Duplicados detectados: 2
   - Tiempo total: 45.23 segundos
   - Memoria usada: 125.50 MB
   - Tiempo promedio por producto: 4.52 segundos

🔍 Verificando imágenes en media library...
   - Producto 123: 3 imágenes
   ...
   - Total de imágenes en media library: 28

🔍 Verificando metadatos...
   - _verial_article_id: 28 attachments
   - _verial_image_hash: 28 attachments
   - _verial_image_order: 28 attachments

═══════════════════════════════════════════════════════════
✅ TEST COMPLETADO
═══════════════════════════════════════════════════════════
✅ ÉXITO: Fase 1 ejecutada correctamente
```

---

### Test 2: Fase 2 - Sincronización de Productos

Este test verifica que los productos se sincronizan correctamente y que las imágenes se asignan desde la media library.

#### Opción A: Desde el Panel de Administración (Recomendado si no tienes terminal)

Ver sección "Opción Más Fácil" arriba.

#### Opción B: Usando WP-CLI desde Local

1. **Abrir terminal de Local** (igual que en Fase 1)

2. **Navegar al directorio del plugin**:
   ```bash
   cd wp-content/plugins/mi-integracion-api
   ```

3. **Ejecutar el test**:
   ```bash
   # Test con 10 productos (default)
   wp eval-file scripts/test-desarrollo-fase2.php
   
   # O especificando número de productos y batch size
   wp eval-file scripts/test-desarrollo-fase2.php -- 10 10
   ```

#### Opción B: Desde línea de comandos PHP

1. **Abrir terminal de Local**

2. **Navegar al directorio del plugin**:
   ```bash
   cd wp-content/plugins/mi-integracion-api
   ```

3. **Ejecutar con PHP**:
   ```bash
   # Test con 10 productos (default)
   php scripts/test-desarrollo-fase2.php
   
   # O especificando parámetros
   php scripts/test-desarrollo-fase2.php 10 10
   ```

#### Parámetros

- **Primer parámetro** (opcional): Número de productos a procesar (default: 10)
- **Segundo parámetro** (opcional): Tamaño de batch (default: 10)

#### Ejemplo de Salida

```
═══════════════════════════════════════════════════════════
🧪 TEST EN DESARROLLO: Fase 2 - Sincronización de Productos
═══════════════════════════════════════════════════════════

📋 Configuración del Test:
   - Productos a procesar: 10
   - Tamaño de batch: 10

✅ Componentes inicializados correctamente

🔍 Verificando imágenes en media library...
   - Imágenes encontradas en media library: 28
   ✅ Imágenes disponibles para asignación

🚀 Iniciando sincronización de productos...
─────────────────────────────────────
   Procesando productos del 1 al 10...

─────────────────────────────────────
📊 RESULTADOS:
   - Éxito: ✅ Sí
   - Productos procesados: 10
   - Errores: 0
   - Saltados: 0
   - Tiempo total: 12.45 segundos
   - Memoria usada: 45.20 MB
   - Tiempo promedio por producto: 1.25 segundos

🔍 Verificando asignación de imágenes a productos...
   - Productos con imágenes: 10
   - Productos sin imágenes: 0
   - Total de imágenes asignadas: 28

🔍 Verificando timeouts en transacciones...
   ✅ No se encontraron errores de timeout

🔍 Verificando consumo de memoria...
   - Memoria actual: 125.50 MB
   - Memoria pico: 145.30 MB
   - Límite de memoria: 256M

═══════════════════════════════════════════════════════════
✅ TEST COMPLETADO
═══════════════════════════════════════════════════════════
✅ ÉXITO: Fase 2 ejecutada correctamente
   - Productos sincronizados: ✅
   - Imágenes asignadas: ✅
   - Sin timeouts: ✅
   - Memoria optimizada: ✅
```

---

## ✅ Checklist de Verificación

### Después de Fase 1

- [ ] Imágenes procesadas y guardadas en media library
- [ ] Metadatos correctos (`_verial_article_id`, `_verial_image_hash`, `_verial_image_order`)
- [ ] No hay errores en los logs
- [ ] Consumo de memoria razonable
- [ ] Tiempo de procesamiento aceptable

### Después de Fase 2

- [ ] Productos sincronizados correctamente
- [ ] Productos tienen imágenes asignadas
- [ ] No hay timeouts en transacciones
- [ ] Consumo de memoria optimizado
- [ ] Duplicados detectados y reutilizados

---

## 🔍 Verificaciones Manuales

### Verificar Imágenes en Media Library

```bash
# Usar WP-CLI para verificar attachments
wp post list --post_type=attachment --meta_key=_verial_article_id --format=count
```

### Verificar Productos con Imágenes

```bash
# Listar productos con imágenes
wp post list --post_type=product --format=table --fields=ID,post_title,meta:_verial_product_id
```

### Revisar Logs

```bash
# Ver logs recientes
tail -f wp-content/uploads/mi-integracion-api/logs/*.log
```

---

## ⚠️ Solución de Problemas

### Error: "No se pudo cargar WordPress"

**Solución**: 
- Asegúrate de ejecutar el script desde la terminal de Local (Open Site Shell)
- O desde el directorio correcto del plugin
- Verifica que estás en: `wp-content/plugins/mi-integracion-api`

### Error: "Plugin no está activo"

**Solución**: Activa el plugin desde WordPress Admin o WP-CLI:
```bash
# Desde la terminal de Local
wp plugin activate mi-integracion-api
```

### Error: "WP-CLI no encontrado"

**Solución**: 
- En Local, siempre usa la terminal integrada (Open Site Shell)
- WP-CLI está preconfigurado en Local
- Si usas terminal del sistema, asegúrate de estar en el directorio correcto

### Error: "Permisos denegados"

**Solución**: 
- En Local, normalmente no hay problemas de permisos
- Si ocurre, verifica que el usuario tiene permisos de lectura/escritura
- En Local, los permisos suelen estar configurados automáticamente

### Advertencia: "No se encontraron imágenes en media library"

**Solución**: Ejecuta primero la Fase 1 antes de la Fase 2.

### Errores de Timeout

**Solución**: 
- Aumenta el tamaño de batch
- Verifica la conexión a la API de Verial
- Revisa los logs para más detalles

---

## 📊 Interpretación de Resultados

### Tiempo de Procesamiento

- **Aceptable**: < 5 segundos por producto
- **Lento**: 5-10 segundos por producto
- **Muy lento**: > 10 segundos por producto

### Consumo de Memoria

- **Aceptable**: < 200 MB por batch
- **Alto**: 200-500 MB por batch
- **Muy alto**: > 500 MB por batch

### Tasa de Éxito

- **Excelente**: 100% productos procesados sin errores
- **Buena**: 95-99% productos procesados
- **Aceptable**: 90-94% productos procesados
- **Problema**: < 90% productos procesados

---

## 🎯 Próximos Pasos

Después de ejecutar los tests en desarrollo:

1. **Si todos los tests pasan**: Proceder con despliegue en producción
2. **Si hay errores**: Revisar logs y corregir problemas
3. **Si hay warnings**: Evaluar si son críticos o pueden esperar

---

## 📝 Notas Importantes

- ✅ **Local es perfecto para estos tests**: Tienes WordPress completo, WooCommerce y acceso a la API
- ⚠️ Estos tests modifican la base de datos y la media library de Local
- 💾 Haz backup de Local antes de ejecutar los tests (Local tiene función de backup)
- 🔌 Los tests procesan productos reales de Verial (necesitas conexión a internet)
- ⏱️ El tiempo de ejecución depende del número de productos y la velocidad de la API
- 🎯 Empieza con pocos productos (10) y luego aumenta gradualmente

## 💡 Consejos para Local

1. **Backup antes de empezar**:
   - En Local, ve a tu sitio → "Backup" → "Create Backup"
   - O exporta la base de datos manualmente

2. **Ver logs en tiempo real**:
   - Los logs del plugin están en: `wp-content/uploads/mi-integracion-api/logs/`
   - Puedes verlos desde el explorador de archivos de Local

3. **Reiniciar si es necesario**:
   - Si algo falla, puedes restaurar el backup de Local fácilmente
   - O resetear la base de datos desde Local

4. **Probar incrementalmente**:
   - Empieza con 1 producto
   - Luego 5 productos
   - Finalmente 10 productos
   - Solo después prueba con más

