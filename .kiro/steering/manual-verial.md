---
inclusion: always
---

# 📖 Manual de Integración del Servicio Web Verial (con Ejemplos)

Esta es la documentación de referencia para la API de Verial. Todo el código que interactúe con esta API debe seguir estrictamente las especificaciones y ejemplos detallados a continuación.

## 1. Conceptos Generales

- [cite_start]**Formato de Datos**: Peticiones y respuestas usan el formato JSON[cite: 1, 2].
- **Autenticación y Sesión**:
    - [cite_start]**GET**: Usar el parámetro `x` con el número de sesión (ej: `x=18`)[cite: 1, 2].
    - [cite_start]**POST**: Incluir la propiedad `sesionwcf` en el cuerpo del JSON (ej: `"sesionwcf": 18`)[cite: 1, 2].
- [cite_start]**URL Base**: `http://x.verial.org:8000/WcfServiceLibraryVerial/`[cite: 1, 2].

## 2. Manejo de Errores

Todas las respuestas incluyen un objeto `InfoError`. [cite_start]Un `Codigo` de `0` indica éxito[cite: 1, 2].

| Código | Descripción del Error |
| :--- | :--- |
| 0 | [cite_start]Todo correcto [cite: 1, 2] |
| 1 | [cite_start]Error iniciando la sesión [cite: 1, 2] |
| 7 | [cite_start]Cliente no encontrado [cite: 1, 2] |
| 10 | [cite_start]Falta un dato requerido [cite: 1, 2] |
| 12 | [cite_start]Error creando un nuevo documento de cliente [cite: 1, 2] |
| 13 | [cite_start]Modificación no permitida [cite: 1, 2] |
| 20 | [cite_start]Módulo no contratado [cite: 1, 2] |

## 3. Tipos de Datos

- [cite_start]`N`: Numérico entero, `A`: Alfanumérico, `D`: Decimal, `F`: Fecha (YYYY-MM-DD), `L`: Lógico (True/False)[cite: 1, 2].

## 4. Métodos de la API

