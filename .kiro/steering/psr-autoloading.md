---
inclusion: always
---

# PSR-4 Autoloading Standard

## Estructura de namespaces y directorios
- El namespace debe corresponder con la estructura de directorios
- Cada segmento del namespace representa una carpeta
- El nombre de la clase debe coincidir con el nombre del archivo

## Ejemplos correctos:
```php
// Archivo: src/App/Controllers/UserController.php
namespace App\Controllers;

class UserController {
    // ...
}

// Archivo: vendor/package/src/Http/Request.php  
namespace Vendor\Package\Http;

class Request {
    // ...
}