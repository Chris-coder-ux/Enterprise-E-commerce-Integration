<?php

declare(strict_types=1);

namespace MiIntegracionApi\Sync;

/**
 * Configuración centralizada para la sincronización de imágenes.
 *
 * Encapsula todos los parámetros configurables de ImageSyncManager,
 * permitiendo inyección de dependencias y facilitando el testing.
 *
 * @package     MiIntegracionApi\Sync
 * @version     1.0.0
 * @since       2.0.0
 */
class ImageSyncConfig
{
    /**
     * Constructor.
     *
     * @param   int   $chunkSize              Tamaño de chunk para procesamiento Base64 (en bytes). Por defecto: 10KB.
     * @param   int   $pageSize               Tamaño de página para paginación de productos. Por defecto: 100.
     * @param   int   $checkpointInterval     Intervalo para guardar checkpoint (cada N productos). Por defecto: 100.
     * @param   int   $statusUpdateInterval   Intervalo para actualizar estado (cada N productos). Por defecto: 5.
     * @param   int   $metricsInterval        Intervalo para registrar métricas (cada N productos). Por defecto: 10.
     * @param   float $maxThrottleDelay       Delay máximo de throttling en segundos. Por defecto: 5.0.
     * @param   float $baseThrottleDelay       Delay base de throttling en segundos. Por defecto: 0.01.
     */
    public function __construct(
        public readonly int $chunkSize = 10 * 1024,
        public readonly int $pageSize = 100,
        public readonly int $checkpointInterval = 100,
        public readonly int $statusUpdateInterval = 5,
        public readonly int $metricsInterval = 10,
        public readonly float $maxThrottleDelay = 5.0,
        public readonly float $baseThrottleDelay = 0.01
    ) {
    }
}

