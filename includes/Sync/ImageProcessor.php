<?php

declare(strict_types=1);

namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Helpers\Logger;
use Exception;

/**
 * Interfaz para funciones auxiliares de procesamiento de imágenes.
 *
 * Define el contrato para sanitizar nombres de archivo y subir bits a WordPress.
 * Permite inyección de dependencias y facilita el testing.
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       2.0.0
 */
interface ImageProcessingHelpersInterface
{
    /**
     * Sanitiza un nombre de archivo.
     *
     * @param   string $filename Nombre de archivo a sanitizar.
     * @return  string           Nombre de archivo sanitizado.
     */
    public function sanitizeFileName(string $filename): string;

    /**
     * Sube bits a WordPress.
     *
     * @param   string      $name       Nombre del archivo.
     * @param   mixed       $deprecated Parámetro deprecated (mantenido por compatibilidad).
     * @param   string      $bits       Contenido binario del archivo.
     * @return  array|false             Array con 'file' y 'url' si éxito, false si error.
     */
    public function uploadBits(string $name, $deprecated, string $bits): array|false;
}

/**
 * Configuración inyectable para ImageProcessor.
 *
 * Permite configurar límites de tamaño y tipos de imagen permitidos
 * sin modificar el código de la clase.
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       2.0.0
 */
class ImageProcessorConfig
{
    /**
     * Constructor.
     *
     * @param   int   $maxBase64Size  Tamaño máximo de Base64 en bytes (por defecto 10MB).
     * @param   int   $maxDecodedSize Tamaño máximo de imagen decodificada en bytes (por defecto 15MB).
     * @param   array $allowedTypes   Tipos de imagen permitidos (por defecto: jpeg, jpg, png, gif, webp).
     */
    public function __construct(
        public readonly int $maxBase64Size = 10 * 1024 * 1024,
        public readonly int $maxDecodedSize = 15 * 1024 * 1024,
        public readonly array $allowedTypes = ['jpeg', 'jpg', 'png', 'gif', 'webp']
    ) {
    }
}

/**
 * Interfaz para procesadores de imágenes.
 *
 * Define el contrato para procesar imágenes desde Base64.
 * Permite inyección de dependencias y facilita el testing.
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       2.0.0
 */
interface ImageProcessorInterface
{
    /**
     * Procesa una imagen desde Base64 y la sube a WordPress.
     *
     * @param   string $base64_image Imagen en formato Base64.
     * @param   int    $article_id   ID del artículo de Verial.
     * @param   int    $order        Orden de la imagen (0 = principal).
     * @return  int|false|string      Attachment ID, false si error, ImageProcessor::DUPLICATE si ya existe.
     */
    public function processImageFromBase64(string $base64_image, int $article_id, int $order = 0): int|false|string;
}

/**
 * Implementación de WordPress para funciones auxiliares de procesamiento de imágenes.
 *
 * Wrapper alrededor de las funciones globales del plugin para sanitizar
 * nombres de archivo y subir bits a WordPress.
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       2.0.0
 */
class WordPressImageProcessingHelpers implements ImageProcessingHelpersInterface
{
    /**
     * Sanitiza un nombre de archivo usando la función global del plugin.
     *
     * @param   string $filename Nombre de archivo a sanitizar.
     * @return  string             Nombre de archivo sanitizado.
     */
    public function sanitizeFileName(string $filename): string
    {
        if (!function_exists('mi_integracion_api_sanitize_file_name_safe')) {
            // Fallback básico si la función no está disponible
            return preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        }
        
        return mi_integracion_api_sanitize_file_name_safe($filename);
    }

    /**
     * Sube bits a WordPress usando la función global del plugin.
     *
     * @param   string      $name       Nombre del archivo.
     * @param   mixed       $deprecated Parámetro deprecated (mantenido por compatibilidad).
     * @param   string      $bits       Contenido binario del archivo.
     * @return  array|false             Array con 'file' y 'url' si éxito, false si error.
     */
    public function uploadBits(string $name, $deprecated, string $bits): array|false
    {
        if (!function_exists('mi_integracion_api_upload_bits_safe')) {
            return false;
        }
        
        return mi_integracion_api_upload_bits_safe($name, $deprecated, $bits);
    }
}

/**
 * Procesa imágenes individuales desde Base64 y las guarda en la media library.
 *
 * Esta clase se encarga de:
 * - Validar formato y contenido Base64
 * - Procesar imágenes en chunks para optimizar memoria
 * - Detectar duplicados por hash MD5
 * - Guardar imágenes en la biblioteca de medios de WordPress
 * - Asociar metadatos personalizados (_verial_article_id, _verial_image_hash, _verial_image_order)
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       1.5.0
 */
class ImageProcessor implements ImageProcessorInterface
{
    /**
     * Constante que indica que la imagen ya existe (duplicado).
     *
     * @var string
     */
    public const DUPLICATE = 'duplicate';

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
     * Instancia de helpers para procesamiento de imágenes.
     *
     * @var ImageProcessingHelpersInterface
     */
    private ImageProcessingHelpersInterface $helpers;

