# 🔄 Guía de Migración para Desarrolladores

**Versión:** 1.0  
**Fecha:** 2025-01-26  
**Sistema:** MiIntegracionApi v1.4.1+  

---

## 📋 Resumen Ejecutivo

Esta guía proporciona instrucciones paso a paso para migrar código legacy al nuevo sistema unificado de manejo de errores con `SyncResponseInterface`. La migración es gradual y mantiene compatibilidad total con el código existente.

**Tiempo estimado de migración:** 2-4 horas por módulo  
**Compatibilidad:** 100% con código existente durante la migración  

---

## 🎯 Objetivos de la Migración

### **Antes (Código Legacy)**
```php
// Múltiples tipos de retorno inconsistentes
public function method(): array
{
    try {
        // lógica...
        return ['success' => true, 'data' => $data];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Manejo inconsistente de errores
if (is_wp_error($result)) {
    // manejo WP_Error
} elseif (is_array($result) && !$result['success']) {
    // manejo array
}
```

### **Después (Código Unificado)**
```php
// Tipo de retorno unificado
public function method(): SyncResponseInterface
{
    try {
        // lógica...
        return ResponseFactory::success($data, 'Operación exitosa');
    } catch (Exception $e) {
        return ResponseFactory::fromException($e, ['method' => 'method']);
    }
}

// Manejo unificado de errores
if (!$result->isSuccess()) {
    $error = $result->getError();
    $message = $result->getMessage();
    // manejo unificado
}
```

---

## 📋 Checklist de Migración

### **Fase 1: Preparación**
- [ ] Identificar métodos que retornan `array`
- [ ] Identificar métodos que retornan `WP_Error`
- [ ] Identificar métodos que lanzan excepciones
- [ ] Crear backup del código actual
- [ ] Configurar entorno de testing

### **Fase 2: Migración de Métodos Core**
- [ ] Cambiar tipo de retorno a `SyncResponseInterface`
- [ ] Reemplazar retornos de array con `ResponseFactory`
- [ ] Reemplazar lanzamiento de excepciones con `ResponseFactory`
- [ ] Actualizar documentación PHPDoc

### **Fase 3: Migración de Capas de Presentación**
- [ ] Actualizar manejo de respuestas en REST API
- [ ] Actualizar manejo de respuestas en AJAX
- [ ] Actualizar manejo de respuestas en CLI
- [ ] Usar `WordPressAdapter` para conversiones

### **Fase 4: Testing y Validación**
- [ ] Ejecutar tests unitarios
- [ ] Ejecutar tests de integración
- [ ] Validar funcionalidad en desarrollo
- [ ] Validar funcionalidad en staging

---

## 🔧 Patrones de Migración

### **Patrón 1: Métodos que Retornan Array**

#### **Antes:**
```php
public function processData(array $data): array
{
    try {
        $this->validateData($data);
        $result = $this->process($data);
        
        return [
            'success' => true,
            'data' => $result,
            'message' => 'Datos procesados correctamente'
        ];
    } catch (ValidationException $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
            'error_code' => 400
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Error interno del servidor',
            'error_code' => 500
        ];
    }
}
```

#### **Después:**
```php
public function processData(array $data): SyncResponseInterface
{
    try {
        $this->validateData($data);
        $result = $this->process($data);
        
        return ResponseFactory::success(
            $result,
            'Datos procesados correctamente',
            ['method' => 'processData']
        );
    } catch (ValidationException $e) {
        return ResponseFactory::validationError(
            $e->getMessage(),
            $e->getContext(),
            ['method' => 'processData', 'data' => $data]
        );
    } catch (Exception $e) {
        return ResponseFactory::fromException(
            $e,
            ['method' => 'processData', 'data' => $data]
        );
    }
}
```

### **Patrón 2: Métodos que Retornan WP_Error**

