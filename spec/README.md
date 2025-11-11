# Tests con Jasmine

Este directorio contiene los tests unitarios ejecutados con **Jasmine**, un framework de testing para JavaScript que funciona tanto en Node.js como en el navegador.

## ¿Por qué Jasmine?

Jasmine es especialmente útil para:

1. **Depuración en el navegador**: Puedes ejecutar los tests directamente en el navegador, lo que facilita la depuración de problemas de carga de scripts.
2. **Tests visuales**: El runner HTML (`SpecRunner.html`) muestra los resultados de forma visual y clara.
3. **Compatibilidad**: Funciona tanto en Node.js como en navegadores sin configuración adicional.

## Estructura

```
spec/
├── SpecRunner.html          # Runner HTML para ejecutar tests en el navegador
├── support/
│   └── jasmine.json          # Configuración de Jasmine
├── helpers/
│   └── jasmine-jquery.js     # Helpers para trabajar con jQuery en los tests
└── dashboard/
    └── components/
        └── ConsoleManagerSpec.js  # Tests para ConsoleManager
```

## Ejecutar Tests

### Opción 1: En el Navegador (Recomendado para depuración)

1. Abre `spec/SpecRunner.html` en tu navegador
2. Los tests se ejecutarán automáticamente
3. Verás los resultados en la página

**Ventajas**:
- Puedes usar las herramientas de desarrollador del navegador
- Puedes ver exactamente qué scripts se cargan y cuándo
- Útil para depurar problemas de carga de scripts como el de `ConsoleManager`

### Opción 2: Desde la Línea de Comandos

```bash
# Instalar dependencias (si no lo has hecho)
npm install

# Ejecutar tests con Jasmine
npm run test:jasmine

# Ejecutar en modo watch (re-ejecuta tests cuando cambian los archivos)
npm run test:jasmine:watch
```

## Configuración

### jasmine.json

El archivo `spec/support/jasmine.json` contiene la configuración de Jasmine:

```json
{
  "spec_dir": "spec",
  "spec_files": [
    "**/*[sS]pec.js"
  ],
  "helpers": [
    "helpers/**/*.js"
  ]
}
```

### SpecRunner.html

El archivo `spec/SpecRunner.html` carga:
1. Jasmine Core (desde CDN)
2. Helpers (mocks de jQuery, etc.)
3. Código fuente a testear
4. Tests (specs)

## Escribir Tests

### Estructura de un Test

```javascript
describe('NombreDelMódulo', function() {
  beforeEach(function() {
    // Configuración antes de cada test
  });

  afterEach(function() {
    // Limpieza después de cada test
  });

  describe('Método específico', function() {
    it('debe hacer algo específico', function() {
      // Arrange (preparar)
      const mockJQuery = createMockJQuery();
      
      // Act (ejecutar)
      ConsoleManager.addLine('info', 'Test');
      
      // Assert (verificar)
      expect(mockJQuery.append).toHaveBeenCalled();
    });
  });
});
```

### Matchers de Jasmine

Jasmine incluye muchos matchers útiles:

```javascript
expect(value).toBe(expected);           // Igualdad estricta (===)
expect(value).toEqual(expected);       // Igualdad profunda
expect(value).toBeDefined();           // Verifica que está definido
expect(value).toBeUndefined();         // Verifica que no está definido
expect(value).toBeNull();              // Verifica que es null
expect(value).toBeTruthy();            // Verifica que es truthy
expect(value).toBeFalsy();             // Verifica que es falsy
expect(value).toContain(item);         // Verifica que contiene un elemento
expect(value).toMatch(regex);          // Verifica que coincide con regex
expect(fn).toThrow();                  // Verifica que lanza una excepción
expect(spy).toHaveBeenCalled();        // Verifica que se llamó
expect(spy).toHaveBeenCalledWith(args); // Verifica que se llamó con args
```

### Spies

Jasmine permite crear "spies" para mockear funciones:

```javascript
const spy = jasmine.createSpy('nombreSpy');
spy.and.returnValue('valor');
spy.and.callFake(function() { /* ... */ });
```

## Ejemplo: Test de ConsoleManager

El archivo `spec/dashboard/components/ConsoleManagerSpec.js` contiene tests completos para `ConsoleManager`, incluyendo:

- ✅ Verificación de carga del script
- ✅ Verificación de exposición global (`window.ConsoleManager`)
- ✅ Tests de métodos individuales
- ✅ Tests de integración con `PollingManager`

## Depuración del Problema de ConsoleManager

Para depurar por qué `ConsoleManager` no está disponible:

1. Abre `spec/SpecRunner.html` en el navegador
2. Abre las herramientas de desarrollador (F12)
3. Ve a la pestaña "Console"
4. Busca los logs que empiezan con `[ConsoleManager]`
5. Si no ves el log `[ConsoleManager] ⚡ Script ConsoleManager.js iniciado`, el script no se está ejecutando

## Comparación: Jest vs Jasmine

| Característica | Jest | Jasmine |
|---------------|------|---------|
| Ejecución en navegador | ❌ (solo Node.js) | ✅ |
| Depuración visual | ❌ | ✅ (SpecRunner.html) |
| Configuración | Media | Baja |
| Popularidad | Muy alta | Alta |
| Integración con WordPress | ✅ | ✅ |

## Próximos Pasos

1. Agregar más tests para otros módulos
2. Configurar integración continua con Jasmine
3. Agregar tests de integración end-to-end

## Recursos

- [Documentación oficial de Jasmine](https://jasmine.github.io/)
- [Jasmine en GitHub](https://github.com/jasmine/jasmine)

