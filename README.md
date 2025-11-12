# 🚀 Mi Integración API

Plugin de integración completa entre **Verial** y **WooCommerce** para WordPress.

## 📋 Descripción

**Mi Integración API** es un plugin avanzado que proporciona sincronización automática bidireccional entre el sistema Verial y WooCommerce, incluyendo productos, clientes, pedidos y stock.

## ✨ Características Principales

- ✅ **Sincronización Automática**: Productos, clientes y pedidos en tiempo real
- ✅ **API REST Completa**: 45+ endpoints para integración con Verial
- ✅ **Detección Automática de Stock**: Monitoreo continuo de cambios en inventario
- ✅ **Dashboard Avanzado**: Panel de administración moderno y completo
- ✅ **Sistema de Caché Inteligente**: Optimización automática de rendimiento
- ✅ **Gestión de SSL**: Manejo avanzado de certificados y seguridad
- ✅ **Logs y Monitoreo**: Sistema completo de registro y análisis
- ✅ **Compatible con HPOS**: Soporte para High-Performance Order Storage

## 📦 Requisitos

- WordPress 6.0+ (recomendado 6.8+)
- WooCommerce 7.0+ (recomendado 9.8+)
- PHP 8.1+ (requerido por el plugin, compatible con WordPress 6.8)
- Composer (para dependencias)
- Extensión PHP cURL
- Extensión PHP OpenSSL

**Nota sobre PHP**: 
- WordPress 6.8 requiere PHP 7.4 como mínimo, pero recomienda PHP 8.0+
- Este plugin requiere PHP 8.1+ para aprovechar características modernas y mejor rendimiento
- PHP 8.1 es compatible con WordPress 6.8 y es la versión recomendada

## 🛠️ Instalación

### 1. Descargar el Plugin

```bash
git clone https://github.com/tu-usuario/mi-integracion-api.git
cd mi-integracion-api
```

### 2. Instalar Dependencias

```bash
composer install --no-dev --optimize-autoloader
```

### 3. Activar en WordPress

1. Copia el plugin a `wp-content/plugins/`
2. Activa desde el panel de administración de WordPress
3. Configura las credenciales en **Mi Integración API > Configuración**

## 📚 Documentación

La documentación completa está incluida en el plugin:

- **Manual de Usuario**: `docs/manual-usuario/index.html`
- **Guías Técnicas**: `docs/*.md`
- **Arquitectura**: `docs/arquitectura-sistema-errores.md`
- **Guía de Migración**: `docs/guia-migracion-desarrolladores.md`

## 🎯 Uso

### Configuración Básica

1. Ve a **WordPress Admin > Mi Integración API**
2. Ingresa tus credenciales de API de Verial
3. Verifica la conexión con el botón de prueba
4. Configura las opciones de sincronización

### Endpoints Disponibles

El plugin proporciona 45+ endpoints REST:

- `GET /wp-json/verial/v1/articulos` - Obtener productos
- `GET /wp-json/verial/v1/clientes` - Obtener clientes
- `GET /wp-json/verial/v1/pedidos` - Obtener pedidos
- `POST /wp-json/verial/v1/sync/productos` - Sincronizar productos
- Y muchos más...

Consulta `docs/manual-usuario/manual-endpoints.html` para la lista completa.

## 🏗️ Arquitectura

```
includes/
├── Core/              # Clases principales del sistema
├── Admin/             # Panel de administración
├── Endpoints/         # API REST endpoints
├── Sync/              # Sistema de sincronización
├── Cache/             # Sistema de caché
├── WooCommerce/       # Integración con WooCommerce
├── Deteccion/         # Detección automática de stock
├── Helpers/           # Utilidades y helpers
├── Logging/           # Sistema de logging
├── ErrorHandling/     # Manejo de errores
└── ...
```

## 🔧 Desarrollo

### Estructura del Proyecto

```bash
mi-integracion-api/
├── includes/          # Código fuente del plugin
├── templates/         # Templates de administración
├── assets/            # CSS, JS, imágenes
├── docs/              # Documentación completa
├── languages/         # Archivos de traducción
├── scripts/           # Scripts de utilidades
└── verialconfig.php   # Configuración principal
```

### Instalación para Desarrollo

```bash
# Clonar repositorio
git clone https://github.com/tu-usuario/mi-integracion-api.git
cd mi-integracion-api

# Instalar dependencias de desarrollo
composer install

# Ejecutar tests
phpunit

# Compilar plugin
bash build-plugin-fixed-v2.sh
```

## 📝 Licencia

Este plugin está licenciado bajo GPLv2 o posterior.

## 👤 Autor

Desarrollado por Christian

## 🐛 Reportar Issues

Si encuentras un problema, por favor abre un [issue en GitHub](https://github.com/tu-usuario/mi-integracion-api/issues).

## 🔄 Changelog

### 2.0.0 (2025-10-27)
- Refactorización completa del sistema de sincronización
- Arquitectura simplificada con responsabilidad única
- Sistema de detección automática de stock
- Dashboard de administración renovado
- Optimización de autoloaders
- Documentación completa del usuario

### 1.4.1
- Sistema de configuración unificado
- Mejoras en el rendimiento
- Corrección de bugs menores

## 📞 Soporte

Para soporte técnico, consulta la documentación en `docs/` o abre un issue en GitHub.

---

**¿Necesitas ayuda?** Consulta la [documentación completa](docs/manual-usuario/index.html) o abre un [issue](https://github.com/tu-usuario/mi-integracion-api/issues).