#### **Antes:**
```php
public function validateUser(int $userId): WP_Error|array
{
    if (!$this->userExists($userId)) {
        return new WP_Error(
            'user_not_found',
            'Usuario no encontrado',
            ['status' => 404, 'user_id' => $userId]
        );
    }
    
    if (!$this->userHasPermission($userId)) {
        return new WP_Error(
            'permission_denied',
            'Usuario sin permisos',
            ['status' => 403, 'user_id' => $userId]
        );
    }
    
    return ['success' => true, 'user' => $this->getUser($userId)];
}
```

#### **Después:**
```php
public function validateUser(int $userId): SyncResponseInterface
{
    if (!$this->userExists($userId)) {
        return ResponseFactory::error(
            'user_not_found',
            'Usuario no encontrado',
            ['user_id' => $userId],
            404
        );
    }
    
    if (!$this->userHasPermission($userId)) {
        return ResponseFactory::error(
            'permission_denied',
            'Usuario sin permisos',
            ['user_id' => $userId],
            403
        );
    }
    
    return ResponseFactory::success(
        ['user' => $this->getUser($userId)],
        'Usuario validado correctamente',
        ['method' => 'validateUser', 'user_id' => $userId]
    );
}
```

### **Patrón 3: Métodos que Lanzan Excepciones**

#### **Antes:**
```php
public function syncProduct(int $productId): array
{
    if (!$this->productExists($productId)) {
        throw new ProductNotFoundException("Producto {$productId} no encontrado");
    }
    
    if (!$this->canSyncProduct($productId)) {
        throw new SyncPermissionException("No se puede sincronizar el producto {$productId}");
    }
    
    $result = $this->performSync($productId);
    
    return [
        'success' => true,
        'data' => $result,
        'message' => 'Producto sincronizado correctamente'
    ];
}
```

#### **Después:**
```php
public function syncProduct(int $productId): SyncResponseInterface
{
    try {
        if (!$this->productExists($productId)) {
            throw new ProductNotFoundException("Producto {$productId} no encontrado");
        }
        
        if (!$this->canSyncProduct($productId)) {
            throw new SyncPermissionException("No se puede sincronizar el producto {$productId}");
        }
        
        $result = $this->performSync($productId);
        
        return ResponseFactory::success(
            $result,
            'Producto sincronizado correctamente',
            ['method' => 'syncProduct', 'product_id' => $productId]
        );
    } catch (ProductNotFoundException $e) {
        return ResponseFactory::error(
            'product_not_found',
            $e->getMessage(),
            ['product_id' => $productId],
            404
        );
    } catch (SyncPermissionException $e) {
        return ResponseFactory::error(
            'sync_permission_denied',
            $e->getMessage(),
            ['product_id' => $productId],
            403
        );
    } catch (Exception $e) {
        return ResponseFactory::fromException(
            $e,
            ['method' => 'syncProduct', 'product_id' => $productId]
        );
    }
}
```

---

## 🔄 Migración de Capas de Presentación

### **REST API Controller**

#### **Antes:**
```php
public function sync_products(\WP_REST_Request $request)
{
    $params = $request->get_params();
    
    try {
        $result = $this->sync_manager->sync_products($params);
        
        if (is_wp_error($result)) {
            return new \WP_Error(
                'sync_error',
                $result->get_error_message(),
                ['status' => $result->get_error_code()]
            );
        }
        
        if (!$result['success']) {
            return new \WP_Error(
                'sync_failed',
                $result['error'],
                ['status' => 500]
            );
        }
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $result['data']
        ], 200);
        
    } catch (Exception $e) {
        return new \WP_Error(
            'sync_exception',
            $e->getMessage(),
            ['status' => 500]
        );
    }
}
```

#### **Después:**
```php
public function sync_products(\WP_REST_Request $request)
{
    $params = $request->get_params();
    
    try {
        $result = $this->sync_manager->sync_products($params);
        
        // Conversión automática usando WordPressAdapter
        return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($result);
        
    } catch (Exception $e) {
        // Crear respuesta de error y convertir
        $errorResponse = ResponseFactory::fromException($e, ['endpoint' => 'sync_products']);
        return \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::toWpRestResponse($errorResponse);
    }
}
```

