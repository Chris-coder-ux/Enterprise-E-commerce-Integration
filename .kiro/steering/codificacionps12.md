---
inclusion: always
---

# 🎨 Guía de Estilo de Código PHP (PSR-12)

Todo el código PHP generado o modificado debe adherirse estrictamente al estándar PSR-12.

## Principios Clave:

1.  **Apertura de Llaves (`{`)**:
    - Para **clases y métodos**, la llave de apertura SIEMPRE va en una nueva línea.
    - Para **estructuras de control** (`if`, `for`, `foreach`, `while`), la llave de apertura SIEMPRE va en la misma línea.

2.  **Palabras Clave y Espacios**:
    - Después de las palabras clave de estructuras de control (`if`, `else`, `for`, etc.), debe haber UN espacio.
    - Las llamadas a funciones y métodos NO deben tener un espacio entre el nombre y el paréntesis de apertura.

3.  **Visibilidad**: Se deben declarar explícitamente la visibilidad en todas las propiedades y métodos (`public`, `protected`, o `private`).

4.  **Operadores**: Todos los operadores binarios (`+`, `-`, `*`, `=`, `==`, `===`, `.` etc.) deben estar rodeados por al menos un espacio.

5.  **`else if` vs `elseif`**: Utiliza `else if` en lugar de `elseif` para mantener la consistencia.

Al aplicar estos principios, aseguras que el código sea legible y siga las convenciones de la comunidad de PHP.