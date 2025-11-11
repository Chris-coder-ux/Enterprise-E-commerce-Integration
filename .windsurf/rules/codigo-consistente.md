---
trigger: always_on
description: "Aplica un estilo de código consistente para JavaScript, similar a Prettier."
globs:
---

# 💅 Guía de Estilo para JavaScript

Todo el código JavaScript debe seguir un formato consistente y legible. Si existe un archivo `.prettierrc` o `.eslintrc` en el proyecto, sus reglas tienen la máxima prioridad.

## Principios de Formato:

1.  **Punto y Coma**: Todas las sentencias deben terminar con un punto y coma (`;`).

2.  **Comillas**: Utiliza comillas simples (`'`) para las cadenas de texto, a menos que la cadena contenga comillas simples.

3.  **Comas Finales (Trailing Commas)**: Añade una coma al final del último elemento en objetos y arrays multilínea. Esto facilita el control de versiones.
    ```javascript
    // Ejemplo
    const miObjeto = {
        propiedad1: 'valor1',
        propiedad2: 'valor2', // <-- Coma final
    };
    ```

4.  **Indentación**: Usa 2 espacios para la indentación. No uses tabuladores.

5.  **`const` y `let`**: Prefiere `const` por defecto. Usa `let` solo si la variable necesita ser reasignada. Evita el uso de `var`.

6.  **Funciones de Flecha**: Utiliza funciones de flecha (`=>`) para funciones anónimas y callbacks.