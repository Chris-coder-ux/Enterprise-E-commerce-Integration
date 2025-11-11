<?php

declare(strict_types=1);

/**
 * Rastreador temporal de llamadas al SyncManager legacy.
 *
 * Este helper registra llamadas al legacy para medir adopción durante
 * la migración hacia Core. Se eliminará en F9.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 * @temporary Eliminar en F9
 */

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

class LegacyCallTracker {
    
    private static $option_key = 'mia_legacy_sync_calls';
    private static $enabled = null;
    
    /**
     * Verifica si el tracking de llamadas legacy está habilitado.
     *
     * Prioridad: constante explícita > opción en base de datos > WP_DEBUG > filtro externo.
     *
     * @return bool True si el tracking está habilitado, false en caso contrario.
     */
    private static function is_enabled(): bool {
        if (self::$enabled === null) {
            // Prioridad: constante explícita > option > WP_DEBUG
            $explicit_const = defined('MIA_TRACK_LEGACY') ? (bool) MIA_TRACK_LEGACY : null;
            $option = get_option('mia_track_legacy_calls', false);
            $debug = defined('WP_DEBUG') && WP_DEBUG;

            if ($explicit_const !== null) {
                self::$enabled = $explicit_const;
            } else {
                self::$enabled = ($option || $debug);
            }
            // Filtro para permitir sobre-escritura desde plugins externos
            if (function_exists('apply_filters')) {
                self::$enabled = (bool) apply_filters('mia_enable_legacy_calls_tracking', self::$enabled);
            }
        }
        return self::$enabled;
    }
    
    /**
     * Registra una llamada al método legacy.
     *
     * Guarda la llamada agrupada por fecha, método y archivo llamador. Mantiene solo los últimos 30 días.
     *
     * @param string $method Nombre del método llamado.
     * @param string $caller Archivo que hizo la llamada (opcional, se detecta automáticamente si no se proporciona).
     * @return void
     */
    public static function track_call(string $method, string $caller = ''): void {
        if (!self::is_enabled()) {
            return;
        }
        
        // Obtener caller automáticamente si no se proporciona
        if (empty($caller)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = $trace[2]['file'] ?? 'unknown';
            $caller = basename($caller);
        }
        
        $calls = get_option(self::$option_key, []);
        // Guardado inicial de timestamp baseline
        if (!isset($calls['__meta'])) {
            $calls['__meta'] = [
                'baseline_started' => time(),
                'version' => 1
            ];
        }
        $today = date('Y-m-d');
        
        // Inicializar estructura si no existe
        if (!isset($calls[$today])) {
            $calls[$today] = [];
        }
        
        if (!isset($calls[$today][$method])) {
            $calls[$today][$method] = [];
        }
        
        if (!isset($calls[$today][$method][$caller])) {
            $calls[$today][$method][$caller] = 0;
        }
        
        $calls[$today][$method][$caller]++;
        
        // Limpiar datos antiguos (mantener solo últimos 30 días)
        $cutoff = date('Y-m-d', strtotime('-30 days'));
        foreach (array_keys($calls) as $date) {
            if ($date === '__meta') { continue; }
            if ($date < $cutoff) { unset($calls[$date]); }
        }
        
        update_option(self::$option_key, $calls);
    }
    
    /**
     * Obtiene estadísticas de llamadas legacy en un periodo de días.
     *
     * Devuelve totales por método, llamador y por día.
     *
     * @param int $days Número de días atrás a incluir.
     * @return array Estadísticas de llamadas legacy.
     */
    public static function get_stats(int $days = 7): array {
        $calls = get_option(self::$option_key, []);
        $cutoff = date('Y-m-d', strtotime("-{$days} days"));
        
        $stats = [
            'total_calls' => 0,
            'methods' => [],
            'callers' => [],
            'daily' => [],
            'period' => $days
        ];
        
        foreach ($calls as $date => $day_data) {
            if ($date >= $cutoff) {
                $stats['daily'][$date] = 0;
                
                foreach ($day_data as $method => $method_calls) {
                    if (!isset($stats['methods'][$method])) {
                        $stats['methods'][$method] = 0;
                    }
                    
                    foreach ($method_calls as $caller => $count) {
                        $stats['methods'][$method] += $count;
                        $stats['total_calls'] += $count;
                        $stats['daily'][$date] += $count;
                        
                        if (!isset($stats['callers'][$caller])) {
                            $stats['callers'][$caller] = 0;
                        }
                        $stats['callers'][$caller] += $count;
                    }
                }
            }
        }
        
        // Ordenar por uso
        arsort($stats['methods']);
        arsort($stats['callers']);
        
        return $stats;
    }
    
    /**
     * Limpia todos los datos de tracking legacy.
     *
     * @return bool True si se eliminaron los datos, false en caso contrario.
     */
    public static function clear_data(): bool {
        return delete_option(self::$option_key);
    }
    
    /**
     * Habilita o deshabilita el tracking de llamadas legacy.
     *
     * @param bool $enabled True para habilitar, false para deshabilitar.
     * @return void
     */
    public static function set_enabled(bool $enabled): void {
        update_option('mia_track_legacy_calls', $enabled);
        self::$enabled = $enabled;
    }

    /**
     * Registra timestamp de activación del flag Core por defecto (solo una vez).
     *
     * @return void
     */
    public static function record_flag_enabled(): void {
        $calls = get_option(self::$option_key, []);
        if (!isset($calls['__meta'])) {
            $calls['__meta'] = [
                'baseline_started' => time(),
                'version' => 1
            ];
        }
        if (empty($calls['__meta']['flag_enabled_at'])) {
            $calls['__meta']['flag_enabled_at'] = time();
            update_option(self::$option_key, $calls);
        }
    }
}
