# Sanitización de Datos del Servidor - Prevención de XSS

## Resumen

Se ha implementado un sistema completo de sanitización para prevenir ataques XSS (Cross-Site Scripting) al insertar datos del servidor en el DOM.

## Archivos Modificados

### 1. `assets/js/dashboard/utils/Sanitizer.js` (NUEVO)

**Utilidad centralizada de sanitización**:
- `escapeHtml(text)`: Escapa caracteres HTML especiales (`<`, `>`, `&`, `"`, `'`)
- `sanitizeHtml(html, options)`: Sanitiza HTML usando DOMPurify si está disponible, o escapa todo como fallback
- `sanitizeMessage(message)`: Método de conveniencia para sanitizar mensajes

**Uso**:
```javascript
// Para texto plano (recomendado)
const safeText = Sanitizer.sanitizeMessage(serverMessage);
jQuery('#element').text(safeText);

// Para HTML (solo si realmente necesario)
const safeHtml = Sanitizer.sanitizeHtml(serverHtml, { allowBasicFormatting: true });
jQuery('#element').html(safeHtml);
```

### 2. `assets/js/dashboard/core/ErrorHandler.js`

**Cambios**:
- Línea 100-110: Sanitización de mensajes en fallback temporal
- Línea 120-132: Sanitización de mensajes en feedback principal
- Reemplazado `.html()` con construcción segura usando `.text()`

**Antes**:
```javascript
$feedback.html(`<div class="${errorClass}"><strong>${icon}:</strong> ${message}</div>`);
```

**Después**:
```javascript
const sanitizedMessage = Sanitizer.sanitizeMessage(message);
$feedback.html(`<div class="${errorClass}"><strong>${icon}:</strong> </div>`);
$feedback.find('strong').after(jQuery('<span>').text(sanitizedMessage));
```

### 3. `assets/js/dashboard/components/ConsoleManager.js`

**Cambios**:
- Línea 219-235: Sanitización de mensajes antes de insertarlos en la consola
- Construcción segura de elementos usando `.text()` en lugar de template literals con `.html()`

**Antes**:
```javascript
const $line = jQuery('<div>')
  .addClass('mia-console-line')
  .html(`
    <span class="mia-console-time">${timeStr}</span>
    <span class="mia-console-label">${label}</span>
    <span class="mia-console-message">${message}</span>
  `);
```

**Después**:
```javascript
const sanitizedMessage = Sanitizer.sanitizeMessage(message);
const $line = jQuery('<div>').addClass('mia-console-line');
jQuery('<span>').addClass('mia-console-time').text(timeStr).appendTo($line);
jQuery('<span>').addClass('mia-console-label').text(label).appendTo($line);
jQuery('<span>').addClass('mia-console-message').text(sanitizedMessage).appendTo($line);
```

### 4. `assets/js/dashboard/sync/SyncProgress.js`

**Cambios**:
- Línea 530-539: Sanitización de mensajes antes de insertarlos en feedback
- Reemplazado `.html()` con `.text()`

**Antes**:
```javascript
DOM_CACHE.$feedback.html(message);
```

**Después**:
```javascript
const sanitizedMessage = Sanitizer.sanitizeMessage(message);
DOM_CACHE.$feedback.text(sanitizedMessage);
```

### 5. `assets/js/dashboard/components/UnifiedDashboard.js`

**Cambios**:
- Línea 1457-1467: Sanitización de mensajes en `showError()` y `showSuccess()`
- Reemplazado `.html()` con construcción segura usando `.text()`

## Principios de Seguridad Aplicados

### 1. Escape por Defecto

**Regla**: Siempre escapar datos del servidor antes de insertarlos en el DOM.

```javascript
// ✅ CORRECTO
const safe = Sanitizer.sanitizeMessage(serverData.message);
jQuery('#element').text(safe);

// ❌ INCORRECTO
jQuery('#element').html(serverData.message);
```

### 2. Usar `.text()` en lugar de `.html()`

**Regla**: Usar `.text()` para texto plano, `.html()` solo cuando realmente necesites HTML sanitizado.

