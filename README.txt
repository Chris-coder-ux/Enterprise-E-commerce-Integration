=== Mi Integración API ===
Contributors: christian
Tags: verial, woo commerce, api, integration, sync
Requires at least: 5.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Plugin de integración completa entre Verial y WooCommerce con sincronización automática de productos, clientes y pedidos.

== Description ==

**Mi Integración API** es un plugin avanzado que integra WordPress/WooCommerce con el sistema Verial, proporcionando sincronización automática bidireccional de productos, clientes y pedidos.

== Features ==

* ✅ Sincronización automática de productos desde Verial a WooCommerce
* ✅ Gestión completa de clientes y direcciones de envío
* ✅ Sincronización de pedidos en tiempo real
* ✅ Sistema de detección automática de cambios de stock
* ✅ Dashboard de administración avanzado
* ✅ Sistema de caché inteligente para optimizar rendimiento
* ✅ API REST completa para acceso programático
* ✅ Gestión de sesiones y autenticación segura
* ✅ Sistema de logs y monitoreo
* ✅ Comprobación de certificados SSL

== Installation ==

**IMPORTANTE:** Este plugin requiere Composer para instalar dependencias.

1. Sube el archivo `mi-integracion-api.zip` a WordPress
2. Activa el plugin desde la pantalla de Plugins
3. **EJECUTA EN EL SERVIDOR:**
   ```
   cd wp-content/plugins/mi-integracion-api
   composer install --no-dev
   ```
4. Configura las credenciales en Mi Integración API > Configuración
5. Verifica la conexión con la API de Verial

**Ver documentación completa en:** `docs/manual-usuario/index.html`

== Frequently Asked Questions ==

= ¿Por qué no funciona el plugin? =

Verifica que hayas ejecutado `composer install --no-dev` después de activar el plugin. El archivo `vendor/` no está incluido por limitaciones de tamaño.

= ¿Dónde encuentro la documentación? =

La documentación completa está en:
`wp-content/plugins/mi-integracion-api/docs/manual-usuario/`

= ¿Cómo configuro la API de Verial? =

1. Ve a Mi Integración API > Configuración
2. Ingresa tus credenciales de API
3. Haz clic en "Probar Conexión"
4. Guarda los cambios

= ¿Cómo verifico que las dependencias están instaladas? =

Ejecuta: `ls wp-content/plugins/mi-integracion-api/vendor/`

Si el directorio `vendor/` existe y contiene archivos, todo está correcto.

== Screenshots ==

1. Dashboard principal
2. Configuración de sincronización
3. Logs y monitoreo
4. Endpoints API REST

== Changelog ==

= 2.0.0 =
* Refactorización completa del sistema de sincronización
* Arquitectura simplificada con responsabilidad única
* Sistema de detección automática de stock
* Dashboard de administración renovado
* Optimización de autoloaders
* Documentación completa del usuario
* Compatibilidad con WordPress 6.4
* Sistema de SSL avanzado

= 1.4.1 =
* Sistema de configuración unificado
* Mejoras en el rendimiento
* Corrección de bugs menores

== Upgrade Notice ==

= 2.0.0 =
Actualización mayor con arquitectura refactorizada. Se recomienda realizar respaldo antes de actualizar. Ejecutar `composer install --no-dev` después de actualizar.