### **AJAX Handler**

#### **Antes:**
```php
public static function sync_products_batch()
{
    if (!self::validateAjaxSecurity('nonce')) {
        wp_send_json_error('Validación de seguridad falló');
        return;
    }
    
    $filters = $_REQUEST['filters'] ?? [];
    
    try {
        $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        $result = $sync_manager->handle_sync_request($filters);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        } elseif (is_array($result) && !$result['success']) {
            wp_send_json_error($result['error']);
        } else {
            wp_send_json_success($result);
        }
        
    } catch (Exception $e) {
        wp_send_json_error('Error interno: ' . $e->getMessage());
    }
}
```

#### **Después:**
```php
public static function sync_products_batch()
{
    if (!self::validateAjaxSecurity('nonce')) {
        wp_send_json_error('Validación de seguridad falló');
        return;
    }
    
    $filters = $_REQUEST['filters'] ?? [];
    
    try {
        $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        $result = $sync_manager->handle_sync_request($filters);
        
        // Envío unificado usando WordPressAdapter
        \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::sendAjaxResponse($result);
        
    } catch (Exception $e) {
        $errorResponse = ResponseFactory::fromException($e, ['ajax_handler' => 'sync_products_batch']);
        \MiIntegracionApi\ErrorHandling\Adapters\WordPressAdapter::sendAjaxResponse($errorResponse);
    }
}
```

### **CLI Command**

#### **Antes:**
```php
public function sync_products($args, $assoc_args)
{
    $entity = $args[0] ?? 'products';
    $direction = $args[1] ?? 'verial_to_wc';
    
    try {
        $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        $result = $sync_manager->start_sync($entity, $direction, []);
        
        if (is_wp_error($result)) {
            WP_CLI::error('Error: ' . $result->get_error_message());
        }
        
        if (is_array($result) && !$result['success']) {
            WP_CLI::error('Error: ' . $result['error']);
        }
        
        WP_CLI::success('Sincronización completada: ' . json_encode($result));
        
    } catch (Exception $e) {
        WP_CLI::error('Excepción: ' . $e->getMessage());
    }
}
```

#### **Después:**
```php
public function sync_products($args, $assoc_args)
{
    $entity = $args[0] ?? 'products';
    $direction = $args[1] ?? 'verial_to_wc';
    
    try {
        $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        $result = $sync_manager->start_sync($entity, $direction, []);
        
        if (!$result->isSuccess()) {
            WP_CLI::error('Error: ' . $result->getMessage());
        }
        
        WP_CLI::success('Sincronización completada: ' . $result->getMessage());
        
        // Mostrar datos adicionales si están disponibles
        $data = $result->getData();
        if (!empty($data)) {
            WP_CLI::log('Datos: ' . json_encode($data));
        }
        
    } catch (Exception $e) {
        WP_CLI::error('Excepción: ' . $e->getMessage());
    }
}
```

---

## 🧪 Testing de Migración

### **Test Unitario - Antes**
```php
public function test_sync_products_returns_array()
{
    $result = $this->sync_manager->sync_products(['test' => true]);
    
    $this->assertIsArray($result);
    $this->assertArrayHasKey('success', $result);
    $this->assertTrue($result['success']);
    $this->assertArrayHasKey('data', $result);
}
```

### **Test Unitario - Después**
```php
public function test_sync_products_returns_sync_response()
{
    $result = $this->sync_manager->sync_products(['test' => true]);
    
    $this->assertInstanceOf(SyncResponseInterface::class, $result);
    $this->assertTrue($result->isSuccess());
    $this->assertIsArray($result->getData());
    $this->assertNotEmpty($result->getMessage());
}
```

