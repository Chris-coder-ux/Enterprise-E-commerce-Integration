<?php declare(strict_types=1);

/**
 * Interfaz de Logger compatible con PSR-3.
 *
 * Implementación simplificada para evitar dependencias externas,
 * manteniendo compatibilidad con PSR-3 LoggerInterface.
 *
 * @package MiIntegracionApi\Logging\Interfaces
 * @since 1.0.0
 * @version 1.1.0
 */

namespace MiIntegracionApi\Logging\Interfaces;

/**
 * Interfaz para un logger compatible con PSR-3.
 *
 * Define los métodos estándar para registrar mensajes en diferentes niveles de severidad.
 * Esta interfaz es la base del sistema de logging centralizado y debe ser implementada
 * por todas las clases de logging del sistema.
 *
 * @see https://www.php-fig.org/psr/psr-3/
 * @package MiIntegracionApi\Logging\Interfaces
 * @since 1.0.0
 */
interface ILogger
{
    /**
     * Registra un mensaje de emergencia (nivel más alto).
     *
     * El sistema está inutilizable.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function emergency($message, array $context = array()): void;

    /**
     * Registra un mensaje de alerta (requiere acción inmediata).
     *
     * Acción debe ser tomada inmediatamente.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function alert($message, array $context = array()): void;

    /**
     * Registra un mensaje de error crítico.
     *
     * Condiciones críticas.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function critical($message, array $context = array()): void;

    /**
     * Registra un mensaje de error.
     *
     * Condiciones de error en tiempo de ejecución que no requieren acción inmediata
     * pero típicamente deben ser registradas y monitoreadas.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function error($message, array $context = array()): void;

    /**
     * Registra un mensaje de advertencia.
     *
     * Condiciones excepcionales que no son errores.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function warning($message, array $context = array()): void;

    /**
     * Registra un mensaje de aviso (noticia importante pero no crítica).
     *
     * Condiciones normales pero significativas.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function notice($message, array $context = array()): void;

    /**
     * Registra un mensaje informativo.
     *
     * Eventos interesantes del sistema.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function info($message, array $context = array()): void;

    /**
     * Registra un mensaje de depuración (debug).
     *
     * Información detallada para debugging.
     *
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function debug($message, array $context = array()): void;

    /**
     * Registra un mensaje con un nivel arbitrario.
     *
     * @param mixed  $level   Nivel de severidad (string o int).
     * @param string $message Mensaje a registrar.
     * @param array  $context Contexto adicional (opcional).
     * @return void
     */
    public function log($level, $message, array $context = array()): void;
}