    /**
     * Configuración del procesador de imágenes.
     *
     * @var ImageProcessorConfig
     */
    private ImageProcessorConfig $config;

    /**
     * Cache de instancia para hashes recientes.
     *
     * Almacena los resultados de búsquedas recientes para esta instancia específica.
     * Se combina con el cache estático para proporcionar doble capa de optimización.
     *
     * @var array<string, int|false>
     */
    private array $hashCache = [];

    /**
     * Tamaño máximo del cache de instancia.
     *
     * @var int
     */
    private const MAX_INSTANCE_CACHE_SIZE = 1000;

    /**
     * Constructor.
     *
     * @param   Logger                                    $logger   Instancia del logger.
     * @param   int                                       $chunkSize Tamaño de chunk para procesamiento Base64 (por defecto 10KB).
     * @param   ImageProcessingHelpersInterface|null      $helpers  Instancia de helpers para procesamiento (opcional, usa WordPressImageProcessingHelpers por defecto).
     * @param   ImageProcessorConfig|null                 $config   Configuración del procesador (opcional, usa valores por defecto).
     */
    public function __construct(
        Logger $logger,
        int $chunkSize = 10240,
        ?ImageProcessingHelpersInterface $helpers = null,
        ?ImageProcessorConfig $config = null
    ) {
        $this->logger = $logger;
        $this->chunkSize = $chunkSize;
        $this->helpers = $helpers ?? new WordPressImageProcessingHelpers();
        $this->config = $config ?? new ImageProcessorConfig();
    }

