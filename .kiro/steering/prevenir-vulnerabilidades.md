---
inclusion: always
---

# 🛡️ Guía de Desarrollo Seguro

La seguridad es prioritaria. Todo el código debe escribirse siguiendo estas prácticas para minimizar las vulnerabilidades.

## Para PHP (Backend):

1.  **Prevención de Inyección SQL**: NUNCA insertes variables directamente en las consultas SQL. Utiliza **consultas preparadas** (prepared statements) con `PDO` o `MySQLi`. Los parámetros deben ser vinculados (bound).

2.  **Prevención de XSS (Cross-Site Scripting)**: SIEMPRE escapa cualquier dato que vayas a imprimir en HTML. Utiliza funciones como `htmlspecialchars()` sobre cualquier variable que provenga del usuario o de la base de datos antes de mostrarla.

3.  **Validación y Saneo de Entradas**: NUNCA confíes en los datos del usuario. Valida y sanea TODAS las entradas de `$_GET`, `$_POST` y otras fuentes externas antes de usarlas. Usa `filter_input()` o librerías de validación.

4.  **Gestión de Sesiones Segura**: Utiliza `session_regenerate_id()` para prevenir la fijación de sesiones y configura las cookies de sesión para que sean `HttpOnly` y `Secure`.

## Para JavaScript (Frontend):

1.  **No Exponer Datos Sensibles**: Nunca almacenes datos sensibles (tokens, claves de API) en el código del lado del cliente de forma que sean fácilmente accesibles.

2.  **Validación en el Cliente**: La validación en el frontend es para mejorar la experiencia del usuario, no como medida de seguridad. La validación real SIEMPRE debe ocurrir en el backend (PHP).

3.  **Llamadas a API Seguras**: Asegúrate de que todas las llamadas a APIs se realicen a través de `HTTPS`.