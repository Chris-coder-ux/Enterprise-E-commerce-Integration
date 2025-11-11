<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Handlers;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\ErrorHandling\Responses\SyncResponse;
use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;
use MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes;
use MiIntegracionApi\Logging\Core\LogManager;
use Throwable;

/**
 * Factory para crear respuestas del sistema de sincronización
 * 
 * Esta clase proporciona métodos estáticos para crear respuestas
 * de forma consistente y automática, facilitando la migración
 * desde el sistema actual de arrays.
 * 
 * @package MiIntegracionApi\ErrorHandling\Handlers
 * @since 1.0.0
 */
class ResponseFactory
{
    /**
     * Obtiene el logger para el sistema de errores
     * 
     * @return \MiIntegracionApi\Logging\Interfaces\ILogger
     */
    private static function getLogger(): \MiIntegracionApi\Logging\Interfaces\ILogger
    {
        $logManager = LogManager::getInstance();
        return $logManager->getLogger('error-handler');
    }
    /**
     * Crea una respuesta exitosa desde un array del sistema actual
     * 
     * @param array $data Array con datos del sistema actual
     * @return SyncResponseInterface
     */
    public static function fromArray(array $data): SyncResponseInterface
    {
        $success = $data['success'] ?? false;
        
        if ($success) {
            return SyncResponse::success(
                $data['data'] ?? [],
                $data['message'] ?? 'Operación completada exitosamente',
                $data['http_status'] ?? HttpStatusCodes::OK,
                $data['metadata'] ?? []
            );
        } else {
            $error = new SyncError(
                $data['error'] ?? 'Error desconocido',
                $data['error_code'] ?? HttpStatusCodes::INTERNAL_SERVER_ERROR,
                $data['error_context'] ?? []
            );
            
            return SyncResponse::error(
                $error,
                $data,
                $data['metadata'] ?? []
            );
        }
    }

    /**
     * Crea una respuesta exitosa con datos específicos
     * 
     * @param array $data Datos de la respuesta
     * @param string $message Mensaje de éxito
     * @param array $metadata Metadatos adicionales
     * @return SyncResponseInterface
     */
    public static function success(
        array $data = [],
        string $message = 'Operación completada exitosamente',
        array $metadata = []
    ): SyncResponseInterface {
        return SyncResponse::success($data, $message, HttpStatusCodes::OK, $metadata);
    }

    /**
     * Crea una respuesta de error con código específico
     * 
     * @param string $message Mensaje de error
     * @param int $code Código de error
     * @param array $context Contexto del error
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function error(
        string $message,
        int $code = HttpStatusCodes::INTERNAL_SERVER_ERROR,
        array $context = [],
        array $data = []
    ): SyncResponseInterface {
        $error = new SyncError($message, $code, $context);
        
        // Registrar el error automáticamente usando el sistema existente
        try {
            $logger = self::getLogger();
            $logger->error($message, array_merge($context, [
                'error_code' => $code,
                'data' => $data,
                'source' => 'ResponseFactory'
            ]));
        } catch (\Throwable $e) {
            // Fallback a error_log si hay problemas con el logger
            error_log("[MiIntegracionApi] ResponseFactory Error: {$message}");
        }
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Crea una respuesta de error de validación
     * 
     * @param string $message Mensaje de error de validación
     * @param array $validation_errors Errores de validación específicos
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function validationError(
        string $message,
        array $validation_errors = [],
        array $data = []
    ): SyncResponseInterface {
        $error = new SyncError(
            $message,
            HttpStatusCodes::BAD_REQUEST,
            ['validation_errors' => $validation_errors]
        );
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Crea una respuesta de error de API
     * 
     * @param string $message Mensaje de error de API
     * @param int $http_status Código de estado HTTP
     * @param array $api_data Datos de la API
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function apiError(
        string $message,
        int $http_status = HttpStatusCodes::BAD_GATEWAY,
        array $api_data = [],
        array $data = []
    ): SyncResponseInterface {
        $error = new SyncError(
            $message,
            $http_status,
            ['api_data' => $api_data, 'http_status' => $http_status]
        );
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Crea una respuesta de error de concurrencia
     * 
     * @param string $message Mensaje de error de concurrencia
     * @param array $lock_info Información del lock
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function concurrencyError(
        string $message,
        array $lock_info = [],
        array $data = []
    ): SyncResponseInterface {
        $error = new SyncError(
            $message,
            HttpStatusCodes::CONFLICT,
            ['lock_info' => $lock_info]
        );
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Crea una respuesta de error de timeout
     * 
     * @param string $message Mensaje de error de timeout
     * @param int $timeout_seconds Segundos de timeout
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function timeoutError(
        string $message,
        int $timeout_seconds = 30,
        array $data = []
    ): SyncResponseInterface {
        $error = new SyncError(
            $message,
            HttpStatusCodes::REQUEST_TIMEOUT,
            ['timeout_seconds' => $timeout_seconds]
        );
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Crea una respuesta de error de memoria
     * 
     * @param string $message Mensaje de error de memoria
     * @param int $memory_limit Límite de memoria
     * @param int $memory_used Memoria usada
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function memoryError(
        string $message,
        int $memory_limit = 0,
        int $memory_used = 0,
        array $data = []
    ): SyncResponseInterface {
        $error = new SyncError(
            $message,
            HttpStatusCodes::INSUFFICIENT_STORAGE,
            [
                'memory_limit' => $memory_limit,
                'memory_used' => $memory_used,
                'memory_usage_percent' => $memory_limit > 0 ? ($memory_used / $memory_limit) * 100 : 0
            ]
        );
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Crea una respuesta de error de red
     * 
     * @param string $message Mensaje de error de red
     * @param string $endpoint Endpoint que falló
     * @param array $network_data Datos de la red
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function networkError(
        string $message,
        string $endpoint = '',
        array $network_data = [],
        array $data = []
    ): SyncResponseInterface {
        $error = new SyncError(
            $message,
            HttpStatusCodes::BAD_GATEWAY,
            [
                'endpoint' => $endpoint,
                'network_data' => $network_data
            ]
        );
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Crea una respuesta de error reintentable
     * 
     * @param string $message Mensaje de error
     * @param int $code Código de error
     * @param int $retry_delay Segundos de retraso para reintento
     * @param array $context Contexto del error
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function retryableError(
        string $message,
        int $code = HttpStatusCodes::SERVICE_UNAVAILABLE,
        int $retry_delay = 30,
        array $context = [],
        array $data = []
    ): SyncResponseInterface {
        $error = new SyncError($message, $code, $context, true, $retry_delay);
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Convierte una excepción genérica a SyncResponse
     * 
     * @param Throwable $exception Excepción capturada
     * @param array $data Datos adicionales
     * @param array $metadata Metadatos adicionales
     * @return SyncResponseInterface
     */
    public static function fromException(
        Throwable $exception,
        array $data = [],
        array $metadata = []
    ): SyncResponseInterface {
        return SyncResponse::fromException($exception, $data, $metadata);
    }

