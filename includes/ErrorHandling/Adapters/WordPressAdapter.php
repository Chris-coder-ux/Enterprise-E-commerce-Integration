<?php

declare(strict_types=1);

namespace MiIntegracionApi\ErrorHandling\Adapters;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use WP_CLI;
use WP_Error;
use WP_REST_Response;

/**
 * Adaptador para compatibilidad con WordPress
 * 
 * Esta clase proporciona métodos para convertir respuestas del sistema
 * de manejo de errores independiente a formatos específicos de WordPress.
 * 
 * @package MiIntegracionApi\ErrorHandling\Adapters
 * @since 1.0.0
 */
class WordPressAdapter
{
    /**
     * Convierte SyncResponse a formato WordPress para AJAX
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return void
     */
    public static function sendAjaxResponse(SyncResponseInterface $response): void
    {
        if ($response->isSuccess()) {
            wp_send_json_success($response->getData(), $response->getHttpStatus());
        } else {
            wp_send_json_error($response->toArray(), $response->getHttpStatus());
        }
    }

    /**
     * Convierte SyncResponse a WP_REST_Response
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return WP_REST_Response
     */
    public static function toWpRestResponse(SyncResponseInterface $response): WP_REST_Response
    {
        return new WP_REST_Response(
            $response->toArray(),
            $response->getHttpStatus()
        );
    }

    /**
     * Convierte SyncResponse a WP_Error si es necesario
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return \WP_Error|null \WP_Error si hay error, null si fue exitosa
     */
    public static function toWpError(SyncResponseInterface $response): ?\WP_Error
    {
        return $response->toWpError();
    }

    /**
     * Convierte SyncResponse a formato para WP_CLI
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return void
     */
    public static function sendCliResponse(SyncResponseInterface $response): void
    {
        if ($response->isSuccess()) {
            WP_CLI::success($response->getMessage());
            if (!empty($response->getData())) {
                WP_CLI::log('Datos: ' . json_encode($response->getData(), JSON_PRETTY_PRINT));
            }
        } else {
            WP_CLI::error($response->getMessage());
        }
    }

    /**
     * Convierte SyncResponse a formato para logging de WordPress
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @param string $context Contexto del log
     * @return void
     */
    public static function logResponse(SyncResponseInterface $response, string $context = 'sync'): void
    {
        if ($response->isSuccess()) {
            error_log(sprintf(
                '[%s] SUCCESS: %s',
                strtoupper($context),
                $response->getMessage()
            ));
        } else {
            $error = $response->getError();
            error_log(sprintf(
                '[%s] ERROR: %s (Code: %d, Context: %s)',
                strtoupper($context),
                $response->getMessage(),
                $response->getErrorCode() ?? 0,
                $error ? json_encode($error->getContext()) : 'N/A'
            ));
        }
    }