    /**
     * Procesa una imagen Base64 y la guarda en la media library.
     *
     * Usa procesamiento en chunks para optimizar memoria.
     * Incluye validaciones de seguridad: formato Base64, tamaño máximo, tipos MIME.
     *
     * @param   string $base64_image Imagen en formato Base64.
     * @param   int    $article_id    ID del artículo de Verial.
     * @param   int    $order         Orden de la imagen (0 = principal).
     * @return  int|false|string      Attachment ID, false si error, self::DUPLICATE si ya existe.
     */
    public function processImageFromBase64(string $base64_image, int $article_id, int $order = 0): int|false|string
    {
        $temp_file = null;
        try {
            // 1. Validar formato Base64 y extraer tipo y datos
            $parsed = $this->parseBase64ImageFormat($base64_image);
            if ($parsed === false) {
                $base64_truncated = $this->truncateForLog($base64_image, 50);
                $this->logger->error('Formato Base64 inválido', [
                    'article_id' => $article_id,
                    'base64_preview' => $base64_truncated['preview'],
                    'base64_truncated' => $base64_truncated['truncated'],
                    'base64_original_length' => $base64_truncated['original_length'] ?? null
                ]);
                return false;
            }

            $image_type = $parsed['type'];
            $base64_data = $parsed['data'];

            // 2. Validar tipo MIME permitido
            if (!$this->isAllowedImageType($image_type)) {
                $base64_truncated = $this->truncateForLog($base64_image, 100);
                $this->logger->error('Tipo de imagen no permitido - rechazado', [
                    'article_id' => $article_id,
                    'image_type' => $image_type,
                    'image_type_normalized' => strtolower($image_type),
                    'allowed_types' => $this->config->allowedTypes,
                    'base64_preview' => $base64_truncated['preview'],
                    'base64_truncated' => $base64_truncated['truncated'],
                    'base64_original_length' => $base64_truncated['original_length'] ?? null,
                    'action' => 'rejected_unsupported_type'
                ]);
                return false;
            }

            // 3. Validar tamaño máximo de Base64
            if (!$this->isBase64SizeValid($base64_data, $this->config->maxBase64Size)) {
                $base64_length = strlen($base64_data);
                $this->logger->error('Imagen Base64 demasiado grande', [
                    'article_id' => $article_id,
                    'base64_size_bytes' => $base64_length,
                    'max_size_bytes' => $this->config->maxBase64Size,
                    'size_mb' => round($base64_length / 1024 / 1024, 2)
                ]);
                return false;
            }

            // 4. Validar que Base64 es válido (solo caracteres permitidos)
            if (!$this->isValidBase64($base64_data)) {
                $this->logger->error('Base64 contiene caracteres inválidos', [
                    'article_id' => $article_id
                ]);
                return false;
            }

            // 5. Calcular hash para verificar duplicados
            $image_hash = md5($base64_image);

            // 6. Verificar si ya existe
            $existing_attachment = $this->findAttachmentByHash($image_hash, $article_id);

            if ($existing_attachment) {
                // Actualizar orden si es necesario
                $current_order = \get_post_meta($existing_attachment, '_verial_image_order', true);
                if ($current_order !== (string)$order) {
                    \update_post_meta($existing_attachment, '_verial_image_order', $order);
                }
                // ✅ MEJORADO: Limpiar variables antes de retornar (imagen duplicada, no necesita procesarse)
                unset($base64_data, $base64_image, $parsed, $image_type, $image_hash, $existing_attachment, $current_order);
                return self::DUPLICATE;
            }

            // 7. Procesar en chunks y escribir a archivo temporal
            $temp_file = $this->writeBase64ToTempFile($base64_data);
            
            // ✅ MEJORADO: Limpiar base64_data después de escribir archivo temporal
            // Ya no se necesita en memoria, está en el archivo temporal
            unset($base64_data);

            if ($temp_file === false) {
                $this->logger->error('Error escribiendo archivo temporal', [
                    'article_id' => $article_id
                ]);
                // ✅ MEJORADO: Limpiar variables antes de retornar
                unset($base64_image, $parsed, $image_type, $image_hash);
                return false;
            }

            // 8. Validar que el archivo temporal es una imagen válida
            $image_info = $this->validateImageFile($temp_file, $article_id);
            if ($image_info === false) {
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
                // ✅ MEJORADO: Limpiar variables antes de retornar
                unset($base64_image, $parsed, $image_type, $image_hash, $temp_file);
                return false;
            }

            // 9. Validar tipo MIME detectado coincide con el declarado
            $this->validateMimeType($image_info, $image_type, $article_id);

            // 10. Leer archivo temporal completo y subir a WordPress
            $file_content = file_get_contents($temp_file);
            if ($file_content === false) {
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
                $this->logger->error('Error leyendo archivo temporal', [
                    'article_id' => $article_id,
                    'temp_file' => $temp_file
                ]);
                // ✅ MEJORADO: Limpiar variables antes de retornar
                unset($base64_data, $base64_image, $parsed, $image_type, $image_hash);
                return false;
            }

            // Validar tamaño del contenido decodificado (debe ser razonable)
            if (strlen($file_content) > $this->config->maxDecodedSize) {
                $this->logger->error('Imagen decodificada demasiado grande', [
                    'article_id' => $article_id,
                    'decoded_size_bytes' => strlen($file_content),
                    'max_size_bytes' => $this->config->maxDecodedSize,
                    'size_mb' => round(strlen($file_content) / 1024 / 1024, 2)
                ]);
                if (file_exists($temp_file)) {
                    unlink($temp_file);
                }
                // ✅ MEJORADO: Limpiar variables antes de retornar
                unset($file_content, $base64_data, $base64_image, $parsed, $image_type, $image_hash);
                return false;
            }

            // 11. Subir imagen a WordPress
            $attachment_id = $this->uploadToWordPress($file_content, $image_type, $article_id, $image_hash, $order);

            // ✅ MEJORADO: Limpiar variables grandes inmediatamente después de subir
            // Esto libera memoria antes de continuar con la siguiente imagen
            unset($file_content, $base64_data, $base64_image, $parsed, $image_type, $image_info);
            
            // Limpiar archivo temporal
            if (file_exists($temp_file)) {
                @unlink($temp_file);
            }
            unset($temp_file);

            return $attachment_id;
        } catch (Exception $e) {
            $this->logger->error('Excepción procesando imagen Base64', [
                'article_id' => $article_id,
                'error' => $e->getMessage()
            ]);
            // Asegurar limpieza de archivo temporal en caso de excepción
            if (isset($temp_file) && $temp_file !== false && file_exists($temp_file)) {
                @unlink($temp_file);
            }
            // ✅ MEJORADO: Limpiar todas las variables posibles en caso de excepción
            unset($temp_file, $base64_data, $base64_image, $parsed, $image_type, $image_hash, $file_content, $image_info);
            return false;
        }
    }

    /**
     * Valida que una cadena Base64 tenga el formato correcto de data URI de imagen.
     *
     * @param   string $base64_image Cadena Base64 completa con prefijo data:image/...
     * @return  array|false         Array con 'type' y 'data' si es válido, false si no.
     */
    private function parseBase64ImageFormat(string $base64_image): array|false
    {
        if (!preg_match('/^data:image\/(\w+);base64,(.+)$/', $base64_image, $matches)) {
            return false;
        }

        return [
            'type' => strtolower($matches[1]),
            'data' => $matches[2]
        ];
    }