### **Test de Integración - REST API**
```php
public function test_rest_api_returns_wp_rest_response()
{
    $request = new \WP_REST_Request('POST', '/mi-integracion-api/v1/sync/start');
    $request->set_param('entity', 'products');
    $request->set_param('direction', 'verial_to_wc');
    
    $response = $this->rest_controller->sync_products($request);
    
    $this->assertInstanceOf(\WP_REST_Response::class, $response);
    $this->assertEquals(200, $response->get_status());
    
    $data = $response->get_data();
    $this->assertArrayHasKey('success', $data);
    $this->assertTrue($data['success']);
}
```

---

## 🔍 Herramientas de Migración

### **Script de Análisis de Código Legacy**
```bash
#!/bin/bash
# Encontrar métodos que retornan array
grep -r "): array" includes/ --include="*.php"

# Encontrar métodos que retornan WP_Error
grep -r "): WP_Error" includes/ --include="*.php"

# Encontrar uso de is_wp_error
grep -r "is_wp_error" includes/ --include="*.php"

# Encontrar retornos de array con success
grep -r "return \[" includes/ --include="*.php" | grep "success"
```

### **Script de Validación Post-Migración**
```bash
#!/bin/bash
# Verificar que no hay retornos de array
grep -r "return \[" includes/ --include="*.php" | grep -v "ResponseFactory"

# Verificar que se usa SyncResponseInterface
grep -r "SyncResponseInterface" includes/ --include="*.php"

# Verificar que se usa ResponseFactory
grep -r "ResponseFactory::" includes/ --include="*.php"
```

---

## ⚠️ Problemas Comunes y Soluciones

### **Problema 1: Error de Tipo en Tests**
```
Error: Return type declaration must be compatible with parent
```

**Solución:**
```php
// Actualizar la declaración de tipo en la clase padre
abstract class BaseService
{
    abstract public function process(): SyncResponseInterface; // Cambiar de array
}
```

### **Problema 2: Código Legacy que Espera Array**
```php
// Código que no se puede cambiar inmediatamente
$result = $this->newMethod(); // Retorna SyncResponseInterface
$data = $result->toArray(); // Convertir a array para compatibilidad
```

**Solución:**
```php
// Usar toArray() para compatibilidad temporal
if ($result->isSuccess()) {
    $legacyData = $result->toArray();
    $this->legacyMethod($legacyData);
}
```

### **Problema 3: Tests que Fallan Después de Migración**
```
Failed asserting that array is instance of SyncResponseInterface
```

**Solución:**
```php
// Actualizar assertions en tests
$this->assertInstanceOf(SyncResponseInterface::class, $result);
$this->assertTrue($result->isSuccess());
```

---

## 📊 Métricas de Éxito

### **Antes de la Migración**
- **Consistencia:** 4/10 - Múltiples tipos de retorno
- **Mantenibilidad:** 6/10 - Código duplicado
- **Testing:** 5/10 - Difícil de testear

### **Después de la Migración**
- **Consistencia:** 9/10 - Sistema unificado
- **Mantenibilidad:** 9/10 - Código limpio
- **Testing:** 9/10 - Fácil de testear

### **Indicadores de Éxito**
- ✅ 100% de métodos core usando `SyncResponseInterface`
- ✅ 0 métodos legacy identificados
- ✅ > 90% de cobertura de tests
- ✅ 0 regresiones en funcionalidad

---

## 🚀 Próximos Pasos

1. **Completar migración de módulos core** (Prioridad 1)
2. **Actualizar tests para nueva arquitectura** (Prioridad 2)
3. **Migrar capas de presentación** (Prioridad 3)
4. **Optimizar performance de adaptadores** (Prioridad 4)

---

## 📚 Recursos Adicionales

- [Arquitectura del Sistema de Errores](./arquitectura-sistema-errores.md)
- [Documentación de SyncResponseInterface](../includes/ErrorHandling/Responses/SyncResponseInterface.php)
- [Documentación de ResponseFactory](../includes/ErrorHandling/Handlers/ResponseFactory.php)
- [Documentación de WordPressAdapter](../includes/ErrorHandling/Adapters/WordPressAdapter.php)

---

**Última actualización:** 2025-01-26  
**Mantenido por:** Equipo de Desarrollo MiIntegracionApi
