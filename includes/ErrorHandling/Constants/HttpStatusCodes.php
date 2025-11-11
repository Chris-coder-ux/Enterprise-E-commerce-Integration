<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Constants;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use ReflectionClass;

/**
 * Constantes centralizadas para códigos de estado HTTP
 * 
 * Esta clase centraliza todos los códigos de estado HTTP utilizados en el sistema,
 * proporcionando una referencia única y consistente para las respuestas HTTP.
 * 
 * @package MiIntegracionApi\ErrorHandling\Constants
 * @since 1.0.0
 */
class HttpStatusCodes
{
    // ===================================================================
    // CÓDIGOS DE ÉXITO (2xx)
    // ===================================================================

    /**
     * OK - Solicitud exitosa
     */
    public const OK = 200;

    /**
     * Created - Recurso creado exitosamente
     */
    public const CREATED = 201;

    /**
     * Accepted - Solicitud aceptada para procesamiento
     */
    public const ACCEPTED = 202;

    /**
     * No Content - Solicitud exitosa sin contenido de respuesta
     */
    public const NO_CONTENT = 204;

    // ===================================================================
    // CÓDIGOS DE REDIRECCIÓN (3xx)
    // ===================================================================

    /**
     * Moved Permanently - Recurso movido permanentemente
     */
    public const MOVED_PERMANENTLY = 301;

    /**
     * Found - Recurso encontrado temporalmente
     */
    public const FOUND = 302;

    /**
     * Not Modified - Recurso no modificado
     */
    public const NOT_MODIFIED = 304;

    // ===================================================================
    // CÓDIGOS DE ERROR DEL CLIENTE (4xx)
    // ===================================================================

    /**
     * Bad Request - Solicitud malformada
     */
    public const BAD_REQUEST = 400;

    /**
     * Unauthorized - No autenticado
     */
    public const UNAUTHORIZED = 401;

    /**
     * Forbidden - No autorizado
     */
    public const FORBIDDEN = 403;

    /**
     * Not Found - Recurso no encontrado
     */
    public const NOT_FOUND = 404;

    /**
     * Method Not Allowed - Método HTTP no permitido
     */
    public const METHOD_NOT_ALLOWED = 405;

    /**
     * Not Acceptable - Formato de respuesta no aceptable
     */
    public const NOT_ACCEPTABLE = 406;

    /**
     * Request Timeout - Timeout de solicitud
     */
    public const REQUEST_TIMEOUT = 408;

    /**
     * Conflict - Conflicto con el estado actual
     */
    public const CONFLICT = 409;

    /**
     * Gone - Recurso ya no disponible
     */
    public const GONE = 410;

    /**
     * Length Required - Longitud de contenido requerida
     */
    public const LENGTH_REQUIRED = 411;

    /**
     * Precondition Failed - Precondición fallida
     */
    public const PRECONDITION_FAILED = 412;

    /**
     * Payload Too Large - Carga útil demasiado grande
     */
    public const PAYLOAD_TOO_LARGE = 413;

    /**
     * URI Too Long - URI demasiado largo
     */
    public const URI_TOO_LONG = 414;

    /**
     * Unsupported Media Type - Tipo de medio no soportado
     */
    public const UNSUPPORTED_MEDIA_TYPE = 415;

    /**
     * Range Not Satisfiable - Rango no satisfacible
     */
    public const RANGE_NOT_SATISFIABLE = 416;

    /**
     * Expectation Failed - Expectativa fallida
     */
    public const EXPECTATION_FAILED = 417;

    /**
     * Too Many Requests - Demasiadas solicitudes
     */
    public const TOO_MANY_REQUESTS = 429;

    /**
     * Request Header Fields Too Large - Campos de cabecera demasiado grandes
     */
    public const REQUEST_HEADER_FIELDS_TOO_LARGE = 431;

    /**
     * Unavailable For Legal Reasons - No disponible por razones legales
     */
    public const UNAVAILABLE_FOR_LEGAL_REASONS = 451;

    // ===================================================================
    // CÓDIGOS DE ERROR DEL SERVIDOR (5xx)
    // ===================================================================