    /**
     * Valida que una cadena Base64 contenga solo caracteres válidos.
     *
     * @param   string $base64_data Datos Base64 (sin prefijo data:image/...).
     * @return  bool                true si es válido, false si no.
     */
    private function isValidBase64(string $base64_data): bool
    {
        return preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $base64_data) === 1;
    }

    /**
     * Valida que un tipo de imagen esté en la lista de tipos permitidos.
     *
     * @param   string $image_type Tipo de imagen (jpeg, png, gif, webp, etc.).
     * @return  bool                true si está permitido, false si no.
     */
    private function isAllowedImageType(string $image_type): bool
    {
        return in_array(strtolower($image_type), $this->config->allowedTypes, true);
    }

    /**
     * Valida que el tamaño de los datos Base64 no exceda el máximo permitido.
     *
     * @param   string $base64_data Datos Base64.
     * @param   int    $max_size     Tamaño máximo en bytes.
     * @return  bool                 true si está dentro del límite, false si no.
     */
    private function isBase64SizeValid(string $base64_data, int $max_size): bool
    {
        return strlen($base64_data) <= $max_size;
    }

    /**
     * Detecta el tipo de imagen por magic bytes.
     *
     * @param   string $file_path Ruta al archivo de imagen.
     * @return  string|false      Tipo detectado (JPEG, PNG, GIF, WEBP) o false si no se detecta.
     */
    private function detectImageTypeByMagicBytes(string $file_path): string|false
    {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return false;
        }

        $file_content_preview = file_get_contents($file_path, false, null, 0, 12);
        if ($file_content_preview === false) {
            return false;
        }

        $magic_bytes = bin2hex(substr($file_content_preview, 0, 4));

        // JPEG: FF D8 FF
        if (strpos($magic_bytes, 'ffd8') === 0) {
            return 'JPEG';
        }

        // PNG: 89 50 4E 47
        if (strpos($magic_bytes, '89504e47') === 0) {
            return 'PNG';
        }

        // GIF: 47 49 46 (GIF)
        if (strpos($magic_bytes, '474946') === 0) {
            return 'GIF';
        }

        // WEBP: RIFF...WEBP
        if (strpos($file_content_preview, 'RIFF') === 0 && strpos($file_content_preview, 'WEBP') !== false) {
            return 'WEBP';
        }

        return false;
    }

    /**
     * Valida que un archivo temporal sea una imagen válida usando getimagesize().
     *
     * @param   string $temp_file  Ruta al archivo temporal.
     * @param   int    $article_id ID del artículo.
     * @return  array|false        Array de información de imagen o false si no es válida.
     */
    private function validateImageFile(string $temp_file, int $article_id): array|false
    {
        $image_info = false;
        $getimagesize_error = null;
        
        // Configurar un manejador de errores temporal para capturar errores de getimagesize()
        $previous_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$getimagesize_error) {
            // Solo capturar errores relacionados con getimagesize()
            if (strpos($errstr, 'getimagesize') !== false ||
                strpos($errstr, 'image') !== false ||
                strpos($errstr, 'Invalid') !== false) {
                $getimagesize_error = [
                    'errno' => $errno,
                    'errstr' => $errstr,
                    'errfile' => $errfile,
                    'errline' => $errline
                ];
                return true; // Suprimir el error, lo manejamos nosotros
            }
            return false; // Dejar que otros errores se manejen normalmente
        });
        
        try {
            $image_info = getimagesize($temp_file);
        } catch (\Throwable $e) {
            $getimagesize_error = [
                'exception' => true,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            $image_info = false;
        } finally {
            // Restaurar el manejador de errores anterior
            if ($previous_error_handler !== null) {
                set_error_handler($previous_error_handler);
            } else {
                restore_error_handler();
            }
        }
        
        if ($image_info === false) {
            // Logging detallado con información del error capturado
            $error_context = [
                'article_id' => $article_id,
                'temp_file' => $temp_file,
                'temp_file_exists' => file_exists($temp_file),
                'temp_file_size' => file_exists($temp_file) ? filesize($temp_file) : 0,
                'temp_file_readable' => file_exists($temp_file) ? is_readable($temp_file) : false,
                'action' => 'getimagesize_failed'
            ];
            
            if ($getimagesize_error !== null) {
                $error_context['getimagesize_error'] = $getimagesize_error;
            }
            
            // Verificar si el archivo tiene contenido válido usando método dedicado
            if (file_exists($temp_file)) {
                $detected_type = $this->detectImageTypeByMagicBytes($temp_file);
                if ($detected_type !== false) {
                    $error_context['detected_type_by_magic_bytes'] = $detected_type;
                    
                    // Obtener magic bytes para logging adicional
                    $file_content_preview = file_get_contents($temp_file, false, null, 0, 4);
                    if ($file_content_preview !== false) {
                        $error_context['file_magic_bytes'] = bin2hex($file_content_preview);
                        $error_context['file_magic_bytes_ascii'] = $file_content_preview;
                    }
                } else {
                    $error_context['detected_type_by_magic_bytes'] = 'unknown';
                }
            }
            
            $this->logger->error('Archivo temporal no es una imagen válida o getimagesize() falló', $error_context);
            return false;
        }

        return $image_info;
    }

    /**
     * Obtiene el tipo MIME esperado para un tipo de imagen dado.
     *
     * @param   string $imageType Tipo de imagen (jpeg, jpg, png, gif, webp).
     * @return  string            Tipo MIME esperado o cadena vacía si no se encuentra.
     */
    private function getExpectedMimeType(string $imageType): string
    {
        $mimeTypes = [
            'jpeg' => 'image/jpeg',
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp'
        ];

        return $mimeTypes[strtolower($imageType)] ?? '';
    }

    /**
     * Valida que el tipo MIME detectado coincida con el declarado.
     *
     * @param   array  $image_info Información de la imagen de getimagesize().
     * @param   string $image_type Tipo de imagen declarado.
     * @param   int    $article_id ID del artículo.
     * @return  void
     */
    private function validateMimeType(array $image_info, string $image_type, int $article_id): void
    {
        $detected_mime = $image_info['mime'] ?? '';
        $expected_mime = $this->getExpectedMimeType($image_type);

        if (empty($expected_mime)) {
            $this->logger->error('Tipo MIME no encontrado en lista de esperados', [
                'article_id' => $article_id,
                'image_type' => $image_type,
                'allowed_types' => $this->config->allowedTypes,
                'action' => 'mime_not_in_expected_list'
            ]);
        } elseif (!empty($detected_mime) && $detected_mime !== $expected_mime) {
            $this->logger->warning('Tipo MIME detectado no coincide con el declarado', [
                'article_id' => $article_id,
                'declared_type' => $image_type,
                'declared_mime' => $expected_mime,
                'detected_mime' => $detected_mime,
                'image_width' => $image_info[0] ?? 'unknown',
                'image_height' => $image_info[1] ?? 'unknown',
                'action' => 'mime_mismatch_warning',
                'note' => 'Continuando procesamiento - el tipo declarado fue validado previamente'
            ]);
        }
    }

    /**
     * Sube una imagen a WordPress y crea el attachment con metadatos.
     *
     * @param   string $file_content Contenido del archivo de imagen.
     * @param   string $image_type    Tipo de imagen (jpeg, png, etc.).
     * @param   int    $article_id    ID del artículo de Verial.
     * @param   string $image_hash    Hash MD5 de la imagen.
     * @param   int    $order         Orden de la imagen.
     * @return  int|false             Attachment ID o false si error.
     */
    private function uploadToWordPress(string $file_content, string $image_type, int $article_id, string $image_hash, int $order): int|false
    {
        // Sanitizar nombre de archivo
        $filename = 'verial-image-' . \absint($article_id) . '-' . uniqid() . '.' . $image_type;
        $filename = $this->helpers->sanitizeFileName($filename);

        // Subir bits a WordPress
        $upload = $this->helpers->uploadBits($filename, null, $file_content);

        if ($upload === false) {
            $this->logger->error('Error subiendo imagen', [
                'article_id' => $article_id,
                'filename' => $filename
            ]);
            return false;
        }

        // Obtener tipo MIME esperado
        $mime_type = $this->getExpectedMimeType($image_type);
        if (empty($mime_type)) {
            // Fallback si no se encuentra el tipo MIME
            $mime_type = 'image/' . $image_type;
            $this->logger->warning('Tipo MIME no encontrado, usando fallback', [
                'article_id' => $article_id,
                'image_type' => $image_type,
                'fallback_mime' => $mime_type
            ]);
        }

        // Crear attachment
        $attachment = [
            'post_mime_type' => $mime_type,
            'post_title' => $this->helpers->sanitizeFileName($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        ];

        $attachment_id = \wp_insert_attachment($attachment, $upload['file'], 0);

        if (\is_wp_error($attachment_id)) {
            $this->logger->error('Error creando attachment', [
                'article_id' => $article_id,
                'error' => $attachment_id->get_error_message()
            ]);
            return false;
        }

        // Generar metadatos del attachment
        if (file_exists(ABSPATH . 'wp-admin/includes/image.php')) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $attachment_data = \wp_generate_attachment_metadata($attachment_id, $upload['file']);
        \wp_update_attachment_metadata($attachment_id, $attachment_data);

        // Guardar metadatos personalizados
        \update_post_meta($attachment_id, '_verial_article_id', $article_id);
        \update_post_meta($attachment_id, '_verial_image_hash', $image_hash);
        \update_post_meta($attachment_id, '_verial_image_order', $order);

        // ✅ NUEVO: Verificar que los metadatos se guardaron correctamente
        $saved_article_id = \get_post_meta($attachment_id, '_verial_article_id', true);
        if ($saved_article_id != $article_id) {
            $this->logger->error('Error: Metadato _verial_article_id no se guardó correctamente', [
                'attachment_id' => $attachment_id,
                'expected' => $article_id,
                'expected_type' => gettype($article_id),
                'saved' => $saved_article_id,
                'saved_type' => gettype($saved_article_id),
                'comparison' => ($saved_article_id == $article_id) ? 'loose_equal' : 'not_equal',
                'strict_comparison' => ($saved_article_id === $article_id) ? 'strict_equal' : 'not_strict_equal'
            ]);
        }

        // ✅ REMOVIDO: Log de debug por cada imagen (genera demasiado ruido)
        // Solo se registran errores o resúmenes periódicos

        return $attachment_id;
    }

    /**
     * Escribe una cadena Base64 a un archivo temporal en chunks.
     *
     * Procesa los datos Base64 en trozos pequeños para optimizar
     * el uso de memoria. Decodifica cada chunk y lo escribe directamente
     * al archivo temporal sin cargar todo el contenido en memoria.
     *
     * @param   string $base64_data Datos Base64 (sin prefijo data:image/...).
     * @return  string|false        Ruta del archivo temporal o false si error.
     */
    private function writeBase64ToTempFile(string $base64_data): string|false
    {
        // Obtener directorio temporal con validación
        $temp_dir = sys_get_temp_dir();
        if (empty($temp_dir) || !is_dir($temp_dir) || !is_writable($temp_dir)) {
            $this->logger->error('Directorio temporal no disponible o no escribible', [
                'temp_dir' => $temp_dir,
                'temp_dir_exists' => is_dir($temp_dir),
                'temp_dir_writable' => is_dir($temp_dir) ? is_writable($temp_dir) : false,
                'action' => 'temp_dir_unavailable'
            ]);
            return false;
        }

        $temp_path = tempnam($temp_dir, 'verial_img_');

        if ($temp_path === false) {
            $this->logger->error('Error creando archivo temporal con tempnam()', [
                'temp_dir' => $temp_dir,
                'temp_dir_exists' => is_dir($temp_dir),
                'temp_dir_writable' => is_writable($temp_dir),
                'temp_dir_permissions' => is_dir($temp_dir) ? substr(sprintf('%o', fileperms($temp_dir)), -4) : 'unknown',
                'disk_free_space' => disk_free_space($temp_dir) !== false ? round(disk_free_space($temp_dir) / 1024 / 1024, 2) . ' MB' : 'unknown',
                'base64_data_length' => strlen($base64_data),
                'action' => 'tempnam_failed',
                'error_context' => 'No se pudo crear archivo temporal para procesar imagen Base64'
            ]);
            return false;
        }

        $handle = fopen($temp_path, 'wb');

        if (!$handle) {
            $last_error = error_get_last();
            $this->logger->error('Error abriendo archivo temporal para escritura', [
                'temp_path' => $temp_path,
                'temp_path_exists' => file_exists($temp_path),
                'temp_path_writable' => file_exists($temp_path) ? is_writable($temp_path) : false,
                'temp_path_permissions' => file_exists($temp_path) ? substr(sprintf('%o', fileperms($temp_path)), -4) : 'unknown',
                'disk_free_space' => disk_free_space(dirname($temp_path)) !== false ? round(disk_free_space(dirname($temp_path)) / 1024 / 1024, 2) . ' MB' : 'unknown',
                'last_php_error' => $last_error ? $last_error['message'] : 'unknown',
                'base64_data_length' => strlen($base64_data),
                'action' => 'fopen_failed',
                'error_context' => 'No se pudo abrir archivo temporal para escribir datos Base64 decodificados'
            ]);
            
            if (file_exists($temp_path)) {
                @unlink($temp_path);
            }
            
            return false;
        }

        $length = strlen($base64_data);

        // Procesar en chunks respetando límites de bloques Base64 (4 caracteres)
        $base64_block_size = 4;
        $chunk_size_aligned = (int)(floor($this->chunkSize / $base64_block_size) * $base64_block_size);
        if ($chunk_size_aligned < $base64_block_size) {
            $chunk_size_aligned = $base64_block_size;
        }

        $chunk_count = 0;
        for ($start = 0; $start < $length; $start += $chunk_size_aligned) {
            $end = min($start + $chunk_size_aligned, $length);
            $chunk = substr($base64_data, $start, $end - $start);
            $chunk_count++;
            $is_last_chunk = $end >= $length;

            $strict_mode = !$is_last_chunk;
            $decoded_chunk = base64_decode($chunk, $strict_mode);

            if ($decoded_chunk === false || ($strict_mode && $decoded_chunk === '')) {
                $chunk_truncated = $this->truncateForLog($chunk, 50);
                $this->logger->error('Error decodificando chunk Base64', [
                    'chunk_number' => $chunk_count,
                    'chunk_start' => $start,
                    'chunk_end' => $end,
                    'chunk_length' => strlen($chunk),
                    'chunk_preview' => $chunk_truncated['preview'],
                    'chunk_truncated' => $chunk_truncated['truncated'],
                    'chunk_original_length' => $chunk_truncated['original_length'] ?? strlen($chunk),
                    'is_last_chunk' => $is_last_chunk,
                    'strict_mode' => $strict_mode,
                    'total_length' => $length,
                    'chunk_size_aligned' => $chunk_size_aligned
                ]);
                
                fclose($handle);
                if (file_exists($temp_path)) {
                    unlink($temp_path);
                }
                return false;
            }

            if (strlen($decoded_chunk) > 0 && fwrite($handle, $decoded_chunk) === false) {
                $this->logger->error('Error escribiendo chunk decodificado al archivo temporal', [
                    'chunk_number' => $chunk_count,
                    'decoded_chunk_size' => strlen($decoded_chunk),
                    'temp_path' => $temp_path
                ]);
                
                fclose($handle);
                if (file_exists($temp_path)) {
                    unlink($temp_path);
                }
                // ✅ MEJORADO: Limpiar variables antes de retornar
                unset($chunk, $decoded_chunk, $base64_data);
                return false;
            }
            
            // ✅ MEJORADO: Limpiar chunk después de escribirlo para liberar memoria inmediatamente
            unset($chunk, $decoded_chunk);
        }

        fclose($handle);
        // ✅ MEJORADO: Limpiar base64_data después de escribir el archivo temporal
        // Ya no se necesita en memoria, está en el archivo temporal
        unset($base64_data);
        return $temp_path;
    }

    /**
     * Cache estático para hashes recientes.
     *
     * Almacena los resultados de búsquedas recientes para evitar
     * consultas repetidas a la base de datos.
     *
     * @var array<string, int|false>
     */
    private static array $recent_hashes = [];

    /**
     * Tamaño máximo del cache de hashes recientes.
     *
     * @var int
     */
    private const MAX_CACHE_SIZE = 1000;

    /**
     * Busca un attachment existente por hash MD5.
     *
     * Optimizado con cache estático y timeout para evitar búsquedas eternas.
     *
     * @param   string $image_hash Hash MD5 de la imagen Base64 completa.
     * @param   int    $article_id ID del artículo (opcional, para optimizar búsqueda).
     * @return  int|false           Attachment ID o false si no existe.
     */
    private function findAttachmentByHash(string $image_hash, ?int $article_id = null): int|false
    {
        global $wpdb;

        // Validar que image_hash es un hash MD5 válido
        if (empty($image_hash) || !preg_match('/^[a-f0-9]{32}$/i', $image_hash)) {
            $this->logger->error('Hash MD5 inválido en findAttachmentByHash', [
                'image_hash' => $this->truncateForLog($image_hash, 50)['preview'],
                'image_hash_truncated' => $this->truncateForLog($image_hash, 50)['truncated'],
                'image_hash_original_length' => strlen($image_hash),
                'hash_length' => strlen($image_hash),
                'action' => 'invalid_hash_rejected'
            ]);
            return false;
        }

        // ✅ OPTIMIZADO: Crear clave de cache que incluye article_id para mayor precisión
        $cache_key = $image_hash . '_' . ($article_id ?? 'all');

        // ✅ OPTIMIZADO: Verificar cache de instancia primero (más rápido)
        if (isset($this->hashCache[$cache_key])) {
            $cached_result = $this->hashCache[$cache_key];
            // ✅ REMOVIDO: Log de debug por cada búsqueda en cache (genera demasiado ruido)
            return $cached_result;
        }

        // ✅ OPTIMIZADO: Verificar cache estático como segunda opción
        if (isset(self::$recent_hashes[$cache_key])) {
            $cached_result = self::$recent_hashes[$cache_key];
            // Guardar también en cache de instancia para acceso futuro más rápido
            $this->addToInstanceCache($cache_key, $cached_result);
            // ✅ REMOVIDO: Log de debug por cada búsqueda en cache (genera demasiado ruido)
            return $cached_result;
        }

        // Validar que article_id es un entero válido si se proporciona
        if ($article_id !== null) {
            $article_id = \absint($article_id);
            if ($article_id <= 0) {
                $this->logger->warning('article_id inválido en findAttachmentByHash, ignorando filtro', [
                    'article_id_original' => $article_id,
                    'action' => 'invalid_article_id_ignored'
                ]);
                $article_id = null;
                // Actualizar clave de cache si article_id era inválido
                $cache_key = $image_hash . '_all';
            }
        }

        // ✅ OPTIMIZADO: Timeout para evitar búsquedas eternas
        $timeout = 5; // segundos
        $start_time = microtime(true);

        // Configurar timeout de consulta si está disponible
        $original_query_timeout = null;
        if (method_exists($wpdb, 'set_query_timeout')) {
            $original_query_timeout = $wpdb->query_timeout ?? null;
            $wpdb->query_timeout = $timeout;
        }

        try {
            $query = "
                SELECT post_id
                FROM {$wpdb->postmeta}
                WHERE meta_key = %s
                AND meta_value = %s
            ";

            $params = ['_verial_image_hash', $image_hash];

            if ($article_id !== null && $article_id > 0) {
                $query .= " AND post_id IN (
                    SELECT post_id
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = %s
                    AND meta_value = %d
                )";
                $params[] = '_verial_article_id';
                $params[] = $article_id;
            }

            $query .= " LIMIT 1";

            $prepared_query = $wpdb->prepare($query, ...$params);
            
            if ($prepared_query === false) {
                $this->logger->error('Error preparando consulta SQL en findAttachmentByHash', [
                    'query_template' => $query,
                    'params_count' => count($params),
                    'action' => 'sql_prepare_failed'
                ]);
                return false;
            }

            // Verificar timeout antes de ejecutar
            $elapsed = microtime(true) - $start_time;
            if ($elapsed >= $timeout) {
                $this->logger->warning('Timeout alcanzado antes de ejecutar consulta', [
                    'image_hash' => substr($image_hash, 0, 8) . '...',
                    'elapsed_seconds' => round($elapsed, 3),
                    'timeout_seconds' => $timeout,
                    'action' => 'timeout_before_query'
                ]);
                return false;
            }

            $attachment_id = $wpdb->get_var($prepared_query);

            // Verificar timeout después de ejecutar
            $elapsed = microtime(true) - $start_time;
            if ($elapsed >= $timeout) {
                $this->logger->warning('Timeout alcanzado durante consulta', [
                    'image_hash' => substr($image_hash, 0, 8) . '...',
                    'elapsed_seconds' => round($elapsed, 3),
                    'timeout_seconds' => $timeout,
                    'action' => 'timeout_during_query'
                ]);
                return false;
            }

            if ($attachment_id !== null) {
                $attachment_id = \absint($attachment_id);
                if ($attachment_id <= 0) {
                    $attachment_id = false;
                } else {
                    $attachment_id = (int)$attachment_id;
                }
            } else {
                $attachment_id = false;
            }

            // ✅ OPTIMIZADO: Guardar en cache estático (limitar tamaño del cache)
            if (count(self::$recent_hashes) >= self::MAX_CACHE_SIZE) {
                // Eliminar el 20% más antiguo del cache (FIFO)
                $keys_to_remove = array_slice(array_keys(self::$recent_hashes), 0, (int)(self::MAX_CACHE_SIZE * 0.2));
                foreach ($keys_to_remove as $key) {
                    unset(self::$recent_hashes[$key]);
                }
            }
            self::$recent_hashes[$cache_key] = $attachment_id;

            // ✅ OPTIMIZADO: Guardar también en cache de instancia
            $this->addToInstanceCache($cache_key, $attachment_id);

            // ✅ REMOVIDO: Log de debug por cada búsqueda de hash (genera demasiado ruido)
            // Solo se registran errores o timeouts

            return $attachment_id;
        } catch (\Exception $e) {
            $elapsed = microtime(true) - $start_time;
            $this->logger->error('Excepción en findAttachmentByHash', [
                'image_hash' => substr($image_hash, 0, 8) . '...',
                'article_id' => $article_id,
                'error' => $e->getMessage(),
                'elapsed_seconds' => round($elapsed, 3),
                'action' => 'exception_during_query'
            ]);
            return false;
        } finally {
            // Restaurar timeout original si se modificó
            if ($original_query_timeout !== null && method_exists($wpdb, 'set_query_timeout')) {
                $wpdb->query_timeout = $original_query_timeout;
            }
        }
    }

    /**
     * Agrega un resultado al cache de instancia con límite de tamaño.
     *
     * Gestiona el tamaño del cache de instancia eliminando entradas antiguas
     * cuando se alcanza el límite máximo.
     *
     * @param   string      $cache_key Clave del cache.
     * @param   int|false   $result    Resultado a guardar (attachment ID o false).
     * @return  void
     */
    private function addToInstanceCache(string $cache_key, int|false $result): void
    {
        // Limitar tamaño del cache de instancia
        if (count($this->hashCache) >= self::MAX_INSTANCE_CACHE_SIZE) {
            // Eliminar el 20% más antiguo del cache (FIFO)
            $keys_to_remove = array_slice(array_keys($this->hashCache), 0, (int)(self::MAX_INSTANCE_CACHE_SIZE * 0.2));
            foreach ($keys_to_remove as $key) {
                unset($this->hashCache[$key]);
            }
        }
        
        $this->hashCache[$cache_key] = $result;
    }

    /**
     * Trunca una cadena de forma segura para logging.
     *
     * @param   string $string     Cadena a truncar.
     * @param   int    $max_length Longitud máxima (por defecto 100 caracteres).
     * @param   string $suffix     Sufijo a añadir si se trunca (por defecto '...').
     * @return  array              Array con 'preview' (string truncado) y 'truncated' (bool).
     */
    private function truncateForLog(string $string, int $max_length = 100, string $suffix = '...'): array
    {
        $length = strlen($string);
        $truncated = $length > $max_length;

        if (!$truncated) {
            return [
                'preview' => $string,
                'truncated' => false,
                'original_length' => $length
            ];
        }

        $preview = substr($string, 0, $max_length) . $suffix;

        return [
            'preview' => $preview,
            'truncated' => true,
            'original_length' => $length,
            'preview_length' => strlen($preview)
        ];
    }
}

