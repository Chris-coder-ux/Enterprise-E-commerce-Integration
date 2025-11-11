<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Responses;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;
use Throwable;
use WP_Error;
use WP_REST_Response;

/**
 * Implementación base para respuestas del sistema de sincronización
 * 
 * Esta clase implementa SyncResponseInterface proporcionando una API
 * consistente para manejar respuestas exitosas y de error en todo el sistema.
 * 
 * @package MiIntegracionApi\ErrorHandling\Responses
 * @since 1.0.0
 */
class SyncResponse implements SyncResponseInterface, \Countable
{
    private bool $success;
    private array $data;
    private ?SyncError $error;
    private int $httpStatus;
    private string $message;
    private array $metadata;

    /**
     * Constructor de la respuesta
     * 
     * @param bool $success Indica si la operación fue exitosa
     * @param array $data Datos de la respuesta
     * @param SyncError|null $error Error si existe
     * @param int $httpStatus Código de estado HTTP
     * @param string $message Mensaje de la respuesta
     * @param array $metadata Metadatos adicionales
     */
    public function __construct(
        bool $success,
        array $data = [],
        ?SyncError $error = null,
        int $httpStatus = 200,
        string $message = '',
        array $metadata = []
    ) {
        $this->success = $success;
        $this->data = $data;
        $this->error = $error;
        $this->httpStatus = $error ? $error->getCode() : $httpStatus;
        $this->message = $message ?: ($error ? $error->getMessage() : 'Operación completada exitosamente');
        $this->metadata = $metadata;
    }

    /**
     * Crea una respuesta exitosa
     * 
     * @param array $data Datos de la respuesta
     * @param string $message Mensaje de éxito
     * @param int $httpStatus Código de estado HTTP
     * @param array $metadata Metadatos adicionales
     * @return self
     */
    public static function success(
        array $data = [],
        string $message = 'Operación completada exitosamente',
        int $httpStatus = 200,
        array $metadata = []
    ): self {
        return new self(true, $data, null, $httpStatus, $message, $metadata);
    }

    /**
     * Crea una respuesta de error
     * 
     * @param SyncError $error Error de la operación
     * @param array $data Datos adicionales
     * @param array $metadata Metadatos adicionales
     * @return self
     */
    public static function error(
        SyncError $error,
        array $data = [],
        array $metadata = []
    ): self {
        return new self(false, $data, $error, $error->getCode(), $error->getMessage(), $metadata);
    }

    /**
     * Crea una respuesta de error desde una excepción genérica
     * 
     * @param Throwable $exception Excepción capturada
     * @param array $data Datos adicionales
     * @param array $metadata Metadatos adicionales
     * @return self
     */
    public static function fromException(
        Throwable $exception,
        array $data = [],
        array $metadata = []
    ): self {
        $syncError = new SyncError(
            $exception->getMessage(),
            $exception->getCode(),
            array_merge($metadata, [
                'exception_class' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ])
        );

        return new self(false, $data, $syncError, $exception->getCode(), $exception->getMessage(), $metadata);
    }

    /**
     * {@inheritdoc}
     */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * {@inheritdoc}
     */
    public function getData(): array
    {
        return $this->data;
    }

    /**
     * {@inheritdoc}
     */
    public function getError(): ?SyncError
    {
        return $this->error;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorCode(): ?int
    {
        return $this->error?->getCode();
    }

    /**
     * {@inheritdoc}
     */
    public function getCode(): ?int
    {
        return $this->getErrorCode();
    }

    /**
     * {@inheritdoc}
     */
    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * {@inheritdoc}
     */
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * {@inheritdoc}
     */
    public function toArray(): array
    {
        $response = [
            'success' => $this->success,
            'message' => $this->message,
            'data' => $this->data,
            'http_status' => $this->httpStatus,
            'metadata' => $this->metadata,
            'error_code' => $this->getErrorCode(),
            'retryable' => $this->isRetryable(),
            'retry_delay' => $this->getRetryDelay()
        ];

        if ($this->error) {
            $response['error'] = [
                'message' => $this->error->getMessage(),
                'code' => $this->error->getCode(),
                'context' => $this->error->getContext(),
                'retryable' => $this->error->isRetryable(),
                'retry_delay' => $this->error->getRetryDelay()
            ];
        }

        return $response;
    }

    /**
     * {@inheritdoc}
     */
    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * {@inheritdoc}
     */
    public function toWpError(): ?WP_Error
    {
        if (!$this->error) {
            return null;
        }

        return new WP_Error(
            'sync_error',
            $this->error->getMessage(),
            [
                'status' => $this->error->getCode(),
                'context' => $this->error->getContext(),
                'retryable' => $this->error->isRetryable(),
                'retry_delay' => $this->error->getRetryDelay()
            ]
        );
    }

    /**
     * {@inheritdoc}
     */
    public function toWpRestResponse(): ?WP_REST_Response
    {
        if (!class_exists('\WP_REST_Response')) {
            return null;
        }

        return new WP_REST_Response(
            $this->toArray(),
            $this->httpStatus
        );
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * {@inheritdoc}
     */
    public function isRetryable(): bool
    {
        return $this->error?->isRetryable() ?? false;
    }

    /**
     * {@inheritdoc}
     */
    public function getRetryDelay(): int
    {
        return $this->error?->getRetryDelay() ?? 0;
    }

    /**
     * Añade metadatos a la respuesta
     * 
     * @param string $key Clave del metadato
     * @param mixed $value Valor del metadato
     * @return self
     */
    public function addMetadata(string $key, mixed $value): self
    {
        $this->metadata[$key] = $value;
        return $this;
    }

    /**
     * Añade datos a la respuesta
     * 
     * @param string $key Clave del dato
     * @param mixed $value Valor del dato
     * @return self
     */
    public function addData(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Obtiene un valor específico de los datos
     * 
     * @param string $key Clave del dato (puede ser anidada con puntos, ej: 'user.name')
     * @param mixed|null $default Valor por defecto si no existe
     * @return mixed Valor del dato o valor por defecto
     */
    public function getDataValue(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->data;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }

    /**
     * Obtiene un valor específico de los metadatos
     * 
     * @param string $key Clave del metadato (puede ser anidada con puntos, ej: 'user.name')
     * @param mixed|null $default Valor por defecto si no existe
     * @return mixed Valor del metadato o valor por defecto
     */
    public function getMetadataValue(string $key, mixed $default = null): mixed
    {
        $keys = explode('.', $key);
        $value = $this->metadata;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !array_key_exists($k, $value)) {
                return $default;
            }
            $value = $value[$k];
        }
        
        return $value;
    }

    /**
     * Implementación de Countable para compatibilidad con WordPress
     * @return int Número de elementos en los datos de la respuesta
     */
    public function count(): int
    {
        return count($this->data);
    }
}
