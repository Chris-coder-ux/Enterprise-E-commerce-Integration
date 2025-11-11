<?php declare(strict_types=1);
/**
 * Manejador de respuestas de la API
 *
 * @package MiIntegracionApi\Endpoints
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory;
use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes;

class API_Response_Handler {
    /**
     * Formatea una respuesta exitosa
     *
     * @param mixed|null $data Datos a incluir en la respuesta
     * @param array $extra Datos adicionales para incluir
     * @return SyncResponseInterface
     */
    public static function success(mixed $data = null, array $extra = []): SyncResponseInterface
    {
        $responseData = is_array($data) ? $data : ['data' => $data];
        
        if (!empty($extra)) {
            $responseData = array_merge($responseData, $extra);
        }

        return ResponseFactory::success(
            $responseData,
            'Operación completada exitosamente',
            []
        );
    }

    /**
     * Formatea una respuesta de error
     *
     * @param string $code Código de error
     * @param string $message Mensaje de error
     * @param array $data Datos adicionales del error
     * @param int $status Código de estado HTTP
     * @return SyncResponseInterface
     */
    public static function error(string $code, string $message, array $data = [], int $status = 400): SyncResponseInterface
    {
        return ResponseFactory::error(
            $message,
            $status,
            array_merge(
                ['error_code' => $code],
                $data
            )
        );
    }

    /**
     * Formatea una respuesta de error de validación
     *
     * @param array $errors Errores de validación
     * @return SyncResponseInterface
     */
    public static function validation_error(array $errors): SyncResponseInterface
    {
        return ResponseFactory::error(
            __('Error de validación', 'mi-integracion-api'),
            422, // UNPROCESSABLE_ENTITY
            ['errors' => $errors],
            ['error_code' => 'validation_error']
        );
    }

    /**
     * Formatea una respuesta de error de autenticación
     *
     * @param string $message Mensaje de error
     * @return SyncResponseInterface
     */
    public static function auth_error(string $message = ''): SyncResponseInterface
    {
        return ResponseFactory::error(
            $message ?: __('Error de autenticación', 'mi-integracion-api'),
            HttpStatusCodes::UNAUTHORIZED,
            [],
            ['error_code' => 'auth_error']
        );
    }

    /**
     * Formatea una respuesta de error de permisos
     *
     * @param string $message Mensaje de error
     * @return SyncResponseInterface
     */
    public static function permission_error(string $message = ''): SyncResponseInterface
    {
        return ResponseFactory::error(
            $message ?: __('No tienes permisos para realizar esta acción', 'mi-integracion-api'),
            HttpStatusCodes::FORBIDDEN,
            [],
            ['error_code' => 'permission_error']
        );
    }
}