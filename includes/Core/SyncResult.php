<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

/**
 * Resultado de una operación de sincronización
 *
 * Encapsula el resultado de procesar un lote de elementos
 * con información detallada sobre éxitos, errores y métricas.
 *
 * @package MiIntegracionApi\Core
 * @since 1.5.0
 */
class SyncResult
{
    /**
     * @param int $created Elementos creados
     * @param int $updated Elementos actualizados
     * @param int $skipped Elementos omitidos (sin cambios)
     * @param int $errors Elementos con errores
     * @param array $error_details Detalles de errores específicos
     * @param float $duration Duración del procesamiento en segundos
     * @param array $metrics Métricas adicionales específicas
     * @param array $context Contexto adicional del procesamiento
     */
    public function __construct(
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $skipped = 0,
        public readonly int $errors = 0,
        public readonly array $error_details = [],
        public readonly float $duration = 0.0,
        public readonly array $metrics = [],
        public readonly array $context = []
    ) {}

    /**
     * Obtiene el total de elementos procesados
     */
    public function get_total_processed(): int
    {
        return $this->created + $this->updated + $this->skipped;
    }

    /**
     * Obtiene el total de elementos exitosos
     */
    public function get_successful(): int
    {
        return $this->created + $this->updated + $this->skipped;
    }

    /**
     * Verifica si la operación fue completamente exitosa
     */
    public function is_success(): bool
    {
        return $this->errors === 0;
    }

    /**
     * Verifica si la operación fue parcialmente exitosa
     */
    public function is_partial_success(): bool
    {
        return $this->get_successful() > 0 && $this->errors > 0;
    }

    /**
     * Obtiene la tasa de éxito como porcentaje
     */
    public function get_success_rate(): float
    {
        $total = $this->get_total_processed() + $this->errors;
        if ($total === 0) {
            return 0.0;
        }
        return ($this->get_successful() / $total) * 100.0;
    }

    /**
     * Convierte el resultado a array para serialización
     */
    public function to_array(): array
    {
        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
            'error_details' => $this->error_details,
            'duration' => $this->duration,
            'metrics' => $this->metrics,
            'context' => $this->context,
            'total_processed' => $this->get_total_processed(),
            'successful' => $this->get_successful(),
            'success_rate' => $this->get_success_rate(),
            'is_success' => $this->is_success(),
            'is_partial_success' => $this->is_partial_success()
        ];
    }

    /**
     * Crea un resultado combinando múltiples SyncResults
     */
    public static function combine(array $results): self
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = 0;
        $error_details = [];
        $duration = 0.0;
        $metrics = [];
        $context = [];

        foreach ($results as $result) {
            if (!$result instanceof self) {
                continue;
            }

            $created += $result->created;
            $updated += $result->updated;
            $skipped += $result->skipped;
            $errors += $result->errors;
            $error_details = array_merge($error_details, $result->error_details);
            $duration += $result->duration;
            $metrics = array_merge_recursive($metrics, $result->metrics);
            $context = array_merge($context, $result->context);
        }

        return new self(
            $created,
            $updated,
            $skipped,
            $errors,
            $error_details,
            $duration,
            $metrics,
            $context
        );
    }

    /**
     * Crea un resultado de error
     */
    public static function error(string $message, array $context = []): self
    {
        return new self(
            errors: 1,
            error_details: [$message],
            context: $context
        );
    }

    /**
     * Crea un resultado exitoso simple
     */
    public static function success(int $created = 0, int $updated = 0, int $skipped = 0): self
    {
        return new self($created, $updated, $skipped);
    }
}
