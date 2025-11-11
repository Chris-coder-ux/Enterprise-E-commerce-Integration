<?php

declare(strict_types=1);

namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Helpers\Logger;

/**
 * Throttler adaptativo que ajusta el delay dinámicamente según errores consecutivos.
 *
 * Implementa un sistema de throttling inteligente que:
 * - Aumenta el delay progresivamente cuando hay errores consecutivos
 * - Reduce el delay gradualmente cuando hay éxito después de errores
 * - Limita el delay a un máximo configurable
 * - Proporciona logging opcional para monitoreo
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       2.0.0
 */
class AdaptiveThrottler
{
    /**
     * Multiplicador máximo para el delay cuando hay errores consecutivos.
     *
     * @var float
     */
    private const MAX_THROTTLE_MULTIPLIER = 5.0;

    /**
     * Incremento del multiplicador por cada error consecutivo.
     *
     * @var float
     */
    private const THROTTLE_INCREMENT_PER_ERROR = 0.5;

    /**
     * Delay máximo permitido en segundos.
     *
     * @var float
     */
    private const MAX_THROTTLE_DELAY_SECONDS = 5.0;

    /**
     * Factor de reducción del delay cuando hay éxito después de errores.
     *
     * @var float
     */
    private const THROTTLE_REDUCTION_FACTOR = 0.9;

    /**
     * Umbral de errores consecutivos para registrar warning de ajuste.
     *
     * @var int
     */
    private const LOG_THROTTLE_ADJUSTMENT_THRESHOLD = 3;

    /**
     * Umbral de errores consecutivos para mostrar sugerencia al usuario.
     *
     * @var int
     */
    private const MAX_CONSECUTIVE_ERRORS_THRESHOLD = 5;

    /**
     * Delay base configurado.
     *
     * @var float
     */
    private float $baseDelay;

    /**
     * Contador de errores consecutivos.
     *
     * @var int
     */
    private int $consecutiveErrors = 0;

    /**
     * Delay actual ajustado dinámicamente.
     *
     * @var float
     */
    private float $currentDelay;

    /**
     * Instancia del logger (opcional).
     *
     * @var Logger|null
     */
    private ?Logger $logger;

    /**
     * Constructor.
     *
     * @param   float       $baseDelay Delay base en segundos.
     * @param   Logger|null $logger    Instancia del logger para logging opcional.
     */
    public function __construct(float $baseDelay, ?Logger $logger = null)
    {
        $this->baseDelay = max(0, min(self::MAX_THROTTLE_DELAY_SECONDS, $baseDelay));
        $this->currentDelay = $this->baseDelay;
        $this->logger = $logger;
    }

    /**
     * Obtiene el delay actual.
     *
     * @return  float Delay en segundos.
     */
    public function getDelay(): float
    {
        return $this->currentDelay;
    }

    /**
     * Obtiene el delay base configurado.
     *
     * @return  float Delay base en segundos.
     */
    public function getBaseDelay(): float
    {
        return $this->baseDelay;
    }

    /**
     * Obtiene el número de errores consecutivos.
     *
     * @return  int Número de errores consecutivos.
     */
    public function getConsecutiveErrors(): int
    {
        return $this->consecutiveErrors;
    }

    /**
     * Maneja un error, aumentando el delay progresivamente.
     *
     * @return  void
     */
    public function onError(): void
    {
        $this->consecutiveErrors++;
        
        // Calcular multiplicador: 1.0 + (errores * incremento), limitado a máximo
        $multiplier = min(1.0 + ($this->consecutiveErrors * self::THROTTLE_INCREMENT_PER_ERROR), self::MAX_THROTTLE_MULTIPLIER);
        $new_delay = $this->baseDelay * $multiplier;
        
        // Limitar a máximo permitido
        $new_delay = min($new_delay, self::MAX_THROTTLE_DELAY_SECONDS);
        
        if ($new_delay > $this->currentDelay) {
            $this->currentDelay = $new_delay;
            
            // Logging opcional cuando se ajusta el delay
            if ($this->logger !== null && $this->consecutiveErrors >= self::LOG_THROTTLE_ADJUSTMENT_THRESHOLD) {
                $this->logger->warning('Delay de throttling aumentado automáticamente debido a errores consecutivos', [
                    'consecutive_errors' => $this->consecutiveErrors,
                    'original_delay_ms' => round($this->baseDelay * 1000, 2),
                    'adjusted_delay_ms' => round($this->currentDelay * 1000, 2),
                    'multiplier' => round($multiplier, 2),
                    'suggestion' => 'Si los errores persisten, considera aumentar el delay base mediante: update_option("mia_images_sync_throttle_delay", 0.05) o más',
                    'action' => 'throttle_delay_auto_adjusted'
                ]);
            }
        }
    }

    /**
     * Maneja un éxito, reduciendo el delay gradualmente si había errores previos.
     *
     * @return  void
     */
    public function onSuccess(): void
    {
        if ($this->consecutiveErrors > 0) {
            $this->consecutiveErrors = 0;
            // Reducir gradualmente el delay, pero nunca por debajo del delay base
            $this->currentDelay = max($this->baseDelay, $this->currentDelay * self::THROTTLE_REDUCTION_FACTOR);
        }
    }

    /**
     * Verifica si se debe mostrar sugerencia al usuario por errores consecutivos.
     *
     * @return  bool true si se debe mostrar sugerencia, false si no.
     */
    public function shouldShowSuggestion(): bool
    {
        return $this->consecutiveErrors >= self::MAX_CONSECUTIVE_ERRORS_THRESHOLD;
    }

    /**
     * Resetea el throttler a su estado inicial.
     *
     * @return  void
     */
    public function reset(): void
    {
        $this->consecutiveErrors = 0;
        $this->currentDelay = $this->baseDelay;
    }
}

