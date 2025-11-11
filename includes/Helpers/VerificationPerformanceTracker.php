<?php

declare(strict_types=1);

/**
 * Sistema de monitoreo de rendimiento de verificaciones
 * 
 * Este sistema rastrea el rendimiento de las verificaciones para identificar
 * cuellos de botella y optimizar el rendimiento del sistema.
 * 
 * @package MiIntegracionApi\Helpers
 * @since 2.4.0
 */

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

class VerificationPerformanceTracker {
    
    /**
     * Métricas de rendimiento almacenadas
     *
     * @var array
     */
    private static $metrics = [];
    
    /**
     * Timestamp de inicio de la sesión
     *
     * @var int
     */
    private static $session_start = 0;
    
    /**
     * Inicializa el tracker de rendimiento
     * 
     * @return void
     */
    public static function initialize(): void {
        if (self::$session_start === 0) {
            self::$session_start = time();
        }
    }
    
    /**
     * Inicia el tracking de una verificación
     * 
     * @param string $verification_type Tipo de verificación
     * @param string $context Contexto de la verificación
     * @return string ID único del tracking
     */
    public static function startTracking(string $verification_type, string $context = 'general'): string {
        self::initialize();
        
        $tracking_id = uniqid($verification_type . '_', true);
        
        self::$metrics[$tracking_id] = [
            'type' => $verification_type,
            'context' => $context,
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(),
            'end_time' => null,
            'end_memory' => null,
            'duration' => null,
            'memory_used' => null,
            'success' => null,
            'error' => null
        ];
        
        return $tracking_id;
    }
    
    /**
     * Finaliza el tracking de una verificación
     * 
     * @param string $tracking_id ID del tracking
     * @param bool $success Si la verificación fue exitosa
     * @param string|null $error Mensaje de error si hubo uno
     * @return void
     */
    public static function endTracking(string $tracking_id, bool $success = true, ?string $error = null): void {
        if (!isset(self::$metrics[$tracking_id])) {
            return;
        }
        
        $end_time = microtime(true);
        $end_memory = memory_get_usage();
        
        self::$metrics[$tracking_id]['end_time'] = $end_time;
        self::$metrics[$tracking_id]['end_memory'] = $end_memory;
        self::$metrics[$tracking_id]['duration'] = $end_time - self::$metrics[$tracking_id]['start_time'];
        self::$metrics[$tracking_id]['memory_used'] = $end_memory - self::$metrics[$tracking_id]['start_memory'];
        self::$metrics[$tracking_id]['success'] = $success;
        self::$metrics[$tracking_id]['error'] = $error;
    }
    
    /**
     * Obtiene métricas de rendimiento por tipo
     * 
     * @param string $verification_type Tipo de verificación
     * @return array Métricas del tipo especificado
     */
    public static function getMetricsByType(string $verification_type): array {
        $filtered_metrics = array_filter(self::$metrics, function($metric) use ($verification_type) {
            return $metric['type'] === $verification_type;
        });
        
        return self::calculateStats($filtered_metrics);
    }
    
    /**
     * Obtiene métricas de rendimiento por contexto
     * 
     * @param string $context Contexto de la verificación
     * @return array Métricas del contexto especificado
     */
    public static function getMetricsByContext(string $context): array {
        $filtered_metrics = array_filter(self::$metrics, function($metric) use ($context) {
            return $metric['context'] === $context;
        });
        
        return self::calculateStats($filtered_metrics);
    }
    
    /**
     * Obtiene todas las métricas de rendimiento
     * 
     * @return array Todas las métricas
     */
    public static function getAllMetrics(): array {
        return self::calculateStats(self::$metrics);
    }
    
    /**
     * Obtiene métricas de rendimiento en tiempo real
     * 
     * @return array Métricas en tiempo real
     */
    public static function getRealTimeMetrics(): array {
        $now = time();
        $session_duration = $now - self::$session_start;
        
        $total_verifications = count(self::$metrics);
        $successful_verifications = count(array_filter(self::$metrics, function($metric) {
            return $metric['success'] === true;
        }));
        
        $failed_verifications = $total_verifications - $successful_verifications;
        
        $avg_duration = 0;
        $avg_memory = 0;
        
        if ($total_verifications > 0) {
            $total_duration = array_sum(array_column(self::$metrics, 'duration'));
            $total_memory = array_sum(array_column(self::$metrics, 'memory_used'));
            
            $avg_duration = $total_duration / $total_verifications;
            $avg_memory = $total_memory / $total_verifications;
        }
        
        return [
            'session_duration' => $session_duration,
            'total_verifications' => $total_verifications,
            'successful_verifications' => $successful_verifications,
            'failed_verifications' => $failed_verifications,
            'success_rate' => $total_verifications > 0 ? ($successful_verifications / $total_verifications) * 100 : 0,
            'avg_duration_ms' => round($avg_duration * 1000, 2),
            'avg_memory_kb' => round($avg_memory / 1024, 2),
            'verifications_per_second' => $session_duration > 0 ? round($total_verifications / $session_duration, 2) : 0
        ];
    }
    
    /**
     * Calcula estadísticas de un conjunto de métricas
     * 
     * @param array $metrics Métricas a analizar
     * @return array Estadísticas calculadas
     */
    private static function calculateStats(array $metrics): array {
        if (empty($metrics)) {
            return [
                'count' => 0,
                'avg_duration_ms' => 0,
                'min_duration_ms' => 0,
                'max_duration_ms' => 0,
                'avg_memory_kb' => 0,
                'min_memory_kb' => 0,
                'max_memory_kb' => 0,
                'success_rate' => 0
            ];
        }
        
        $durations = array_column($metrics, 'duration');
        $memories = array_column($metrics, 'memory_used');
        $successes = array_column($metrics, 'success');
        
        $success_count = count(array_filter($successes));
        $total_count = count($metrics);
        
        return [
            'count' => $total_count,
            'avg_duration_ms' => round(array_sum($durations) / $total_count * 1000, 2),
            'min_duration_ms' => round(min($durations) * 1000, 2),
            'max_duration_ms' => round(max($durations) * 1000, 2),
            'avg_memory_kb' => round(array_sum($memories) / $total_count / 1024, 2),
            'min_memory_kb' => round(min($memories) / 1024, 2),
            'max_memory_kb' => round(max($memories) / 1024, 2),
            'success_rate' => round(($success_count / $total_count) * 100, 2)
        ];
    }
    
    /**
     * Limpia las métricas antiguas
     * 
     * @param int $max_age_seconds Edad máxima en segundos
     * @return void
     */
    public static function cleanupOldMetrics(int $max_age_seconds = 3600): void {
        $cutoff_time = time() - $max_age_seconds;
        
        self::$metrics = array_filter(self::$metrics, function($metric) use ($cutoff_time) {
            return $metric['start_time'] > $cutoff_time;
        });
    }
    
    /**
     * Resetea todas las métricas
     * 
     * @return void
     */
    public static function reset(): void {
        self::$metrics = [];
        self::$session_start = time();
    }
    
    /**
     * Exporta las métricas para análisis
     * 
     * @return array Métricas exportadas
     */
    public static function exportMetrics(): array {
        return [
            'session_start' => self::$session_start,
            'session_duration' => time() - self::$session_start,
            'metrics' => self::$metrics,
            'summary' => self::getAllMetrics(),
            'realtime' => self::getRealTimeMetrics()
        ];
    }
}
