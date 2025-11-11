# 🏗️ Implementación: Arquitectura en Dos Fases para Procesamiento de Imágenes

**Fecha**: 2025-11-04  
**Versión**: 1.0  
**Estado**: Pendiente Implementación  
**Prioridad**: ALTA

---

## 📋 Tabla de Contenidos

1. [Resumen Ejecutivo](#resumen-ejecutivo)
2. [Arquitectura de Dos Fases](#arquitectura-de-dos-fases)
3. [Análisis del Código Actual](#análisis-del-código-actual)
4. [Implementación Detallada](#implementación-detallada)
5. [Código a Comentar](#código-a-comentar)
6. [Plan de Migración](#plan-de-migración)
7. [Validación y Testing](#validación-y-testing)
8. [Rollback](#rollback)
9. [Consideraciones de Seguridad](#consideraciones-de-seguridad)

---

## 🎯 Resumen Ejecutivo

### Objetivo

Implementar una arquitectura en dos fases que separe completamente el procesamiento de imágenes del procesamiento de productos, resolviendo:

- ✅ **Timeouts en transacciones**: Reducción de 80-85% (imágenes fuera de transacciones)
- ✅ **Consumo de memoria**: Optimización con procesamiento en chunks
- ✅ **Reutilización automática**: 100% de reutilización en sincronizaciones repetidas
- ✅ **Escalabilidad**: Soporte para millones de productos

### Arquitectura

**Fase 1**: Procesar todas las imágenes primero
- Descargar imágenes de API
- Procesar Base64 en chunks (optimización de memoria)
- Guardar en media library con metadatos
- Crear índice: `article_id → [attachment_ids]`

**Fase 2**: Procesar productos y asignar imágenes
- Procesar productos normalmente (sin procesar imágenes)
- Buscar imágenes por `article_id` usando metadatos
- Asignar `attachment_ids` ya existentes

---

## 🏛️ Arquitectura de Dos Fases

### Diagrama de Flujo

```
┌─────────────────────────────────────────────────────────┐
│  FASE 1: PROCESAMIENTO MASIVO DE IMÁGENES               │
│  (Ejecutado independientemente, puede ser en background) │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│  1.1 Obtener todos los IDs de productos                 │
│      - GetArticulosWS (paginación completa)             │
│      - Extraer: [ID1, ID2, ID3, ...]                    │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│  1.2 Para cada producto:                                │
│      - GetImagenesArticulosWS?id_articulo=X             │
│      - Obtener imágenes Base64                          │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│  1.3 Para cada imagen Base64:                            │
│      - Procesar en chunks (10KB)                        │
│      - Escribir a archivo temporal                       │
│      - Verificar duplicados por hash                    │
│      - Si no existe: crear attachment                  │
│      - Guardar metadatos:                                │
│        * _verial_article_id                             │
│        * _verial_image_hash                             │
│        * _verial_image_order                            │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│  1.4 Crear índice en memoria:                            │
│      $images_index[article_id] = [attachment_id1, ...] │
└─────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────┐
│  FASE 2: SINCRONIZACIÓN DE PRODUCTOS                    │
│  (Flujo normal, sin procesar imágenes)                  │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│  2.1 prepare_complete_batch_data()                      │
│      - ❌ NO obtener imágenes (código comentado)        │
│      - Obtener productos, stock, precios, etc.          │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│  2.2 MapProduct::processProductImages()                  │
│      - Buscar attachments por article_id                 │
│      - Usar get_attachments_by_article_id()              │
│      - Retornar [attachment_id1, attachment_id2, ...]    │
└─────────────────────────────────────────────────────────┘
                           ↓
┌─────────────────────────────────────────────────────────┐
│  2.3 handlePostSaveOperations()                         │
│      - setProductImages() con attachment_ids             │
│      - setProductGallery() con attachment_ids           │
│      - No procesar Base64 (ya son IDs)                   │
└─────────────────────────────────────────────────────────┘
```

---

## 🔍 Análisis del Código Actual

### Puntos de Entrada Identificados

#### 1. Obtención de Imágenes

**Archivo**: `includes/Core/BatchProcessor.php`

**Métodos a Modificar**:
- `prepare_complete_batch_data()` (línea ~2312)
  - **Línea 2313**: `$imagenes_response = $this->get_imagenes_batch($inicio, $fin);`
  - **Líneas 2315-2412**: Lógica de fallback y validación de imágenes
  - **Acción**: Comentar todo este bloque

**Métodos a Comentar (NO Eliminar)**:
- `get_imagenes_batch()` (línea 1651)
  - Mantener para rollback
  - Comentar cuerpo del método
- `get_imagenes_for_products()` (línea 1701)
  - Mantener para rollback
  - Comentar cuerpo del método

#### 2. Procesamiento de Imágenes

**Archivo**: `includes/Helpers/MapProduct.php`

**Método a Modificar**:
- `processProductImages()` (línea 623)
  - **Líneas 631-694**: Búsqueda lineal en `batch_cache['imagenes_productos']`
  - **Acción**: Reemplazar por búsqueda en media library

**Archivo**: `includes/Core/BatchProcessor.php`

**Métodos a Modificar**:
- `createAttachmentFromBase64()` (línea 4671)
  - **Acción**: Usar solo en Fase 1 (procesamiento masivo)
  - Mantener para rollback
- `processImageItem()` (línea 4544)
  - **Acción**: Modificar para aceptar attachment_ids directamente
  - Mantener lógica Base64 comentada para rollback

#### 3. Asignación de Imágenes

**Archivo**: `includes/Core/BatchProcessor.php`

**Métodos a Modificar**:
- `setProductImages()` (línea 4597)
  - **Acción**: Aceptar attachment_ids directamente (no Base64)
- `setProductGallery()` (línea 4635)
  - **Acción**: Aceptar attachment_ids directamente (no Base64)

---

## 💻 Implementación Detallada

### Fase 1: Sistema de Procesamiento Masivo de Imágenes

#### 1.1 Nueva Clase: `ImageSyncManager`

**Archivo**: `includes/Sync/ImageSyncManager.php`

```php
<?php

namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Logging\Logger;
use MiIntegracionApi\ErrorHandling\Responses\ResponseFactory;

/**
 * Gestiona la sincronización masiva de imágenes en dos fases.
 *
 * Esta clase implementa la Fase 1 de la arquitectura en dos fases:
 * procesa todas las imágenes primero, antes de sincronizar productos.
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       1.5.0
 */
class ImageSyncManager
{
    /**
     * Instancia del conector de API.
     *
     * @var ApiConnector
     */
    private ApiConnector $apiConnector;

    /**
     * Instancia del logger.
     *
     * @var Logger
     */
    private Logger $logger;

    /**
     * Tamaño de chunk para procesamiento Base64 (en bytes).
     *
     * @var int
     */
    private int $chunkSize;

    /**
     * Constructor.
     *
     * @param ApiConnector $apiConnector Instancia del conector de API.
     * @param Logger        $logger       Instancia del logger.
     */
    public function __construct(ApiConnector $apiConnector, Logger $logger)
    {
        $this->apiConnector = $apiConnector;
        $this->logger = $logger;
        $this->chunkSize = 10 * 1024; // 10KB
    }

    /**
     * Procesa todas las imágenes de todos los productos.
     *
     * Obtiene todos los IDs de productos, descarga sus imágenes,
     * las procesa en chunks y las guarda en la media library.
     *
     * @param   int|null $resume_from_product_id ID de producto para reanudar (opcional).
     * @return  array                           Estadísticas del procesamiento.
     */
    public function syncAllImages(?int $resume_from_product_id = null): array
    {
        $stats = [
            'total_processed' => 0,
            'total_attachments' => 0,
            'duplicates_skipped' => 0,
            'errors' => 0,
            'last_processed_id' => 0
        ];

        try {
            // 1. Obtener todos los IDs de productos
            $product_ids = $this->getAllProductIds();
            
            $this->logger->info('Iniciando sincronización masiva de imágenes', [
                'total_products' => count($product_ids),
                'resume_from' => $resume_from_product_id
            ]);

            // 2. Procesar imágenes por producto
            $start_index = $resume_from_product_id 
                ? array_search($resume_from_product_id, $product_ids) 
                : 0;

            if ($start_index === false) {
                $start_index = 0;
            }

            for ($i = $start_index; $i < count($product_ids); $i++) {
                $product_id = $product_ids[$i];
                
                $result = $this->processProductImages($product_id);
                
                $stats['total_processed']++;
                $stats['total_attachments'] += $result['attachments'];
                $stats['duplicates_skipped'] += $result['duplicates'];
                $stats['errors'] += $result['errors'];
                $stats['last_processed_id'] = $product_id;

                // Guardar checkpoint cada 100 productos
                if ($stats['total_processed'] % 100 === 0) {
                    $this->saveCheckpoint($stats);
                }
            }

            $this->logger->info('Sincronización masiva de imágenes completada', $stats);

            return $stats;

        } catch (\Exception $e) {
            $this->logger->error('Error en sincronización masiva de imágenes', [
                'error' => $e->getMessage(),
                'stats' => $stats
            ]);
            throw $e;
        }
    }

    /**
     * Obtiene todos los IDs de productos desde la API.
     *
     * @return  array Array de IDs de productos.
     */
    private function getAllProductIds(): array
    {
        $product_ids = [];
        $page_size = 100;
        $inicio = 1;

        while (true) {
            $fin = $inicio + $page_size - 1;
            
            $params = [
                'x' => $this->apiConnector->get_session_number(),
                'id_cliente' => 0,
                'fecha' => date('Y-m-d'),
                'hora' => date('H:i')
            ];

            $response = $this->apiConnector->get('GetArticulosWS', $params);
            
            if (!$response->isSuccess()) {
                $this->logger->warning('Error obteniendo productos', [
                    'inicio' => $inicio,
                    'fin' => $fin,
                    'error' => $response->getMessage()
                ]);
                break;
            }

            $data = $response->getData();
            $articulos = $data['Articulos'] ?? [];

            if (empty($articulos)) {
                break;
            }

            foreach ($articulos as $articulo) {
                if (!empty($articulo['Id'])) {
                    $product_ids[] = (int)$articulo['Id'];
                }
            }

            // Si obtenemos menos productos de los esperados, es la última página
            if (count($articulos) < $page_size) {
                break;
            }

            $inicio = $fin + 1;
        }

        return array_unique($product_ids);
    }

    /**
     * Procesa todas las imágenes de un producto específico.
     *
     * @param   int $product_id ID del producto.
     * @return  array Estadísticas del procesamiento.
     */
    private function processProductImages(int $product_id): array
    {
        $stats = [
            'attachments' => 0,
            'duplicates' => 0,
            'errors' => 0
        ];

        try {
            // Obtener imágenes del producto
            $params = [
                'x' => $this->apiConnector->get_session_number(),
                'id_articulo' => $product_id,
                'numpixelsladomenor' => 300
            ];

            $response = $this->apiConnector->get('GetImagenesArticulosWS', $params);
            
            if (!$response->isSuccess()) {
                $this->logger->warning('Error obteniendo imágenes del producto', [
                    'product_id' => $product_id,
                    'error' => $response->getMessage()
                ]);
                $stats['errors']++;
                return $stats;
            }

            $data = $response->getData();
            $imagenes = $data['Imagenes'] ?? [];

            if (empty($imagenes)) {
                return $stats;
            }

            // Procesar cada imagen
            foreach ($imagenes as $index => $imagen_data) {
                if (empty($imagen_data['Imagen'])) {
                    continue;
                }

                $base64_image = 'data:image/jpeg;base64,' . $imagen_data['Imagen'];
                
                $attachment_id = $this->processImageFromBase64(
                    $base64_image,
                    $product_id,
                    $index
                );

                if ($attachment_id === false) {
                    $stats['errors']++;
                } elseif ($attachment_id === 'duplicate') {
                    $stats['duplicates']++;
                } else {
                    $stats['attachments']++;
                }
            }

            return $stats;

        } catch (\Exception $e) {
            $this->logger->error('Error procesando imágenes del producto', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            $stats['errors']++;
            return $stats;
        }
    }

    /**
     * Procesa una imagen Base64 y la guarda en la media library.
     *
     * Usa procesamiento en chunks para optimizar memoria.
     *
     * @param   string $base64_image Imagen en formato Base64.
     * @param   int    $article_id    ID del artículo de Verial.
     * @param   int    $order         Orden de la imagen (0 = principal).
     * @return  int|false|string      Attachment ID, false si error, 'duplicate' si ya existe.
     */
    private function processImageFromBase64(string $base64_image, int $article_id, int $order = 0): int|false|string
    {
        try {
            // 1. Calcular hash para verificar duplicados
            $image_hash = md5($base64_image);
            
            // 2. Verificar si ya existe
            $existing_attachment = $this->findAttachmentByHash($image_hash, $article_id);
            
            if ($existing_attachment) {
                // Actualizar orden si es necesario
                $current_order = get_post_meta($existing_attachment, '_verial_image_order', true);
                if ($current_order !== $order) {
                    update_post_meta($existing_attachment, '_verial_image_order', $order);
                }
                return 'duplicate';
            }

            // 3. Extraer tipo y datos Base64
            if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64_image, $matches)) {
                $this->logger->error('Formato Base64 inválido', [
                    'article_id' => $article_id
                ]);
                return false;
            }

            $image_type = $matches[1];
            $base64_data = $matches[2];

            // 4. Procesar en chunks y escribir a archivo temporal
            $temp_file = $this->writeBase64ToTempFile($base64_data);
            
            if ($temp_file === false) {
                $this->logger->error('Error escribiendo archivo temporal', [
                    'article_id' => $article_id
                ]);
                return false;
            }

            // 5. Leer archivo temporal completo y subir a WordPress
            $file_content = file_get_contents($temp_file);
            if ($file_content === false) {
                unlink($temp_file);
                return false;
            }

            $filename = 'verial-image-' . $article_id . '-' . uniqid() . '.' . $image_type;
            
            $upload = mi_integracion_api_upload_bits_safe($filename, null, $file_content);
            
            // Limpiar archivo temporal
            unlink($temp_file);

            if ($upload === false) {
                $this->logger->error('Error subiendo imagen', [
                    'article_id' => $article_id
                ]);
                return false;
            }

            // 6. Crear attachment
            $attachment = [
                'post_mime_type' => 'image/' . $image_type,
                'post_title' => mi_integracion_api_sanitize_file_name_safe($filename),
                'post_content' => '',
                'post_status' => 'inherit'
            ];

            $attachment_id = wp_insert_attachment($attachment, $upload['file'], 0);
            
            if (is_wp_error($attachment_id)) {
                $this->logger->error('Error creando attachment', [
                    'article_id' => $article_id,
                    'error' => $attachment_id->get_error_message()
                ]);
                return false;
            }

            // 7. Generar metadatos del attachment
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
            wp_update_attachment_metadata($attachment_id, $attachment_data);

            // 8. Guardar metadatos personalizados
            update_post_meta($attachment_id, '_verial_article_id', $article_id);
            update_post_meta($attachment_id, '_verial_image_hash', $image_hash);
            update_post_meta($attachment_id, '_verial_image_order', $order);

            $this->logger->debug('Imagen procesada exitosamente', [
                'article_id' => $article_id,
                'attachment_id' => $attachment_id,
                'order' => $order
            ]);

            return $attachment_id;

        } catch (\Exception $e) {
            $this->logger->error('Excepción procesando imagen Base64', [
                'article_id' => $article_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Escribe una cadena Base64 a un archivo temporal en chunks.
     *
     * @param   string $base64_data Datos Base64 (sin prefijo data:image/...).
     * @return  string|false        Ruta del archivo temporal o false si error.
     */
    private function writeBase64ToTempFile(string $base64_data): string|false
    {
        $temp_path = tempnam(sys_get_temp_dir(), 'verial_img_');
        
        if ($temp_path === false) {
            return false;
        }

        $handle = fopen($temp_path, 'wb');
        
        if (!$handle) {
            return false;
        }

        $length = strlen($base64_data);
        
        // Procesar en chunks de 10KB
        for ($start = 0; $start < $length; $start += $this->chunkSize) {
            $end = min($start + $this->chunkSize, $length);
            $chunk = substr($base64_data, $start, $end - $start);
            
            $decoded_chunk = base64_decode($chunk);
            
            if ($decoded_chunk === false) {
                fclose($handle);
                unlink($temp_path);
                return false;
            }
            
            if (fwrite($handle, $decoded_chunk) === false) {
                fclose($handle);
                unlink($temp_path);
                return false;
            }
        }

        fclose($handle);
        return $temp_path;
    }

    /**
     * Busca un attachment existente por hash MD5.
     *
     * @param   string $image_hash Hash MD5 de la imagen.
     * @param   int    $article_id ID del artículo (opcional, para optimizar búsqueda).
     * @return  int|false           Attachment ID o false si no existe.
     */
    private function findAttachmentByHash(string $image_hash, ?int $article_id = null): int|false
    {
        global $wpdb;

        $query = "
            SELECT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = '_verial_image_hash' 
            AND meta_value = %s
        ";

        $params = [$image_hash];

        // Si tenemos article_id, buscar también por ese (más rápido)
        if ($article_id !== null) {
            $query .= " AND post_id IN (
                SELECT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = '_verial_article_id' 
                AND meta_value = %d
            )";
            $params[] = $article_id;
        }

        $query .= " LIMIT 1";

        $attachment_id = $wpdb->get_var($wpdb->prepare($query, ...$params));

        return $attachment_id ? (int)$attachment_id : false;
    }

    /**
     * Guarda un checkpoint del progreso.
     *
     * @param   array $stats Estadísticas actuales.
     * @return  void
     */
    private function saveCheckpoint(array $stats): void
    {
        update_option('mia_images_sync_checkpoint', [
            'last_processed_id' => $stats['last_processed_id'],
            'stats' => $stats,
            'timestamp' => time()
        ]);
    }
}
```

#### 1.2 Método Helper para Obtener Attachments por Article ID

**Archivo**: `includes/Helpers/MapProduct.php`

**Añadir al final de la clase**:

```php
/**
 * Obtiene attachment IDs de imágenes por ID de artículo de Verial.
 *
 * Busca en la media library attachments asociados a un artículo específico
 * usando metadatos. Retorna los attachments ordenados por _verial_image_order.
 *
 * @param   int $article_id ID del artículo de Verial.
 * @return  array Array de attachment IDs ordenados.
 * @since   1.5.0
 */
public static function get_attachments_by_article_id(int $article_id): array
{
    $args = [
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'meta_query' => [
            [
                'key' => '_verial_article_id',
                'value' => $article_id,
                'compare' => '='
            ]
        ],
        'posts_per_page' => -1,
        'fields' => 'ids'
    ];

    $attachment_ids = get_posts($args);

    if (empty($attachment_ids)) {
        return [];
    }

    // Ordenar por orden guardado
    usort($attachment_ids, function($a, $b) use ($article_id) {
        $order_a = get_post_meta($a, '_verial_image_order', true) ?: 999;
        $order_b = get_post_meta($b, '_verial_image_order', true) ?: 999;
        return $order_a <=> $order_b;
    });

    return array_map('intval', $attachment_ids);
}
```

---

### Fase 2: Modificación del Flujo de Sincronización

#### 2.1 Modificar `prepare_complete_batch_data()`

**Archivo**: `includes/Core/BatchProcessor.php`

**Ubicación**: Línea ~2312

**Cambio**:

```php
// ⚠️ CÓDIGO COMENTADO: Obtención de imágenes durante batch (ARQUITECTURA DOS FASES)
// Este código se ha comentado porque las imágenes ahora se procesan en una fase
// separada (Fase 1) antes de sincronizar productos. Las imágenes se buscan
// desde la media library usando metadatos durante el mapeo.
//
// Para rollback, descomentar este bloque y comentar la nueva lógica.

/*
// 1.4 GetImagenesArticulosWS - IMÁGENES de productos del lote específico (usar paginación por rango)
$imagenes_response = $this->get_imagenes_batch($inicio, $fin);
// ... resto del código comentado ...
*/

// ✅ NUEVO: Arquitectura en dos fases
// Las imágenes ya están procesadas en la Fase 1 y disponibles en la media library.
// No es necesario obtenerlas aquí. Se buscarán durante el mapeo.
$this->logger->debug('Sincronización en dos fases: imágenes omitidas en batch', [
    'inicio' => $inicio,
    'fin' => $fin,
    'nota' => 'Imágenes se buscarán desde media library durante mapeo'
]);
```

#### 2.2 Modificar `MapProduct::processProductImages()`

**Archivo**: `includes/Helpers/MapProduct.php`

**Ubicación**: Línea 623

**Cambio**:

```php
/**
 * Procesa las imágenes del producto desde la media library.
 *
 * En la arquitectura de dos fases, las imágenes ya están procesadas
 * y guardadas en la media library. Este método busca los attachments
 * asociados al artículo usando metadatos.
 *
 * @param   array $verial_product Datos del producto de Verial.
 * @param   array $product_data   Datos del producto de WooCommerce.
 * @param   array $batch_cache    Cache del batch (no usado en nueva arquitectura).
 * @return  array Datos del producto con imágenes asignadas.
 * @since   1.5.0
 */
private static function processProductImages(
    array $verial_product, 
    array $product_data, 
    array $batch_cache
): array {
    $verial_product_id = (int)($verial_product['Id'] ?? 0);
    $sku = $verial_product['ReferenciaBarras'] ?? $verial_product['Id'] ?? 'UNKNOWN';
    
    // ⚠️ CÓDIGO LEGACY COMENTADO: Búsqueda lineal en batch_cache
    // Este código se ha comentado porque la nueva arquitectura busca imágenes
    // directamente en la media library usando metadatos.
    //
    // Para rollback, descomentar este bloque y comentar la nueva lógica.
    /*
    if (!empty($batch_cache['imagenes_productos']) && is_array($batch_cache['imagenes_productos'])) {
        foreach ($batch_cache['imagenes_productos'] as $index => $imagen_data) {
            // ... código legacy comentado ...
        }
    }
    */

    // ✅ NUEVO: Buscar attachments en media library por article_id
    $attachment_ids = self::get_attachments_by_article_id($verial_product_id);
    
    if (empty($attachment_ids)) {
        self::$logger->debug('No se encontraron imágenes en media library', [
            'sku' => $sku,
            'verial_id' => $verial_product_id
        ]);
        $product_data['images'] = [];
        $product_data['gallery'] = [];
        return $product_data;
    }

    // Primera imagen va a images, resto a gallery
    $images = [array_shift($attachment_ids)];
    $gallery = $attachment_ids;

    $product_data['images'] = $images;
    $product_data['gallery'] = $gallery;

    self::$logger->debug('Imágenes encontradas en media library', [
        'sku' => $sku,
        'verial_id' => $verial_product_id,
        'total_images' => count($images) + count($gallery)
    ]);

    return $product_data;
}
```

#### 2.3 Modificar `processImageItem()` para Aceptar Attachment IDs

**Archivo**: `includes/Core/BatchProcessor.php`

**Ubicación**: Línea 4544

**Cambio**:

```php
/**
 * ✅ HELPER: Procesa una imagen individual y retorna el attachment_id
 * 
 * En la arquitectura de dos fases, las imágenes ya están procesadas
 * y se pasan como attachment_ids directamente. Este método ahora
 * acepta tanto attachment_ids como Base64 (para compatibilidad).
 * 
 * @param   mixed  $image       Imagen a procesar (ID numérico, Base64 o URL).
 * @param   int    $product_id  ID del producto asociado.
 * @param   string $context     Contexto para logging ('main_image' o 'gallery').
 * @return  int|false ID del attachment o false si no se pudo procesar.
 */
private function processImageItem($image, int $product_id, string $context = 'image'): int|false
{
    try {
        // ✅ NUEVO: Si es un ID numérico, retornar directamente (arquitectura dos fases)
        if (is_numeric($image)) {
            $attachment_id = (int)$image;
            
            // Verificar que el attachment existe
            if (get_post($attachment_id) && get_post_type($attachment_id) === 'attachment') {
                $this->getLogger()->debug("Imagen procesada desde attachment ID ({$context})", [
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id
                ]);
                return $attachment_id;
            } else {
                $this->getLogger()->warning("Attachment ID no válido", [
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id
                ]);
                return false;
            }
        }
        
        // ⚠️ CÓDIGO LEGACY COMENTADO: Procesamiento Base64
        // Este código se ha comentado porque en la arquitectura de dos fases
        // las imágenes ya están procesadas. Solo se mantiene para rollback.
        //
        // Para rollback, descomentar este bloque.
        /*
        elseif (is_string($image) && str_starts_with($image, 'data:image/')) {
            // Es una imagen Base64, crear attachment
            $attachment_id = $this->createAttachmentFromBase64($image, $product_id);
            if ($attachment_id) {
                $this->getLogger()->debug("Imagen procesada desde Base64 ({$context})", [
                    'product_id' => $product_id,
                    'attachment_id' => $attachment_id
                ]);
                return $attachment_id;
            } else {
                $this->getLogger()->error("Error creando attachment desde Base64 ({$context})", [
                    'product_id' => $product_id
                ]);
                return false;
            }
        }
        */
        
        elseif (is_string($image)) {
            // URL u otro formato no soportado
            $this->getLogger->warning("Formato de imagen no soportado", [
                'product_id' => $product_id,
                'image_type' => gettype($image),
                'context' => $context
            ]);
            return false;
        }
        
        return false;
        
    } catch (Exception $e) {
        $this->getLogger()->error('Error procesando imagen', [
            'product_id' => $product_id,
            'context' => $context,
            'error' => $e->getMessage()
        ]);
        return false;
    }
}
```

---

## 📝 Código a Comentar

### Resumen de Código a Comentar

| Archivo | Método/Líneas | Razón | Rollback |
|---------|---------------|-------|----------|
| `BatchProcessor.php` | `get_imagenes_batch()` (1651-1691) | No se usa en Fase 2 | Descomentar método |
| `BatchProcessor.php` | `get_imagenes_for_products()` (1701-1750) | No se usa en Fase 2 | Descomentar método |
| `BatchProcessor.php` | `prepare_complete_batch_data()` (2312-2412) | Bloque de obtención de imágenes | Descomentar bloque |
| `MapProduct.php` | `processProductImages()` (631-694) | Búsqueda lineal legacy | Descomentar bloque |
| `BatchProcessor.php` | `processImageItem()` (4550-4564) | Procesamiento Base64 | Descomentar bloque |
| `BatchProcessor.php` | `createAttachmentFromBase64()` (4671-4761) | Solo usar en Fase 1 | Mantener para Fase 1 |

### Plantilla de Comentarios

Todos los comentarios deben seguir este formato:

```php
// ⚠️ CÓDIGO COMENTADO: [Descripción breve]
// Este código se ha comentado porque [razón].
// [Información adicional si es necesario]
//
// Para rollback, descomentar este bloque y comentar la nueva lógica.
//
// Fecha de comentario: [fecha]
// Arquitectura: Dos Fases v1.0

/*
[CÓDIGO ORIGINAL AQUÍ]
*/
```

---

## 🔄 Plan de Migración

### Paso 1: Preparación (Sin Cambios en Código)

1. **Backup completo**:
   ```bash
   # Backup de base de datos
   wp db export backup_pre_dos_fases.sql
   
   # Backup de archivos
   tar -czf backup_pre_dos_fases.tar.gz wp-content/uploads/
   ```

2. **Verificar dependencias**:
   - Verificar que `ApiConnector` funciona correctamente
   - Verificar que `Logger` está disponible
   - Verificar permisos de escritura en `wp-content/uploads/`

### Paso 2: Implementar Fase 1

1. **Crear nueva clase**:
   - Crear `includes/Sync/ImageSyncManager.php`
   - Asegurar que sigue PSR-12 y PHPDoc completo

2. **Registrar en autoloader**:
   - Verificar que el namespace está en el autoloader
   - O añadir manualmente si es necesario

3. **Crear comando WP-CLI o endpoint AJAX**:
   ```php
   // En includes/Admin/ o includes/Cli/
   // Crear comando para ejecutar Fase 1 manualmente
   ```

4. **Ejecutar Fase 1**:
   ```bash
   # Opción 1: WP-CLI
   wp verial sync-images --all
   
   # Opción 2: AJAX desde admin
   # Botón en dashboard: "Sincronizar Imágenes"
   ```

### Paso 3: Implementar Fase 2

1. **Comentar código legacy**:
   - Seguir plantilla de comentarios
   - Comentar `prepare_complete_batch_data()` (bloque de imágenes)
   - Comentar `processProductImages()` (búsqueda lineal)

2. **Implementar nueva lógica**:
   - Añadir `get_attachments_by_article_id()` en `MapProduct`
   - Modificar `processProductImages()` para usar media library
   - Modificar `processImageItem()` para aceptar attachment_ids

3. **Testing incremental**:
   - Probar con 1 producto
   - Probar con 10 productos
   - Probar con 100 productos

### Paso 4: Validación

1. **Verificar imágenes asignadas**:
   - Verificar que productos tienen imágenes
   - Verificar que imágenes están en media library
   - Verificar que metadatos están correctos

2. **Verificar rendimiento**:
   - Medir tiempo de sincronización
   - Verificar que no hay timeouts
   - Verificar consumo de memoria

### Paso 5: Rollout Completo

1. **Monitorear errores**:
   - Revisar logs diariamente
   - Verificar métricas de rendimiento

2. **Ajustar si es necesario**:
   - Ajustar tamaño de chunks si es necesario
   - Ajustar frecuencia de checkpoints

---

## ✅ Validación y Testing

### Tests Unitarios

```php
// tests/ImageSyncManagerTest.php

class ImageSyncManagerTest extends TestCase
{
    public function test_processImageFromBase64_creates_attachment()
    {
        // Test que procesa Base64 y crea attachment
    }
    
    public function test_processImageFromBase64_detects_duplicates()
    {
        // Test que detecta duplicados por hash
    }
    
    public function test_get_attachments_by_article_id_returns_correct_attachments()
    {
        // Test que retorna attachments correctos
    }
}
```

### Tests de Integración

1. **Test completo de dos fases**:
   - Ejecutar Fase 1 para 10 productos
   - Ejecutar Fase 2 para los mismos 10 productos
   - Verificar que imágenes están asignadas

2. **Test de rollback**:
   - Descomentar código legacy
   - Comentar código nuevo
   - Verificar que funciona como antes

### Criterios de Aceptación

- ✅ Imágenes procesadas y guardadas en media library
- ✅ Metadatos correctos (`_verial_article_id`, `_verial_image_hash`, `_verial_image_order`)
- ✅ Productos tienen imágenes asignadas correctamente
- ✅ No hay timeouts en transacciones
- ✅ Consumo de memoria optimizado
- ✅ Duplicados detectados y reutilizados
- ✅ Rollback funcional

---

## 🔙 Rollback

### Procedimiento de Rollback

Si la nueva arquitectura falla, seguir estos pasos:

1. **Descomentar código legacy**:
   - Descomentar bloque en `prepare_complete_batch_data()`
   - Descomentar bloque en `processProductImages()`
   - Descomentar bloque en `processImageItem()`

2. **Comentar código nuevo**:
   - Comentar nueva lógica en `processProductImages()`
   - Comentar nueva lógica en `processImageItem()`

3. **Verificar funcionamiento**:
   - Ejecutar sincronización de prueba
   - Verificar que funciona como antes

4. **Restaurar backup si es necesario**:
   ```bash
   wp db import backup_pre_dos_fases.sql
   ```

### Checklist de Rollback

- [ ] Código legacy descomentado
- [ ] Código nuevo comentado
- [ ] Sincronización de prueba ejecutada
- [ ] Verificado que funciona como antes
- [ ] Backup restaurado si es necesario

---

## 🔒 Consideraciones de Seguridad

### Validación de Entradas

1. **Validar Base64**:
   - Verificar formato antes de procesar
   - Limitar tamaño máximo de imagen

2. **Validar Article IDs**:
   - Verificar que son números enteros
   - Verificar que existen en la API

3. **Sanitizar nombres de archivo**:
   - Usar `sanitize_file_name()` siempre
   - Limitar longitud de nombres

### Permisos

1. **Verificar permisos de escritura**:
   - Verificar permisos en `wp-content/uploads/`
   - Verificar permisos en directorio temporal

2. **Verificar permisos de usuario**:
   - Solo usuarios autorizados pueden ejecutar Fase 1
   - Solo usuarios autorizados pueden ejecutar Fase 2

### Seguridad de Archivos Temporales

1. **Limpiar archivos temporales**:
   - Siempre eliminar archivos temporales después de usar
   - Usar `tempnam()` para nombres únicos

2. **Validar contenido de archivos**:
   - Verificar que archivos son imágenes válidas
   - Verificar tipo MIME

---

## 📊 Métricas y Monitoreo

### Métricas a Monitorear

1. **Tiempo de sincronización**:
   - Tiempo total de Fase 1
   - Tiempo total de Fase 2
   - Tiempo por producto

2. **Uso de memoria**:
   - Memoria máxima durante Fase 1
   - Memoria máxima durante Fase 2

3. **Errores**:
   - Errores de procesamiento de imágenes
   - Errores de asignación de imágenes
   - Errores de duplicados

### Logging

Todos los logs deben incluir:
- Timestamp
- Nivel de log (debug, info, warning, error)
- Contexto relevante (product_id, article_id, attachment_id, etc.)

---

## 🔗 Referencias

- `docs/COMPARACION-SOLUCIONES-IMAGENES.md` - Comparación de soluciones
- `docs/ANALISIS-CONTEXTO-IMPLEMENTACION-BASE64.md` - Análisis de contexto
- `docs/ESTRATEGIA-SINCRONIZACION-SEPARADA-IMAGENES.md` - Estrategia base
- `docs/ESTRATEGIA-PAGINACION-MASIVA-MEDIA-LIBRARY.md` - Implementación detallada
- `docs/PRIORIDADES-IMPLEMENTACION.md` - Prioridades

---

**Última actualización**: 2025-11-04  
**Versión del documento**: 1.0  
**Estado**: Pendiente Implementación