    /**
     * Internal Server Error - Error interno del servidor
     */
    public const INTERNAL_SERVER_ERROR = 500;

    /**
     * Not Implemented - Funcionalidad no implementada
     */
    public const NOT_IMPLEMENTED = 501;

    /**
     * Bad Gateway - Gateway incorrecto
     */
    public const BAD_GATEWAY = 502;

    /**
     * Service Unavailable - Servicio no disponible
     */
    public const SERVICE_UNAVAILABLE = 503;

    /**
     * Gateway Timeout - Timeout del gateway
     */
    public const GATEWAY_TIMEOUT = 504;

    /**
     * HTTP Version Not Supported - Versión HTTP no soportada
     */
    public const HTTP_VERSION_NOT_SUPPORTED = 505;

    /**
     * Variant Also Negotiates - Variante también negocia
     */
    public const VARIANT_ALSO_NEGOTIATES = 506;

    /**
     * Insufficient Storage - Almacenamiento insuficiente
     */
    public const INSUFFICIENT_STORAGE = 507;

    /**
     * Loop Detected - Bucle detectado
     */
    public const LOOP_DETECTED = 508;

    /**
     * Not Extended - No extendido
     */
    public const NOT_EXTENDED = 510;

    /**
     * Network Authentication Required - Autenticación de red requerida
     */
    public const NETWORK_AUTHENTICATION_REQUIRED = 511;

    // ===================================================================
    // MÉTODOS DE UTILIDAD
    // ===================================================================

    /**
     * Obtiene todos los códigos de estado HTTP
     * 
     * @return array Array con todos los códigos de estado
     */
    public static function getAllCodes(): array
    {
        $reflection = new ReflectionClass(self::class);
        return array_values($reflection->getConstants());
    }

    /**
     * Obtiene códigos de estado por categoría
     * 
     * @param string $category Categoría (success, client_error, server_error, etc.)
     * @return array Array con códigos de la categoría
     */
    public static function getCodesByCategory(string $category): array
    {
        $reflection = new ReflectionClass(self::class);
        $constants = array_values($reflection->getConstants());
        
        $codes = [];
        
        foreach ($constants as $value) {
            switch ($category) {
                case 'success':
                    if ($value >= 200 && $value < 300) {
                        $codes[] = $value;
                    }
                    break;
                case 'client_error':
                    if ($value >= 400 && $value < 500) {
                        $codes[] = $value;
                    }
                    break;
                case 'server_error':
                    if ($value >= 500 && $value < 600) {
                        $codes[] = $value;
                    }
                    break;
                case 'redirection':
                    if ($value >= 300 && $value < 400) {
                        $codes[] = $value;
                    }
                    break;
            }
        }
        
        return $codes;
    }

    /**
     * Verifica si un código de estado es válido
     * 
     * @param int $code Código a verificar
     * @return bool True si es válido, false en caso contrario
     */
    public static function isValidCode(int $code): bool
    {
        return $code >= 100 && $code < 600;
    }

