---
trigger: always_on
description: "Directrices para la generación automática de bloques de documentación PHPDoc en todo el código PHP."
globs:
---

# 📜 Guía de Estilo para Documentación PHPDoc

El objetivo es asegurar que todo el código PHP esté documentado de manera clara y consistente. Al editar o generar código, aplica las siguientes directrices de PHPDoc.

## 1. Regla General

Añade bloques de documentación PHPDoc a todas las clases, métodos, funciones y propiedades que no lo tengan. El bloque debe describir de forma concisa el propósito del elemento.

## 2. Documentación de Clases

Las clases deben tener un bloque de documentación que describa su propósito general.

- **Ejemplo**:
  ```php
  /**
   * Gestiona las interacciones con la API de Verial.
   *
   * Esta clase encapsula la lógica para realizar llamadas cURL a los
   * diferentes endpoints del servicio web de Verial.
   *
   * @package     VerialIntegration
   * @version     1.0.0
   */
  class VerialApiClient
  {
      // ...
  }

## 3. Documentación de Propiedades
Las propiedades de una clase deben documentarse con la etiqueta @var para indicar su tipo de dato.

- **Ejemplo**:

```php

/**
 * El número de sesión para autenticarse en la API de Verial.
 * @var int
 */
private $sesionwcf;
```

## 4. Documentación de Métodos y Funciones
Este es el punto más importante. Todos los métodos y funciones deben tener un bloque PHPDoc que incluya:

Una descripción breve de lo que hace el método.

La etiqueta @param para cada parámetro, especificando su tipo, nombre y una descripción.

La etiqueta @return para describir el tipo de dato que devuelve la función y lo que representa.

La etiqueta @throws si el método puede lanzar una excepción.

Estilo: Alinea los nombres de las variables y las descripciones para mejorar la legibilidad.

- **Ejemplo**:

```php

/**
 * Crea un nuevo cliente en la API de Verial.
 *
 * Envía los datos de un nuevo cliente al endpoint 'NuevoClienteWS' mediante POST.
 *
 * @param   array   $datosCliente   Array asociativo con los datos del cliente.
 * @param   int     $sesionId       El ID de sesión para la petición.
 * @return  object                  El objeto del cliente creado, devuelto por la API.
 * @throws  \Exception              Si la llamada a la API falla o devuelve un error.
 */
public function crearNuevoCliente(array $datosCliente, int $sesionId)
{
    // ...
}
```