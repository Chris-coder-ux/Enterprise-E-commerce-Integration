# 📊 Manual de Usuario - Dashboard Mi Integración API

## 📋 Índice
1. [Introducción](#introducción)
2. [Acceso al Dashboard](#acceso-al-dashboard)
3. [Estructura del Dashboard](#estructura-del-dashboard)
4. [Navegación Lateral](#navegación-lateral)
5. [Sección Principal](#sección-principal)
6. [Métricas del Sistema](#métricas-del-sistema)
7. [Sincronización de Productos](#sincronización-de-productos)
8. [Recomendaciones del Sistema](#recomendaciones-del-sistema)
9. [Acciones Rápidas](#acciones-rápidas)
10. [Configuración](#configuración)
11. [Solución de Problemas](#solución-de-problemas)
12. [Preguntas Frecuentes](#preguntas-frecuentes)

---

## 🎯 Introducción

El **Dashboard Mi Integración API** es el panel de control principal del plugin Enterprise E-commerce Integration que conecta tu tienda WooCommerce con el sistema ERP Verial. Este dashboard te permite:

- **Monitorear** el estado general del sistema en tiempo real
- **Sincronizar** productos, clientes y pedidos entre plataformas
- **Gestionar** configuraciones y ajustes del sistema
- **Diagnosticar** problemas y optimizar el rendimiento
- **Controlar** procesos de sincronización masiva

### 🎨 Características Principales
- **Interfaz Unificada**: Sidebar colapsible con navegación intuitiva
- **Monitoreo en Tiempo Real**: Métricas actualizadas automáticamente
- **Sincronización Inteligente**: Sistema de lotes optimizado
- **Diagnóstico Automático**: Detección proactiva de problemas
- **Temas Personalizables**: Soporte para temas claro, oscuro y por defecto

---

## 🚪 Acceso al Dashboard

### Requisitos Previos
- WordPress 6.0 o superior
- WooCommerce activo y funcional
- PHP 8.0 o superior
- Permisos de administrador

### Pasos para Acceder
1. Inicia sesión en tu panel de administración de WordPress
2. En el menú lateral izquierdo, busca **"Mi Integración API"**
3. Haz clic en **"Dashboard"** para acceder al panel principal

**Ruta directa**: `wp-admin/admin.php?page=mi-integracion-api`

---

## 🏗️ Estructura del Dashboard

El dashboard está organizado en dos secciones principales:

### 📱 Sidebar Unificado (Izquierda)
- **Navegación Principal**: Enlaces a todas las secciones del plugin
- **Acciones Rápidas**: Botones para operaciones comunes
- **Configuración**: Ajustes de tema y precisión
- **Búsqueda**: Buscador de elementos del menú

### 📊 Contenido Principal (Derecha)
- **Banner Informativo**: Información visual del sistema
- **Estado del Sistema**: Indicadores de salud general
- **Sincronización Masiva**: Controles para sincronización de productos
- **Métricas del Sistema**: Tarjetas con estadísticas clave
- **Recomendaciones**: Sugerencias automáticas del sistema

---

## 🧭 Navegación Lateral

### 📍 Menú Principal

| Icono | Sección | Descripción |
|-------|---------|-------------|
| 🏠 | **Dashboard** | Panel principal con métricas y controles |
| 🔍 | **Detección Automática** | Herramientas de detección y análisis |
| 🛒 | **Sincronización de Pedidos** | Gestión de pedidos entre plataformas |
| 🌐 | **Endpoints** | Configuración de conexiones API |
| ⚡ | **Caché** | Gestión del sistema de caché |
| 🔄 | **Reintentos** | Configuración del sistema de reintentos |
| 📈 | **Monitoreo de Memoria** | Análisis de uso de memoria |

### 🔧 Acciones Rápidas

| Botón | Función | Descripción |
|-------|---------|-------------|
| 🔄 **Sincronizar** | Inicia sincronización inmediata | Ejecuta sincronización de productos |
| 🔃 **Actualizar** | Actualiza datos del dashboard | Refresca métricas y estadísticas |
| 📥 **Exportar** | Exporta datos del sistema | Genera reportes en formato CSV/JSON |
| ⚙️ **Config** | Acceso a configuración | Abre panel de ajustes avanzados |

### 🎨 Configuración Visual

#### Selector de Tema
- **Por Defecto**: Tema estándar de WordPress
- **Oscuro**: Tema oscuro para uso nocturno
- **Claro**: Tema claro optimizado para luz

#### Precisión de Datos
- **Rango**: 0-4 decimales
- **Valor por defecto**: 2 decimales
- **Uso**: Controla la precisión de porcentajes y métricas

---

## 📊 Sección Principal

### 🎪 Banner Informativo

El banner superior muestra:
- **Título**: "Sincronización Automática"
- **Descripción**: Funcionalidad principal del plugin
- **Logo Visual**: Representación gráfica de la integración
- **Animación**: Sincronización entre Verial y WooCommerce

### 🏥 Estado General del Sistema

#### Indicadores de Salud
- **🟢 Saludable**: Sistema funcionando correctamente
- **🟡 Atención**: Requiere monitoreo
- **🟠 Advertencia**: Problemas detectados
- **🔴 Crítico**: Acción inmediata requerida

#### Información Mostrada
- **Estado General**: Evaluación automática del sistema
- **Última Verificación**: Timestamp del último diagnóstico
- **Problemas Detectados**: Contador de issues activos

---

## 📈 Métricas del Sistema

### 🧠 Estado de Memoria
- **Porcentaje de Uso**: Memoria actual vs límite configurado
- **Estado**: Saludable, Alto, Crítico
- **Mensaje**: Descripción del estado actual

### 🔄 Sistema de Reintentos
- **Tasa de Éxito**: Porcentaje de operaciones exitosas
- **Estado**: Excelente, Moderado, Bajo, Crítico
- **Políticas**: Configuración por tipo de operación

### ⚡ Sincronización
- **Estado**: En progreso, Completada, Error
- **Progreso**: Porcentaje de elementos procesados
- **Mensaje**: Descripción detallada del estado

### 📦 Productos Sincronizados
- **Total**: Número de productos sincronizados
- **Fuente**: Base de datos de WooCommerce
- **Caché**: Actualización cada 5 minutos

### ❌ Errores Recientes
- **Contador**: Errores en la última sincronización
- **Tipo**: Errores de conexión, validación, etc.
- **Historial**: Últimos errores registrados

### ⏰ Última Sincronización
- **Fecha y Hora**: Timestamp de la última sincronización
- **Formato**: dd/mm/yyyy hh:mm
- **Estado**: "Nunca" si no hay sincronizaciones previas

---

## 🔄 Sincronización de Productos

### 🎛️ Controles de Sincronización

#### Selector de Lote
- **Rango**: 1-200 productos por lote
- **Opciones**: 1, 5, 10, 20, 50, 100, 200
- **Valor por defecto**: Configurado en BatchSizeHelper
- **Restricciones**: Límites mínimos y máximos configurables

#### Botón de Sincronización
- **Estado**: Habilitado/Deshabilitado según sincronización activa
- **Confirmación**: Diálogo de confirmación antes de iniciar
- **Progreso**: Indicador visual del progreso

### 📊 Barra de Progreso

#### Información Mostrada
- **Progreso**: Porcentaje completado
- **Elementos**: Procesados/Total
- **Tiempo**: Duración estimada restante
- **Errores**: Contador de errores durante el proceso

#### Controles de Progreso
- **Mostrar/Ocultar Detalles**: Toggle para información detallada
- **Cancelar Sincronización**: Botón para detener el proceso
- **Actualización**: Polling automático cada 5 segundos

### 🔒 Sistema de Locks

#### Protección contra Duplicados
- **Lock Global**: Previene múltiples sincronizaciones simultáneas
- **Verificación PID**: Comprueba que el proceso esté activo
- **Limpieza Automática**: Libera locks obsoletos después de 30 minutos

#### Estados de Lock
- **Activo**: Sincronización en curso
- **Obsoleto**: Lock sin proceso activo
- **Liberado**: Sin sincronización activa

---

## 💡 Recomendaciones del Sistema

### 🎯 Tipos de Recomendaciones

#### 🔴 Críticas
- **Memoria Crítica**: Uso de memoria > 80%
- **Sistema de Reintentos Crítico**: Tasa de éxito < 60%
- **Sincronización Fallida**: Errores en proceso de sync

#### 🟠 Altas
- **Memoria Alta**: Uso de memoria > 60%
- **Problemas de Conexión**: Errores de API frecuentes

#### 🟡 Medias
- **Optimización de Caché**: Mejoras de rendimiento
- **Configuración de Lotes**: Ajustes de tamaño de lote

#### 🟢 Bajas
- **Sistema Saludable**: Funcionamiento correcto
- **Mantenimiento Preventivo**: Tareas de optimización

### 🛠️ Acciones Disponibles

Cada recomendación incluye botones de acción:
- **Ver Dashboard Específico**: Enlace a sección relevante
- **Ejecutar Acción**: Botón para resolver el problema
- **Ver Logs**: Acceso a registros de errores
- **Configurar**: Enlace a configuración relacionada

---

## ⚡ Acciones Rápidas

### 🔄 Sincronizar Ahora
- **Función**: Inicia sincronización inmediata de productos
- **Confirmación**: Diálogo de confirmación requerido
- **Progreso**: Seguimiento en tiempo real
- **Cancelación**: Posibilidad de detener el proceso

### 🔃 Actualizar Datos
- **Función**: Refresca todas las métricas del dashboard
- **Alcance**: Métricas de memoria, reintentos, sincronización
- **Frecuencia**: Manual o automática cada 30 segundos
- **Caché**: Limpia caché de métricas obsoletas

### 📥 Exportar Datos
- **Formatos**: CSV, JSON, XML
- **Datos**: Métricas, logs, configuración
- **Filtros**: Por fecha, tipo de evento, severidad
- **Descarga**: Archivo generado automáticamente

### ⚙️ Configuración
- **Acceso**: Panel de configuración avanzada
- **Secciones**: API, Sincronización, Caché, Reintentos
- **Validación**: Verificación de parámetros
- **Guardado**: Persistencia de configuración

---

## ⚙️ Configuración

### 🎨 Configuración Visual

#### Selector de Tema
```php
// Opciones disponibles
'default' => 'Por Defecto'
'dark'    => 'Oscuro'
'light'   => 'Claro'
```

#### Precisión de Datos
- **Rango**: 0-4 decimales
- **Aplicación**: Porcentajes, métricas numéricas
- **Persistencia**: Guardado en opciones de WordPress

### 🔍 Búsqueda en Menú
- **Campo**: Input de búsqueda en sidebar
- **Alcance**: Elementos del menú de navegación
- **Filtrado**: Búsqueda en tiempo real
- **Accesibilidad**: Soporte para lectores de pantalla

---

## 🔧 Solución de Problemas

### 🚨 Problemas Comunes

#### Sincronización No Inicia
**Síntomas**: Botón deshabilitado, mensaje de error
**Soluciones**:
1. Verificar conexión a API de Verial
2. Comprobar configuración de sesión
3. Revisar logs de errores
4. Limpiar locks obsoletos

#### Métricas No Se Actualizan
**Síntomas**: Datos obsoletos, contadores incorrectos
**Soluciones**:
1. Hacer clic en "Actualizar Datos"
2. Limpiar caché del navegador
3. Verificar configuración de polling
4. Revisar logs de AJAX

#### Memoria Crítica
**Síntomas**: Indicador rojo, recomendaciones críticas
**Soluciones**:
1. Aumentar límite de memoria PHP
2. Reducir tamaño de lote de sincronización
3. Optimizar configuración de caché
4. Revisar plugins conflictivos

#### Errores de Reintentos
**Síntomas**: Tasa de éxito baja, errores frecuentes
**Soluciones**:
1. Ajustar configuración de reintentos
2. Verificar estabilidad de conexión
3. Revisar timeouts de API
4. Optimizar políticas de reintento

### 📋 Checklist de Diagnóstico

#### Verificaciones Básicas
- [ ] WooCommerce activo y funcional
- [ ] Conexión a API de Verial estable
- [ ] Configuración de sesión válida
- [ ] Permisos de administrador correctos

#### Verificaciones Avanzadas
- [ ] Límites de memoria PHP adecuados
- [ ] Configuración de timeouts apropiada
- [ ] Sistema de caché funcionando
- [ ] Logs sin errores críticos

---

## ❓ Preguntas Frecuentes

### 🔄 Sincronización

**P: ¿Cuánto tiempo toma sincronizar todos los productos?**
R: Depende del número de productos y tamaño de lote. Con 100 productos por lote, aproximadamente 1-2 minutos por cada 1000 productos.

**P: ¿Puedo cancelar una sincronización en progreso?**
R: Sí, usa el botón "Cancelar Sincronización" en la barra de progreso. El sistema liberará los locks automáticamente.

**P: ¿Qué pasa si se interrumpe la conexión durante la sincronización?**
R: El sistema de reintentos automáticos reintentará la operación. Los locks se liberarán después de 30 minutos de inactividad.

### 📊 Métricas

**P: ¿Con qué frecuencia se actualizan las métricas?**
R: Las métricas se actualizan automáticamente cada 30 segundos cuando el dashboard está abierto.

**P: ¿Por qué muestra "Nunca" en última sincronización?**
R: Esto indica que no se ha ejecutado ninguna sincronización desde la instalación del plugin.

**P: ¿Cómo se calcula el porcentaje de memoria?**
R: Se calcula como (memoria actual / límite configurado) × 100.

### ⚙️ Configuración

**P: ¿Cómo cambio el tema del dashboard?**
R: Usa el selector de tema en la sección de configuración del sidebar.

**P: ¿Qué significa la precisión de datos?**
R: Controla el número de decimales mostrados en porcentajes y métricas numéricas.

**P: ¿Se guardan mis preferencias de configuración?**
R: Sí, todas las configuraciones se guardan en la base de datos de WordPress.

### 🚨 Problemas

**P: ¿Qué hago si el dashboard no carga?**
R: Verifica que WooCommerce esté activo, revisa los logs de errores y asegúrate de tener permisos de administrador.

**P: ¿Cómo reporto un error del sistema?**
R: Usa la sección de logs para exportar información de errores y contacta al soporte técnico.

**P: ¿Puedo usar el dashboard en dispositivos móviles?**
R: Sí, el dashboard es responsive y se adapta a diferentes tamaños de pantalla.

---

## 📞 Soporte Técnico

### 📧 Contacto
- **Email**: soporte@verialerp.com
- **Web**: https://www.verialerp.com
- **Documentación**: Manual completo disponible en el plugin

### 📋 Información para Soporte
Al contactar soporte, incluye:
- Versión del plugin
- Versión de WordPress y WooCommerce
- Logs de errores relevantes
- Descripción detallada del problema
- Pasos para reproducir el error

---

## 📝 Changelog

### Versión 2.0.0
- ✅ Dashboard unificado con sidebar colapsible
- ✅ Sistema de métricas en tiempo real
- ✅ Sincronización masiva optimizada
- ✅ Diagnóstico automático del sistema
- ✅ Temas personalizables
- ✅ Sistema de recomendaciones inteligentes

### Versión 1.0.0
- ✅ Dashboard básico con métricas esenciales
- ✅ Sincronización de productos
- ✅ Sistema de logs y errores
- ✅ Configuración básica

---

*Este manual está actualizado para la versión 2.0.0 del plugin Mi Integración API. Para la versión más reciente, consulta la documentación oficial.*
