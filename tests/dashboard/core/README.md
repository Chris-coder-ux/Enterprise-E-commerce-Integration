# Tests para Módulos Refactorizados del Dashboard

Este directorio contiene los tests unitarios para los módulos refactorizados del dashboard.

## Estructura

```
tests/dashboard/
└── core/
    ├── ErrorHandler.test.js    # Tests para ErrorHandler
    └── README.md                # Este archivo
```

## Ejecutar Tests

### Ejecutar todos los tests del dashboard
```bash
npm test -- tests/dashboard/
```

### Ejecutar tests de un módulo específico
```bash
npm test -- tests/dashboard/core/ErrorHandler.test.js
```

### Ejecutar tests en modo watch
```bash
npm test -- --watch tests/dashboard/core/ErrorHandler.test.js
```

### Ver cobertura de código
```bash
npm test -- --coverage tests/dashboard/core/ErrorHandler.test.js
```

## Estado Actual

### ✅ ErrorHandler.test.js
- **32 tests** - Todos pasando ✅
- **Cobertura**: Métodos principales cubiertos
- **Tiempo de ejecución**: ~0.4s

#### Métodos testeados:
- ✅ `logError()` - 3 tests
- ✅ `showUIError()` - 8 tests
- ✅ `showConnectionError()` - 7 tests
- ✅ `showProtectionError()` - 2 tests
- ✅ `showCancelError()` - 3 tests
- ✅ `showCriticalError()` - 3 tests
- ✅ Exposición global - 2 tests
- ✅ Integración con jQuery - 2 tests
- ✅ Casos edge - 4 tests

## Notas Técnicas

### Configuración de Jest
- Los tests usan `jest-environment-jsdom` para simular el DOM
- jQuery está disponible globalmente desde `jest.setup.js`
- Los mocks de `jest.setup.js` pueden interferir, por lo que algunos tests restauran funciones reales

### Manejo de setTimeout
- Algunos tests restauran `setTimeout` real de Node.js para evitar problemas con el mock de `jest.setup.js`
- Se usa `require('timers').setTimeout` para obtener el setTimeout real

### Limpieza
- Todos los tests limpian elementos DOM creados en `afterEach`
- Se restauran mocks y referencias originales después de cada test

## Próximos Tests

Cuando se completen los siguientes módulos, crear sus respectivos tests:

- [ ] `AjaxManager.test.js`
- [ ] `EventManager.test.js`
- [ ] `PollingManager.test.js`
- [ ] `SyncStateManager.test.js`
- [ ] `NonceManager.test.js`
- [ ] Y más...

## Mejores Prácticas

1. **Un test por funcionalidad**: Cada test verifica una funcionalidad específica
2. **Nombres descriptivos**: Los nombres de los tests describen claramente qué verifican
3. **Limpieza**: Siempre limpiar después de cada test
4. **Mocks apropiados**: Usar mocks solo cuando sea necesario
5. **Casos edge**: Incluir tests para casos límite y errores