    /**
     * Convierte SyncResponse a formato para notificaciones de WordPress
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @param string $type Tipo de notificación (success, error, warning, info, auto)
     *                     - 'success': Notificación de éxito (verde)
     *                     - 'error': Notificación de error (rojo)
     *                     - 'warning': Notificación de advertencia (amarillo)
     *                     - 'info': Notificación informativa (azul)
     *                     - 'auto': Usar el estado de la respuesta para determinar el tipo
     * @return string HTML de la notificación
     */
    public static function toNotification(SyncResponseInterface $response, string $type = 'info'): string
    {
        // Determinar clase e icono basado en el tipo especificado o el estado de la respuesta
        switch ($type) {
            case 'success':
                $class = 'notice-success';
                $icon = '✓';
                break;
            case 'error':
                $class = 'notice-error';
                $icon = '✗';
                break;
            case 'warning':
                $class = 'notice-warning';
                $icon = '⚠';
                break;
            case 'info':
            default:
                $class = 'notice-info';
                $icon = 'ℹ';
                break;
        }
        
        // Si no se especifica tipo o es 'auto', usar el estado de la respuesta
        if ($type === 'auto' || $type === 'info') {
            $class = $response->isSuccess() ? 'notice-success' : 'notice-error';
            $icon = $response->isSuccess() ? '✓' : '✗';
        }
        
        $html = sprintf(
            '<div class="notice %s is-dismissible"><p><strong>%s</strong> %s</p>',
            $class,
            $icon,
            esc_html($response->getMessage())
        );

        if (!$response->isSuccess() && $response->getError()) {
            $html .= sprintf(
                '<p><small>Código: %d | Reintentable: %s</small></p>',
                $response->getErrorCode() ?? 0,
                $response->isRetryable() ? 'Sí' : 'No'
            );
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Convierte SyncResponse a formato para respuestas de API REST de WordPress
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return array Array compatible con la API REST de WordPress
     */
    public static function toRestApiFormat(SyncResponseInterface $response): array
    {
        $data = $response->toArray();
        
        // Añadir metadatos específicos de WordPress
        $data['_wp_meta'] = [
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'request_id' => uniqid('sync_', true),
            'version' => '1.0.0'
        ];

        return $data;
    }

    /**
     * Convierte SyncResponse a formato para respuestas de AJAX de WordPress
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return array Array compatible con AJAX de WordPress
     */
    public static function toAjaxFormat(SyncResponseInterface $response): array
    {
        $data = $response->toArray();
        
        // Añadir metadatos específicos de AJAX
        $data['_ajax_meta'] = [
            'nonce' => wp_create_nonce('sync_action'),
            'timestamp' => time(),
            'user_can' => current_user_can('manage_options')
        ];

        return $data;
    }

    /**
     * Convierte SyncResponse a formato para respuestas de WP_CLI
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return array Array compatible con WP_CLI
     */
    public static function toCliFormat(SyncResponseInterface $response): array
    {
        return [
            'success' => $response->isSuccess(),
            'message' => $response->getMessage(),
            'data' => $response->getData(),
            'error_code' => $response->getErrorCode(),
            'http_status' => $response->getHttpStatus(),
            'retryable' => $response->isRetryable(),
            'retry_delay' => $response->getRetryDelay()
        ];
    }

    /**
     * Convierte SyncResponse a formato para debugging
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return array Array con información detallada para debugging
     */
    public static function toDebugFormat(SyncResponseInterface $response): array
    {
        $debug = $response->toArray();
        
        // Añadir información de debugging
        $debug['_debug'] = [
            'php_version' => PHP_VERSION,
            'wordpress_version' => get_bloginfo('version'),
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
            'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'N/A',
            'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'N/A',
            'request_uri' => $_SERVER['REQUEST_URI'] ?? 'N/A'
        ];

        return $debug;
    }

    /**
     * Convierte SyncResponse a formato para métricas
     * 
     * @param SyncResponseInterface $response Respuesta del sistema
     * @return array Array con métricas de la respuesta
     */
    public static function toMetricsFormat(SyncResponseInterface $response): array
    {
        return [
            'timestamp' => time(),
            'success' => $response->isSuccess(),
            'error_code' => $response->getErrorCode(),
            'http_status' => $response->getHttpStatus(),
            'retryable' => $response->isRetryable(),
            'retry_delay' => $response->getRetryDelay(),
            'data_size' => strlen(json_encode($response->getData())),
            'metadata_count' => count($response->getMetadata())
        ];
    }

    /**
     * Verifica si WordPress está disponible
     * 
     * @return bool True si WordPress está disponible, false en caso contrario
     */
    public static function isWordPressAvailable(): bool
    {
        return function_exists('wp_send_json_success') && 
               class_exists('\WP_REST_Response') && 
               class_exists('\WP_Error');
    }

    /**
     * Obtiene la versión de WordPress si está disponible
     * 
     * @return string|null Versión de WordPress o null si no está disponible
     */
    public static function getWordPressVersion(): ?string
    {
        if (function_exists('get_bloginfo')) {
            return get_bloginfo('version');
        }
        return null;
    }
}