    /**
     * Obtiene la descripción de un código de estado HTTP
     * 
     * @param int $code Código de estado
     * @return string Descripción del código
     */
    public static function getDescription(int $code): string
    {
        $descriptions = [
            self::OK => 'OK - Solicitud exitosa',
            self::CREATED => 'Created - Recurso creado exitosamente',
            self::ACCEPTED => 'Accepted - Solicitud aceptada para procesamiento',
            self::NO_CONTENT => 'No Content - Solicitud exitosa sin contenido de respuesta',
            self::MOVED_PERMANENTLY => 'Moved Permanently - Recurso movido permanentemente',
            self::FOUND => 'Found - Recurso encontrado temporalmente',
            self::NOT_MODIFIED => 'Not Modified - Recurso no modificado',
            self::BAD_REQUEST => 'Bad Request - Solicitud malformada',
            self::UNAUTHORIZED => 'Unauthorized - No autenticado',
            self::FORBIDDEN => 'Forbidden - No autorizado',
            self::NOT_FOUND => 'Not Found - Recurso no encontrado',
            self::METHOD_NOT_ALLOWED => 'Method Not Allowed - Método HTTP no permitido',
            self::NOT_ACCEPTABLE => 'Not Acceptable - Formato de respuesta no aceptable',
            self::REQUEST_TIMEOUT => 'Request Timeout - Timeout de solicitud',
            self::CONFLICT => 'Conflict - Conflicto con el estado actual',
            self::GONE => 'Gone - Recurso ya no disponible',
            self::LENGTH_REQUIRED => 'Length Required - Longitud de contenido requerida',
            self::PRECONDITION_FAILED => 'Precondition Failed - Precondición fallida',
            self::PAYLOAD_TOO_LARGE => 'Payload Too Large - Carga útil demasiado grande',
            self::URI_TOO_LONG => 'URI Too Long - URI demasiado largo',
            self::UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type - Tipo de medio no soportado',
            self::RANGE_NOT_SATISFIABLE => 'Range Not Satisfiable - Rango no satisfacible',
            self::EXPECTATION_FAILED => 'Expectation Failed - Expectativa fallida',
            self::TOO_MANY_REQUESTS => 'Too Many Requests - Demasiadas solicitudes',
            self::REQUEST_HEADER_FIELDS_TOO_LARGE => 'Request Header Fields Too Large - Campos de cabecera demasiado grandes',
            self::UNAVAILABLE_FOR_LEGAL_REASONS => 'Unavailable For Legal Reasons - No disponible por razones legales',
            self::INTERNAL_SERVER_ERROR => 'Internal Server Error - Error interno del servidor',
            self::NOT_IMPLEMENTED => 'Not Implemented - Funcionalidad no implementada',
            self::BAD_GATEWAY => 'Bad Gateway - Gateway incorrecto',
            self::SERVICE_UNAVAILABLE => 'Service Unavailable - Servicio no disponible',
            self::GATEWAY_TIMEOUT => 'Gateway Timeout - Timeout del gateway',
            self::HTTP_VERSION_NOT_SUPPORTED => 'HTTP Version Not Supported - Versión HTTP no soportada',
            self::VARIANT_ALSO_NEGOTIATES => 'Variant Also Negotiates - Variante también negocia',
            self::INSUFFICIENT_STORAGE => 'Insufficient Storage - Almacenamiento insuficiente',
            self::LOOP_DETECTED => 'Loop Detected - Bucle detectado',
            self::NOT_EXTENDED => 'Not Extended - No extendido',
            self::NETWORK_AUTHENTICATION_REQUIRED => 'Network Authentication Required - Autenticación de red requerida',
        ];

        return $descriptions[$code] ?? "Código de estado HTTP $code";
    }

    /**
     * Obtiene la categoría de un código de estado
     * 
     * @param int $code Código de estado
     * @return string Categoría del código
     */
    public static function getCategory(int $code): string
    {
        if ($code >= 200 && $code < 300) {
            return 'success';
        } elseif ($code >= 300 && $code < 400) {
            return 'redirection';
        } elseif ($code >= 400 && $code < 500) {
            return 'client_error';
        } elseif ($code >= 500 && $code < 600) {
            return 'server_error';
        } else {
            return 'unknown';
        }
    }

    /**
     * Verifica si un código de estado indica éxito
     * 
     * @param int $code Código de estado
     * @return bool True si indica éxito, false en caso contrario
     */
    public static function isSuccess(int $code): bool
    {
        return $code >= 200 && $code < 300;
    }

    /**
     * Verifica si un código de estado indica error
     * 
     * @param int $code Código de estado
     * @return bool True si indica error, false en caso contrario
     */
    public static function isError(int $code): bool
    {
        return $code >= 400 && $code < 600;
    }

    /**
     * Verifica si un código de estado es reintentable
     * 
     * @param int $code Código de estado
     * @return bool True si es reintentable, false en caso contrario
     */
    public static function isRetryable(int $code): bool
    {
        $retryableCodes = [
            self::REQUEST_TIMEOUT,
            self::TOO_MANY_REQUESTS,
            self::INTERNAL_SERVER_ERROR,
            self::BAD_GATEWAY,
            self::SERVICE_UNAVAILABLE,
            self::GATEWAY_TIMEOUT,
        ];

        return in_array($code, $retryableCodes, true);
    }
}
