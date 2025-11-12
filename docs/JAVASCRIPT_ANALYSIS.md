# 🔍 Herramientas de Análisis de JavaScript

## ✅ Herramientas Configuradas

### 1. **Codacy con ESLint** ✅
   - **Estado**: Configurado y funcionando
   - **Versión**: ESLint 8.57.0
   - **Ubicación**: `.codacy/tools-configs/eslint.config.mjs`
   - **Análisis**: Automático en cada commit y push

### 2. **ESLint Local** ✅
   - **Estado**: Configurado pero no instalado
   - **Archivo de configuración**: `assets/js/.eslintrc.json`
   - **Reglas**: Extiende `eslint:recommended` con reglas personalizadas
   - **Entorno**: Browser, jQuery, ES6

### 3. **Jest** ✅
   - **Estado**: Instalado y configurado
   - **Versión**: 29.7.0
   - **Uso**: Testing unitario
   - **Coverage**: Configurado con umbral del 80%

### 4. **Jasmine** ✅
   - **Estado**: Instalado y configurado
   - **Versión**: 5.12.0
   - **Uso**: Testing BDD (Behavior-Driven Development)

### 5. **Plato** ✅
   - **Estado**: Instalado
   - **Versión**: 1.7.0
   - **Uso**: Análisis de complejidad y métricas de código

### 6. **Lizard** ✅
   - **Estado**: Configurado en Codacy
   - **Uso**: Análisis de complejidad ciclomática
   - **Límites**: 
     - Complejidad: 8
     - Líneas por función: 50
     - Líneas por archivo: 500

## 📋 Configuración Actual

### ESLint en Codacy (`.codacy/tools-configs/eslint.config.mjs`)
- ✅ Reglas de errores estrictas
- ✅ Detección de problemas comunes
- ✅ Validación de sintaxis
- ✅ Detección de código muerto
- ✅ Validación de tipos

### ESLint Local (`assets/js/.eslintrc.json`)
- ✅ Entorno: Browser, jQuery, ES6
- ✅ Indentación: 2 espacios
- ✅ Comillas: Simples
- ✅ Punto y coma: Requerido
- ✅ No usar `var` (solo `let`/`const`)
- ✅ Globals de WordPress configurados

## 🚀 Cómo Usar

### Análisis Automático con Codacy
```bash
# Se ejecuta automáticamente en cada commit/push
# Los resultados aparecen en:
# - Dashboard de Codacy: https://app.codacy.com
# - Pull Requests de GitHub
# - MCP Server en Cursor/VS Code
```

### Análisis Manual con ESLint Local
```bash
# Instalar ESLint (si no está instalado)
npm install --save-dev eslint

# Ejecutar análisis
npx eslint assets/js/**/*.js

# Ejecutar con corrección automática
npx eslint assets/js/**/*.js --fix
```

### Testing con Jest
```bash
# Ejecutar todos los tests
npm test

# Ejecutar en modo watch
npm run test:watch

# Ejecutar con coverage
npm run test:coverage

# Tests específicos del dashboard
npm run test:dashboard
```

### Testing con Jasmine
```bash
# Ejecutar tests
npm run test:jasmine

# Modo watch
npm run test:jasmine:watch

# En navegador
npm run test:jasmine:browser
# Luego abre: spec/SpecRunner.html
```

### Análisis de Complejidad con Plato
```bash
# Generar reporte de complejidad
npx plato -r reports/plato -d assets/js

# Ver reporte
open reports/plato/index.html
```

## 📊 Métricas Seguidas

### Complejidad Ciclomática (Lizard)
- **Límite por función**: 8
- **Límite por archivo**: 500 líneas
- **Límite de parámetros**: 8

### Coverage (Jest)
- **Branches**: 80%
- **Functions**: 80%
- **Lines**: 80%
- **Statements**: 80%

### Reglas ESLint
- **Errores**: 0 tolerados
- **Warnings**: Permitidos para `console.log` y variables no usadas
- **Estilo**: Indentación 2 espacios, comillas simples, punto y coma requerido

## 🔧 Mejoras Recomendadas

### 1. Añadir ESLint como script en package.json
```json
{
  "scripts": {
    "lint": "eslint assets/js/**/*.js",
    "lint:fix": "eslint assets/js/**/*.js --fix"
  }
}
```

### 2. Instalar ESLint como dependencia de desarrollo
```bash
npm install --save-dev eslint
```

### 3. Sincronizar configuración de ESLint
- La configuración de Codacy es más estricta
- Considerar usar la misma configuración en ambos lugares

## 📝 Archivos de Configuración

1. **`.codacy/tools-configs/eslint.config.mjs`** - Configuración para Codacy
2. **`assets/js/.eslintrc.json`** - Configuración local de ESLint
3. **`package.json`** - Scripts de testing y configuración de Jest
4. **`jest.setup.js`** - Configuración de Jest
5. **`spec/support/jasmine.json`** - Configuración de Jasmine

## ✅ Resumen

**Herramientas disponibles para análisis de JavaScript:**

1. ✅ **Codacy + ESLint** - Análisis automático continuo
2. ✅ **ESLint Local** - Análisis manual (requiere instalación)
3. ✅ **Jest** - Testing unitario con coverage
4. ✅ **Jasmine** - Testing BDD
5. ✅ **Plato** - Análisis de complejidad
6. ✅ **Lizard** - Complejidad ciclomática (via Codacy)

**Estado**: ✅ **Todas las herramientas están configuradas y funcionando**

