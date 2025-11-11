<?php

declare(strict_types=1);

namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory;
use MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes;

/**
 * Validador de seguridad centralizado para eliminar código duplicado
 *
 * Esta clase implementa el principio DRY (Don't Repeat Yourself) centralizando
 * todas las validaciones de seguridad comunes en el plugin.
 *
 * @package MiIntegracionApi
 * @since 1.4.2
 */
class SecurityValidator
{
    /**
     * Verifica el nonce de seguridad para una acción específica
     *
     * @param string $nonce     El nonce a verificar
     * @param string $action    La acción asociada al nonce
     * @param string $source    Fuente del nonce ('POST', 'GET', 'REQUEST')
     * @return bool             True si el nonce es válido, false en caso contrario
     */
    public static function verifyNonce(string $nonce, string $action, string $source = 'POST'): bool
    {
        if (empty($nonce)) {
            error_log("SecurityValidator: Nonce vacío para acción: $action");
            return false;
        }

        $result = wp_verify_nonce($nonce, $action);
        if ($result === false) {
            error_log("SecurityValidator: Nonce inválido. Nonce: $nonce, Acción: $action");
        }
        
        return $result !== false;
    }

    /**
     * Verifica si el usuario actual tiene permisos de administrador
     *
     * @param string $capability Capacidad específica a verificar (por defecto 'manage_options')
     * @return bool              True si el usuario tiene permisos, false en caso contrario
     */
    public static function hasAdminCapability(string $capability = 'manage_options'): bool
    {
        return current_user_can($capability);
    }