    /**
     * Convierte un WP_Error a SyncResponse
     * 
     * @param \WP_Error $wp_error Error de WordPress
     * @param array $data Datos adicionales
     * @return SyncResponseInterface
     */
    public static function fromWpError(\WP_Error $wp_error, array $data = []): SyncResponseInterface
    {
        $error = new SyncError(
            $wp_error->get_error_message(),
            (int) $wp_error->get_error_code(),
            $wp_error->get_error_data() ?? []
        );
        
        return SyncResponse::error($error, $data);
    }

    /**
     * Crea una respuesta de sincronización en progreso
     * 
     * @param string $operation_id ID de la operación
     * @param int $total_items Total de elementos
     * @param int $total_batches Total de lotes
     * @param array $metadata Metadatos adicionales
     * @return SyncResponseInterface
     */
    public static function syncInProgress(
        string $operation_id,
        int $total_items,
        int $total_batches,
        array $metadata = []
    ): SyncResponseInterface {
        return SyncResponse::success([
            'operation_id' => $operation_id,
            'total_items' => $total_items,
            'total_batches' => $total_batches,
            'in_progress' => true,
            'status' => 'in_progress'
        ], 'Sincronización iniciada correctamente', HttpStatusCodes::ACCEPTED, $metadata);
    }

    /**
     * Crea una respuesta de sincronización completada
     * 
     * @param int $total_processed Total de elementos procesados
     * @param int $total_batches Total de lotes procesados
     * @param array $metadata Metadatos adicionales
     * @return SyncResponseInterface
     */
    public static function syncCompleted(
        int $total_processed,
        int $total_batches,
        array $metadata = []
    ): SyncResponseInterface {
        return SyncResponse::success([
            'total_processed' => $total_processed,
            'total_batches' => $total_batches,
            'completed' => true,
            'status' => 'completed'
        ], "Sincronización completada: $total_processed elementos procesados en $total_batches lotes",
        HttpStatusCodes::OK, $metadata);
    }

    /**
     * Crea una respuesta de sincronización cancelada
     * 
     * @param string $reason Razón de la cancelación
     * @param array $metadata Metadatos adicionales
     * @return SyncResponseInterface
     */
    public static function syncCancelled(
        string $reason = 'Cancelada por el usuario',
        array $metadata = []
    ): SyncResponseInterface {
        return SyncResponse::success([
            'cancelled' => true,
            'status' => 'cancelled',
            'reason' => $reason
        ], 'Sincronización cancelada', HttpStatusCodes::OK, $metadata);
    }
}
