# 🔍 Detector de Productos Duplicados - Guía de Instalación

## 📋 Descripción

Script de WordPress con interfaz gráfica para detectar y gestionar productos duplicados en WooCommerce. Incluye:

- ✅ Detección de SKUs duplicados
- ✅ Productos sin SKU
- ✅ SKUs problemáticos (ID_unknown, VERIAL_*, etc.)
- ✅ Visualización de todos los productos
- ✅ Opciones para fusionar o eliminar duplicados
- ✅ Exportación de reportes en CSV
- ✅ Estadísticas en tiempo real

---

## 🚀 Instalación

### Opción 1: Como Plugin Normal

1. **Subir archivos**:
   ```bash
   # Crear directorio del plugin
   mkdir -p wp-content/plugins/verial-duplicate-detector
   
   # Copiar archivos
   cp scripts/detectar-duplicados-productos.php wp-content/plugins/verial-duplicate-detector/
   cp scripts/detectar-duplicados-js.js wp-content/plugins/verial-duplicate-detector/
   ```

2. **Activar el plugin**:
   - Ir a WordPress Admin → Plugins
   - Buscar "Detector de Duplicados - Verial"
   - Activar

3. **Acceder a la herramienta**:
   - Ir a WooCommerce → Detectar Duplicados

### Opción 2: Como Must-Use Plugin (Recomendado)

1. **Subir archivos**:
   ```bash
   # Crear directorio mu-plugins si no existe
   mkdir -p wp-content/mu-plugins
   
   # Copiar archivos
   cp scripts/detectar-duplicados-productos.php wp-content/mu-plugins/
   cp scripts/detectar-duplicados-js.js wp-content/mu-plugins/
   ```

2. **Modificar el archivo PHP** para incluir el JS:
   ```php
   // En la función get_js(), cambiar:
   private function get_js() {
       return file_get_contents(__DIR__ . '/detectar-duplicados-js.js');
   }
   
   // Por:
   private function get_js() {
       $js_file = __DIR__ . '/detectar-duplicados-js.js';
       if (file_exists($js_file)) {
           return file_get_contents($js_file);
       }
       return '';
   }
   ```

3. **El plugin se activa automáticamente** (no requiere activación manual)

---

## 🎯 Uso

### 1. Ver Estadísticas

Al acceder a la página, verás automáticamente:
- Total de productos
- SKUs duplicados
- Productos sin SKU
- SKUs problemáticos

### 2. Detectar Duplicados

1. Haz clic en **"🔍 Detectar Duplicados"**
2. Espera a que se complete el análisis
3. Revisa los resultados en las pestañas

### 3. Pestañas Disponibles

#### **SKUs Duplicados**
- Lista de SKUs que aparecen en múltiples productos
- Opciones para fusionar o eliminar duplicados

#### **Sin SKU**
- Productos que no tienen SKU asignado
- Enlaces para editar cada producto

#### **SKUs Problemáticos**
- Productos con SKUs generados automáticamente
- SKUs con formato `ID_*` o `VERIAL_*`

#### **Todos los Productos**
- Lista completa de productos
- Búsqueda por SKU, nombre o ID

### 4. Gestionar Duplicados

#### **Fusionar Productos**
1. Haz clic en **"🔀 Fusionar"** en un SKU duplicado
2. Se mantendrá el producto más antiguo
3. Se transferirán datos del producto más nuevo si están vacíos
4. Se eliminarán los productos duplicados

#### **Eliminar Duplicados**
1. Haz clic en **"🗑️ Eliminar Duplicados"** en un SKU duplicado
2. Confirma la acción
3. Se mantendrá el primer producto
4. Se eliminarán permanentemente los demás

⚠️ **Advertencia**: La eliminación es permanente y no se puede deshacer.

### 5. Exportar Reporte

1. Haz clic en **"📥 Exportar Reporte"**
2. Se descargará un archivo CSV con:
   - Todos los duplicados detectados
   - Productos sin SKU
   - SKUs problemáticos

---

## 🔧 Requisitos

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.4+
- Permisos de administrador de WooCommerce

---

## 📊 Queries SQL Usadas

El script utiliza las siguientes queries optimizadas:

### Contar Productos Totales
```sql
SELECT COUNT(*) FROM wp_posts WHERE post_type = 'product'
```

### Contar Productos sin SKU
```sql
SELECT COUNT(DISTINCT p.ID)
FROM wp_posts p
LEFT JOIN wp_postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
WHERE p.post_type = 'product'
  AND (pm.meta_value IS NULL OR pm.meta_value = '')
```

### Detectar SKUs Duplicados
```sql
SELECT 
    pm.meta_value as sku,
    COUNT(*) as count,
    GROUP_CONCAT(p.ID ORDER BY p.ID) as product_ids
FROM wp_postmeta pm
INNER JOIN wp_posts p ON pm.post_id = p.ID
WHERE pm.meta_key = '_sku'
  AND pm.meta_value != ''
  AND p.post_type = 'product'
GROUP BY pm.meta_value
HAVING COUNT(*) > 1
ORDER BY count DESC
```

### Detectar SKUs Problemáticos
```sql
SELECT p.ID, p.post_title, pm.meta_value as sku
FROM wp_posts p
INNER JOIN wp_postmeta pm ON p.ID = pm.post_id
WHERE p.post_type = 'product'
  AND pm.meta_key = '_sku'
  AND (pm.meta_value LIKE 'ID_%' 
       OR pm.meta_value LIKE 'VERIAL_%' 
       OR pm.meta_value = 'ID_unknown')
```

---

## 🛡️ Seguridad

- ✅ Verificación de nonces en todas las peticiones AJAX
- ✅ Verificación de permisos (`manage_woocommerce`)
- ✅ Sanitización de todos los inputs
- ✅ Validación de parámetros antes de procesar

---

## 🐛 Solución de Problemas

### El plugin no aparece en el menú

1. Verificar que WooCommerce está activo
2. Verificar permisos de usuario
3. Revisar logs de WordPress para errores

### No se detectan duplicados

1. Verificar que hay productos en la base de datos
2. Revisar que los productos tienen SKU
3. Verificar permisos de base de datos

### Errores AJAX

1. Verificar que JavaScript está habilitado
2. Revisar consola del navegador para errores
3. Verificar que el nonce es correcto

---

## 📝 Notas

- El script limita los resultados a 100 registros por categoría para mejor rendimiento
- Las acciones de eliminación son **permanentes** y no se pueden deshacer
- Se recomienda hacer backup de la base de datos antes de limpiar duplicados masivamente

---

## 🔗 Relacionado

- `docs/PROBLEMA-DUPLICADOS-PRODUCTOS-SKU.md` - Análisis del problema
- `docs/SOLUCION-ERROR-ACTION-SCHEDULER-TIMEOUT.md` - Soluciones relacionadas