    /**
     * Valida y sanitiza un parámetro GET
     *
     * @param string $param     Nombre del parámetro
     * @param string $type      Tipo de validación ('int', 'text', 'email', 'url')
     * @param mixed  $default   Valor por defecto si el parámetro no existe
     * @return mixed            Valor validado y sanitizado
     */
    public static function validateGetParam(string $param, string $type = 'text', $default = null)
    {
        if (!isset($_GET[$param])) {
            return $default;
        }

        $value = $_GET[$param];

        switch ($type) {
            case 'int':
                return absint($value);
            case 'email':
                return sanitize_email($value);
            case 'url':
                return esc_url_raw($value);
            case 'text':
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Valida y sanitiza un parámetro POST
     *
     * @param string $param     Nombre del parámetro
     * @param string $type      Tipo de validación ('int', 'text', 'email', 'url')
     * @param mixed  $default   Valor por defecto si el parámetro no existe
     * @return mixed            Valor validado y sanitizado
     */
    public static function validatePostParam(string $param, string $type = 'text', $default = null)
    {
        if (!isset($_POST[$param])) {
            return $default;
        }

        $value = $_POST[$param];

        switch ($type) {
            case 'int':
                return absint($value);
            case 'email':
                return sanitize_email($value);
            case 'url':
                return esc_url_raw($value);
            case 'text':
            default:
                return sanitize_text_field($value);
        }
    }

    /**
     * Valida un ID de entidad (entero positivo)
     *
     * @param mixed $id         ID a validar
     * @param int   $min        Valor mínimo permitido (por defecto 1)
     * @return int|null         ID validado o null si es inválido
     */
    public static function validateEntityId($id, int $min = 1): ?int
    {
        $validated_id = absint($id);
        
        if ($validated_id < $min) {
            return null;
        }

        return $validated_id;
    }

    /**
     * Valida un rango de paginación
     *
     * @param int $page         Página solicitada
     * @param int $per_page     Elementos por página
     * @param int $max_pages    Máximo número de páginas permitido
     * @return array            Array con página y offset validados
     */
    public static function validatePaginationRange(int $page, int $per_page, int $max_pages = 1000): array
    {
        // Validar página
        $page = max(1, $page);
        
        // Validar límite máximo de páginas
        if ($page > $max_pages) {
            $page = $max_pages;
        }

        // Calcular offset de forma segura
        $offset = ($page - 1) * $per_page;
        
        // Validar que el offset no sea negativo
        if ($offset < 0) {
            $offset = 0;
            $page = 1;
        }

        return [
            'page' => $page,
            'offset' => $offset,
            'per_page' => $per_page
        ];
    }

    /**
     * Valida un límite de elementos con rango seguro
     *
     * @param int $limit        Límite solicitado
     * @param int $default      Límite por defecto
     * @param int $max          Límite máximo permitido
     * @param int $min          Límite mínimo permitido
     * @return int              Límite validado
     */
    public static function validateLimit(int $limit, int $default = 10, int $max = 100, int $min = 1): int
    {
        $validated_limit = absint($limit);
        
        // Validar rango mínimo
        if ($validated_limit < $min) {
            $validated_limit = $default;
        }
        
        // Validar rango máximo
        if ($validated_limit > $max) {
            $validated_limit = $max;
        }

        return $validated_limit;
    }

    /**
     * Verifica la seguridad completa de una solicitud AJAX (MÉTODO PRINCIPAL)
     *
     * @param string $nonce     Nonce a verificar
     * @param string $action    Acción del nonce
     * @param string $capability Capacidad requerida
     * @return SyncResponseInterface Respuesta unificada del sistema
     */
    public static function validateAjaxRequestSync(string $nonce, string $action, string $capability = 'manage_options'): SyncResponseInterface
    {
        // Verificar nonce
        if (!self::verifyNonce($nonce, $action)) {
            return ResponseFactory::error(
                __('Token de seguridad inválido o expirado', 'mi-integracion-api'),
                HttpStatusCodes::FORBIDDEN,
                [
                    'endpoint' => 'SecurityValidator::validateAjaxRequestSync',
                    'error_code' => 'invalid_nonce',
                    'action' => $action,
                    'nonce' => $nonce,
                    'timestamp' => time()
                ]
            );
        }

        // Verificar permisos
        if (!self::hasAdminCapability($capability)) {
            return ResponseFactory::error(
                __('Permisos insuficientes para realizar esta acción', 'mi-integracion-api'),
                HttpStatusCodes::FORBIDDEN,
                [
                    'endpoint' => 'SecurityValidator::validateAjaxRequestSync',
                    'error_code' => 'insufficient_permissions',
                    'capability' => $capability,
                    'user_id' => get_current_user_id(),
                    'timestamp' => time()
                ]
            );
        }

        return ResponseFactory::success(
            ['validated' => true],
            __('Validación de seguridad exitosa', 'mi-integracion-api'),
            [
                'endpoint' => 'SecurityValidator::validateAjaxRequestSync',
                'action' => $action,
                'capability' => $capability,
                'timestamp' => time()
            ]
        );
    }

    /**
     * Verifica la seguridad completa de una solicitud AJAX (MÉTODO LEGACY)
     *
     * @param string $nonce     Nonce a verificar
     * @param string $action    Acción del nonce
     * @param string $capability Capacidad requerida
     * @return array            Array con resultado de la validación
     * @deprecated Usar validateAjaxRequestSync() en su lugar
     */
    public static function validateAjaxRequest(string $nonce, string $action, string $capability = 'manage_options'): array
    {
        // Verificar nonce
        if (!self::verifyNonce($nonce, $action)) {
            return [
                'valid' => false,
                'error' => 'invalid_nonce',
                'message' => __('Token de seguridad inválido o expirado', 'mi-integracion-api'),
                'code' => 403
            ];
        }

        // Verificar permisos
        if (!self::hasAdminCapability($capability)) {
            return [
                'valid' => false,
                'error' => 'insufficient_permissions',
                'message' => __('Permisos insuficientes para realizar esta acción', 'mi-integracion-api'),
                'code' => 403
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'message' => null,
            'code' => 200
        ];
    }

    /**
     * Envía respuesta de error AJAX estandarizada
     *
     * @param string $message   Mensaje de error
     * @param int    $code      Código de estado HTTP
     * @param string $error_type Tipo de error para debugging
     * @param array  $data      Datos adicionales del error
     */
    public static function sendAjaxError(string $message, int $code = 400, string $error_type = 'general', array $data = []): void
    {
        // Validar código HTTP
        if ($code < 400 || $code > 599) {
            $code = 400;
        }
        
        // Sanitizar entrada
        $message = sanitize_text_field($message);
        $error_type = sanitize_text_field($error_type);
        
        $error_response = [
            'success' => false,
            'message' => $message,
            'error_type' => $error_type,
            'code' => $code
        ];

        if (!empty($data)) {
            $error_response['data'] = $data;
        }

        wp_send_json_error($error_response, $code);
    }

    /**
     * Envía respuesta de éxito AJAX estandarizada
     *
     * @param mixed $data       Datos de la respuesta (array, string, etc.)
     * @param string $message   Mensaje de éxito
     * @param int    $code      Código de estado HTTP
     */
    public static function sendAjaxSuccess($data, string $message = '', int $code = 200): void
    {
        // Validar código HTTP
        if ($code < 200 || $code > 299) {
            $code = 200;
        }
        
        // Si los datos no son array, envolverlos
        if (!is_array($data)) {
            $data = ['data' => $data];
        }
        
        // Añadir mensaje si se proporciona
        if (!empty($message)) {
            $data['message'] = sanitize_text_field($message);
        }
        
        wp_send_json_success($data, $code);
    }

    /**
     * Envía respuesta AJAX desde SyncResponseInterface (MÉTODO PRINCIPAL)
     *
     * @param SyncResponseInterface $response Respuesta del sistema unificado
     */
    public static function sendSyncResponse(SyncResponseInterface $response): void
    {
        if ($response->isSuccess()) {
            self::sendAjaxSuccess($response->getData(), $response->getMessage(), $response->getHttpStatus());
        } else {
            $error = $response->getError();
            $errorType = $error ? (string) $error->getCode() : 'sync_error';
            self::sendAjaxError(
                $response->getMessage(),
                $response->getHttpStatus(),
                $errorType,
                $response->getMetadata()
            );
        }
    }

    /**
     * Envía respuesta de error AJAX desde WP_Error (ADAPTADOR LEGACY)
     *
     * Este método convierte WP_Error a SyncResponseInterface internamente,
     * eliminando la dependencia directa de WordPress en el flujo principal.
     *
     * @param \WP_Error $wp_error Error de WordPress
     * @param int       $default_code Código HTTP por defecto si no se puede determinar
     * @deprecated Usar sendSyncResponse() directamente en su lugar
     */
    public static function sendWpError(\WP_Error $wp_error, int $default_code = 400): void
    {
        // Convertir WP_Error a SyncResponseInterface internamente
        $message = $wp_error->get_error_message();
        $code = $wp_error->get_error_code();
        $data = $wp_error->get_error_data();
        
        // Determinar código HTTP
        $http_code = $default_code;
        if (is_numeric($code)) {
            $http_code = (int) $code;
        } elseif (is_string($code)) {
            // Mapear códigos de error comunes a códigos HTTP
            $code_mapping = [
                'invalid_nonce' => HttpStatusCodes::FORBIDDEN,
                'permission_denied' => HttpStatusCodes::FORBIDDEN,
                'insufficient_permissions' => HttpStatusCodes::FORBIDDEN,
                'invalid_parameters' => HttpStatusCodes::BAD_REQUEST,
                'not_found' => HttpStatusCodes::NOT_FOUND,
                'server_error' => HttpStatusCodes::INTERNAL_SERVER_ERROR,
                'database_error' => HttpStatusCodes::INTERNAL_SERVER_ERROR,
                'security_error' => HttpStatusCodes::INTERNAL_SERVER_ERROR,
                'security_unavailable' => HttpStatusCodes::INTERNAL_SERVER_ERROR
            ];
            $http_code = $code_mapping[$code] ?? $default_code;
        }
        
        // Crear SyncResponseInterface usando ResponseFactory
        $sync_response = ResponseFactory::error(
            $message,
            $http_code,
            $data ?? [],
            [
                'error_code' => $code,
                'source' => 'legacy_wp_error',
                'endpoint' => 'SecurityValidator::sendWpError',
                'timestamp' => time()
            ]
        );
        
        // Usar el sistema unificado (sin dependencia de WP_Error)
        self::sendSyncResponse($sync_response);
    }

    /**
     * Valida y sanitiza un array de parámetros
     *
     * @param array  $params    Array de parámetros a validar
     * @param array  $rules     Reglas de validación para cada parámetro
     * @return array            Array con parámetros validados
     */
    public static function validateParams(array $params, array $rules): array
    {
        $validated = [];

        foreach ($rules as $param => $rule) {
            $type = $rule['type'] ?? 'text';
            $required = $rule['required'] ?? false;
            $default = $rule['default'] ?? null;
            $min = $rule['min'] ?? null;
            $max = $rule['max'] ?? null;

            // Verificar si el parámetro es requerido
            if ($required && !isset($params[$param])) {
                throw new \InvalidArgumentException("El parámetro '{$param}' es requerido");
            }

            // Si no existe y no es requerido, usar valor por defecto
            if (!isset($params[$param])) {
                $validated[$param] = $default;
                continue;
            }

            $value = $params[$param];

            // Aplicar validación según el tipo
            switch ($type) {
                case 'int':
                    $value = absint($value);
                    if ($min !== null && $value < $min) {
                        throw new \InvalidArgumentException("El parámetro '{$param}' debe ser mayor o igual a {$min}");
                    }
                    if ($max !== null && $value > $max) {
                        throw new \InvalidArgumentException("El parámetro '{$param}' debe ser menor o igual a {$max}");
                    }
                    break;

                case 'email':
                    $value = sanitize_email($value);
                    if (!is_email($value)) {
                        throw new \InvalidArgumentException("El parámetro '{$param}' debe ser un email válido");
                    }
                    break;

                case 'url':
                    $value = esc_url_raw($value);
                    break;

                case 'text':
                default:
                    $value = sanitize_text_field($value);
                    if ($min !== null && strlen($value) < $min) {
                        throw new \InvalidArgumentException("El parámetro '{$param}' debe tener al menos {$min} caracteres");
                    }
                    if ($max !== null && strlen($value) > $max) {
                        throw new \InvalidArgumentException("El parámetro '{$param}' debe tener máximo {$max} caracteres");
                    }
                    break;
            }

            $validated[$param] = $value;
        }

        return $validated;
    }
}
