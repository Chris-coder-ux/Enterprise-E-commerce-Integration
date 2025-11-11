<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Responses;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;
use WP_Error;
use WP_REST_Response;

/**
 * Interfaz unificada para respuestas del sistema de sincronización
 * 
 * Esta interfaz proporciona una API consistente para manejar respuestas
 * exitosas y de error en todo el sistema, independiente de WordPress.
 * 
 * @package MiIntegracionApi\ErrorHandling\Responses
 * @since 1.0.0
 */
interface SyncResponseInterface
{
    /**
     * Indica si la operación fue exitosa
     * 
     * @return bool True si fue exitosa, false si hubo error
     */
    public function isSuccess(): bool;

    /**
     * Obtiene los datos de la respuesta
     * 
     * @return array Datos de la respuesta
     */
    public function getData(): array;

    /**
     * Obtiene el error asociado a la respuesta
     * 
     * @return SyncError|null Error si existe, null si fue exitosa
     */
    public function getError(): ?SyncError;

    /**
     * Obtiene el código de error
     * 
     * @return int|null Código de error si existe, null si fue exitosa
     */
    public function getErrorCode(): ?int;

    /**
     * Obtiene el código de error (alias de getErrorCode para compatibilidad)
     * 
     * @return int|null Código de error si existe, null si fue exitosa
     */
    public function getCode(): ?int;

    /**
     * Obtiene el código de estado HTTP
     * 
     * @return int Código de estado HTTP
     */
    public function getHttpStatus(): int;

    /**
     * Obtiene el mensaje de la respuesta
     * 
     * @return string Mensaje de éxito o error
     */
    public function getMessage(): string;

    /**
     * Convierte la respuesta a array
     * 
     * @return array Representación en array de la respuesta
     */
    public function toArray(): array;

    /**
     * Convierte la respuesta a JSON
     * 
     * @return string Representación JSON de la respuesta
     */
    public function toJson(): string;

    /**
     * Convierte la respuesta a WP_Error (solo para compatibilidad con WordPress)
     * 
     * @return WP_Error|null WP_Error si hay error, null si fue exitosa
     */
    public function toWpError(): ?WP_Error;

    /**
     * Convierte la respuesta a WP_REST_Response (solo para compatibilidad con WordPress)
     * 
     * @return WP_REST_Response|null WP_REST_Response, null si no está disponible
     */
    public function toWpRestResponse(): ?WP_REST_Response;

    /**
     * Obtiene metadatos adicionales de la respuesta
     * 
     * @return array Metadatos adicionales
     */
    public function getMetadata(): array;

    /**
     * Indica si la respuesta es reintentable
     * 
     * @return bool True si es reintentable, false en caso contrario
     */
    public function isRetryable(): bool;

    /**
     * Obtiene el retraso sugerido para reintentos
     * 
     * @return int Retraso en segundos para reintentos
     */
    public function getRetryDelay(): int;
}