### GetPaisesWS
[cite_start]Devuelve la lista de países[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetPaisesWS?x=18

### GetProvinciasWS
[cite_start]Devuelve la lista de provincias[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetProvinciasWS?x=18

### NuevaProvinciaWS
[cite_start]Da de alta una nueva provincia[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://192.168.0.42:8000/WcfServiceLibraryVerial/NuevaProvinciaWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "Nombre": "Mainz",
     "ID_Pais": 2,
     "CodigoNuts": ""
  }
  ```

### GetLocalidadesWS
[cite_start]Devuelve la lista de localidades[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetLocalidadesWS?x=18


### NuevaLocalidadWS
[cite_start]Da de alta una nueva localidad[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://192.168.0.42:8000/WcfServiceLibraryVerial/NuevaLocalidadWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "Nombre": "Maguncia",
     "ID_Pais": 2,
     "ID_Provincia": 55,
     "CodigoNuts": ""
  }
  ```

### GetAgentesWS
[cite_start]Devuelve los agentes comisionistas[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetAgentesWS?x=18


### GetClientesWS
[cite_start]Devuelve la información de los clientes[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetClientesWS?x=18&id_cliente=0&fecha=2024-02-05&hora=12:00


### NuevoClienteWS
[cite_start]Da de alta o modifica un cliente[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://x.verial.org:8000/WcfServiceLibraryVerial/NuevoClienteWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "Id": 0,
     "Tipo": 1,
     "NIF": "44444444A",
     "Nombre": "Test2",
     "Apellido1": "Prueba",
     "WebUser": "direcciontest@correo.es",
     "WebPassword": "123456",
     "EnviarAnuncios": true,
     "DireccionesEnvio": [
        {
           "Id": 0,
           "Nombre": "Fernando",
           "Apellido1": "Hernandez",
           "Provincia": "Madrid",
           "CPostal": "28125",
           "Direccion": "Avenida Larga 225-235",
           "Telefono": "666000333"
        }
     ]
  }
  ```

### NuevaDireccionEnvioWS
[cite_start]Da de alta una nueva dirección de envío a un cliente[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://x.verial.org:8000/WcfServiceLibraryVerial/NuevaDireccionEnvioWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "ID_Cliente": 2,
     "Id": 0,
     "Nombre": "Juan Carlos",
     "Apellido1": "Pinto",
     "Provincia": "Barcelona",
     "Localidad": "Badalona",
     "CPostal": "08910",
     "Direccion": "Calle Mayor 8, Bajo",
     "Telefono": "666789000"
  }
  ```

### GetMascotasWS
[cite_start]Devuelve la información de mascotas[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetMascotasWS?x=18&id_cliente=0


### NuevaMascotaWS
[cite_start]Da de alta o modifica una mascota[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://x.verial.org:8000/WcfServiceLibraryVerial/NuevaMascotaWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "Id": 1,
     "ID_Cliente": 8,
     "Nombre": "Rocky",
     "TipoAnimal": "Perro",
     "Raza": "Caniche",
     "FechaNacimiento": "2019-06-13",
     "Peso": 2.55,
     "SituacionPeso": 2,
     "Actividad": 3,
     "HayPatologias": true,
     "Patologias": "Rabia",
     "Alimentacion": 3,
     "AlimentacionOtros": "Comida de personas"
  }
  ```

### BorrarMascotaWS
[cite_start]Borra un registro de mascota[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://x.verial.org:8000/WcfServiceLibraryVerial/BorrarMascotaWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "Id": 2,
     "ID_Cliente": 8
  }
  ```

### GetArticulosWS
[cite_start]Devuelve la información de los artículos[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetArticulosWS?x=18


### GetNumArticulosWS
[cite_start]Obtiene el número total de artículos[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetNumArticulosWS?x=18


### GetStockArticulosWS
[cite_start]Devuelve el stock de los artículos[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetStockArticulosWS?x=18&id_articulo=0


### GetImagenesArticulosWS
[cite_start]Devuelve las imágenes de los artículos[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetImagenesArticulosWS?x=18&id_articulo=0&numpixelsladomenor=300&inicio=1&fin=100


### GetCondicionesTarifaWS
[cite_start]Devuelve las condiciones de tarifa para la venta[cite: 1, 2].
- [cite_start]**Tipo**: `GET` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
http://x.verial.org:8000/WcfServiceLibraryVerial/GetCondicionesTarifaWS?x=18&id_articulo=0&id_cliente=0&fecha=2022-01-17


### NuevoDocClienteWS
[cite_start]Da de alta o modifica un documento de cliente[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://x.verial.org:8000/WcfServiceLibraryVerial/NuevoDocClienteWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "Id": 0,
     "Tipo": 5,
     "Fecha": "2022-01-24",
     "ID_Cliente": 0,
     "Cliente": {
        "Id": 0,
        "Tipo": 1,
        "Nombre": "Rafael",
        "WebUser": "email@correo.es",
        "WebPassword": "abcdef"
     },
     "PreciosImpIncluidos": true,
     "BaseImponible": 78.64,
     "TotalImporte": 87.24,
     "Contenido": [
        {
           "TipoRegistro": 1,
           "ID_Articulo": 11686,
           "Precio": 17.8,
           "Dto": 20.0,
           "Uds": 1.0,
           "ImporteLinea": 14.24
        }
     ],
     "Pagos": [
        {
           "ID_MetodoPago": 8,
           "Fecha": "2022-01-24",
           "Importe": 87.24
        }
     ]
  }
  ```

### UpdateDocClienteWS
[cite_start]Permite modificar ciertos datos de un documento ya existente[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://x.verial.org:8000/WcfServiceLibraryVerial/UpdateDocClienteWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "Id": 67,
     "Aux1": "Texto en auxiliar 1 del pedido"
  }
  ```

### EstadoPedidosWS
[cite_start]Consulta el estado de los pedidos[cite: 1, 2].
- [cite_start]**Tipo**: `POST` [cite: 1, 2]
- **Ejemplo de Petición (Postman)**:
- [cite_start]**URL**: `http://x.verial.org:8000/WcfServiceLibraryVerial/EstadoPedidosWS` [cite: 1, 2]
- **Cuerpo (Body)**:
  ```json
  {
     "sesionwcf": 18,
     "Pedidos": [
        {
           "Id": 14,
           "Referencia": null
        },
        {
           "Id": 0,
           "Referencia": "10000002"
        }
     ]
  }
  ```