```javascript
// ✅ CORRECTO
jQuery('#element').text(sanitizedMessage);

// ❌ INCORRECTO (si message viene del servidor)
jQuery('#element').html(message);
```

### 3. Sanitización Robusta

**Regla**: Si realmente necesitas HTML, usar `Sanitizer.sanitizeHtml()` con DOMPurify o escape completo como fallback.

```javascript
// ✅ CORRECTO (con DOMPurify)
const safeHtml = Sanitizer.sanitizeHtml(serverHtml, { allowBasicFormatting: true });
jQuery('#element').html(safeHtml);

// ✅ CORRECTO (sin DOMPurify - fallback seguro)
// Sanitizer.sanitizeHtml() escapa todo si DOMPurify no está disponible
```

## Casos Especiales

### Template Literals con Datos del Servidor

**Problema**: Los template literals pueden contener datos del servidor sin sanitizar.

**Solución**: Construir elementos de forma segura usando jQuery y `.text()`.

```javascript
// ❌ INSEGURO
html += `<p>${data.message}</p>`;

// ✅ SEGURO
const $p = jQuery('<p>');
$p.text(Sanitizer.sanitizeMessage(data.message));
html += $p[0].outerHTML; // Solo si realmente necesitas HTML
// O mejor aún, construir directamente en el DOM:
jQuery('#container').append($p);
```

### Datos Numéricos y Booleanos

**Nota**: Los números y booleanos son seguros, pero siempre convertirlos a string antes de sanitizar.

```javascript
// ✅ CORRECTO
const safeCount = Sanitizer.sanitizeMessage(String(data.count));
jQuery('#count').text(safeCount);
```

## Verificación de Seguridad

### Checklist de Revisión

Al revisar código que inserta datos del servidor:

- [ ] ¿Se usa `.text()` en lugar de `.html()`?
- [ ] ¿Se sanitiza el mensaje antes de insertarlo?
- [ ] ¿Los template literals solo contienen datos controlados (no del servidor)?
- [ ] ¿Se usa `Sanitizer.sanitizeMessage()` para mensajes del servidor?
- [ ] ¿Se usa `Sanitizer.sanitizeHtml()` solo cuando realmente se necesita HTML?

### Búsqueda de Patrones Inseguros

```bash
# Buscar usos inseguros de .html() con datos del servidor
grep -r "\.html(.*response\|\.html(.*data\.\|\.html(.*\.message" assets/js/dashboard/

# Buscar template literals con datos del servidor
grep -r "\`.*\${.*response\|data\|\.message" assets/js/dashboard/
```

## DOMPurify (Opcional)

Si necesitas renderizar HTML del servidor de forma segura, puedes agregar DOMPurify:

```html
<script src="https://cdn.jsdelivr.net/npm/dompurify@3.0.6/dist/purify.min.js"></script>
```

`Sanitizer.sanitizeHtml()` detectará automáticamente DOMPurify y lo usará para sanitización robusta.

## Archivos Pendientes de Revisión

Los siguientes archivos usan `.html()` con datos del servidor y necesitan revisión adicional:

1. **`assets/js/dashboard/components/UnifiedDashboard.js`**:
   - Líneas 1041, 1056-1060, 1071-1079, 1083-1084, 1200-1201, 1209-1210, 1218-1219: Template literals con datos del servidor
   - **Recomendación**: Construir HTML de forma segura usando jQuery y `.text()` para valores del servidor

## Notas Importantes

1. **Fallback Seguro**: Si `Sanitizer` no está disponible, se usa escape manual como fallback.
2. **Compatibilidad**: El código mantiene compatibilidad hacia atrás mientras mejora la seguridad.
3. **Performance**: El escape es rápido y no afecta significativamente el rendimiento.
4. **DOMPurify**: Opcional pero recomendado si necesitas renderizar HTML del servidor.

## Referencias

- [OWASP XSS Prevention Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/Cross_Site_Scripting_Prevention_Cheat_Sheet.html)
- [DOMPurify Documentation](https://github.com/cure53/DOMPurify)
- [jQuery .text() vs .html()](https://api.jquery.com/text/)

