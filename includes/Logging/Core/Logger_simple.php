<?php declare(strict_types=1);

/**
 * Implementación simplificada de Logger compatible con PSR-3.
 *
 * Esta clase proporciona una implementación básica de la interfaz PSR-3 LoggerInterface,
 * enfocándose únicamente en el registro de mensajes en diferentes niveles de severidad.
 *
 * @package MiIntegracionApi\Logging\Core
 * @since 1.0.0
 * @version 1.2.0
 */

namespace MiIntegracionApi\Logging\Core;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\Logging\Interfaces\ILogger;

/**
 * Clase principal para logging compatible con PSR-3.
 *
 * Implementa la interfaz ILogger compatible con PSR-3 y proporciona un sistema básico
 * para registrar mensajes, errores y eventos en diferentes niveles de severidad.
 *
 * @package MiIntegracionApi\Logging\Core
 * @implements \MiIntegracionApi\Logging\Interfaces\ILogger
 * @since 1.0.0
 * @version 1.2.0
 */
class LoggerSimple implements ILogger
{
    /**
     * Niveles de log disponibles con prioridades (PSR-3 estándar).
     * 
     * @var string Nivel de depuración (el más bajo).
     */
    public const LEVEL_DEBUG = 'debug';
    
    /**
     * @var string Nivel informativo.
     */
    public const LEVEL_INFO = 'info';
    
    /**
     * @var string Nivel de notificación.
     */
    public const LEVEL_NOTICE = 'notice';
    
    /**
     * @var string Nivel de advertencia.
     */
    public const LEVEL_WARNING = 'warning';
    
    /**
     * @var string Nivel de error.
     */
    public const LEVEL_ERROR = 'error';
    
    /**
     * @var string Nivel crítico.
     */
    public const LEVEL_CRITICAL = 'critical';
    
    /**
     * @var string Nivel de alerta.
     */
    public const LEVEL_ALERT = 'alert';
    
    /**
     * @var string Nivel de emergencia (el más alto).
     */
    public const LEVEL_EMERGENCY = 'emergency';

    /**
     * Prioridades de los niveles de log (PSR-3 estándar).
     * 
     * @var array<string, int> Mapa de niveles a prioridades numéricas.
     */
    private const PRIORITIES = [
        self::LEVEL_DEBUG => 0,
        self::LEVEL_INFO => 1,
        self::LEVEL_NOTICE => 2,
        self::LEVEL_WARNING => 3,
        self::LEVEL_ERROR => 4,
        self::LEVEL_CRITICAL => 5,
        self::LEVEL_ALERT => 6,
        self::LEVEL_EMERGENCY => 7,
    ];

    /**
     * Categoría del logger.
     *
     * @var string Categoría que identifica el contexto del logger.
     */
    private string $category;

    /**
     * Nivel mínimo de log.
     *
     * @var string Nivel mínimo de log que se registrará.
     */
    private string $minLevel;

    /**
     * Constructor del Logger.
     *
     * @param string $category Categoría del logger.
     * @param string $minLevel Nivel mínimo de log (opcional).
     * @since 1.0.0
     */
    public function __construct(string $category, string $minLevel = self::LEVEL_DEBUG)
    {
        $this->category = $category;
        $this->minLevel = $minLevel;
    }

    /**
     * Registra un mensaje de emergencia.
     *
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @since 1.0.0
     */
    public function emergency($message, array $context = array()): void
    {
        $this->log(self::LEVEL_EMERGENCY, $message, $context);
    }

    /**
     * Registra un mensaje de alerta.
     *
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @since 1.0.0
     */
    public function alert($message, array $context = array()): void
    {
        $this->log(self::LEVEL_ALERT, $message, $context);
    }

    /**
     * Registra un mensaje crítico.
     *
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @since 1.0.0
     */
    public function critical($message, array $context = array()): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Registra un mensaje de error.
     *
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @since 1.0.0
     */
    public function error($message, array $context = array()): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Registra un mensaje de advertencia.
     *
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @since 1.0.0
     */
    public function warning($message, array $context = array()): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Registra un mensaje de notificación.
     *
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @since 1.0.0
     */
    public function notice($message, array $context = array()): void
    {
        $this->log(self::LEVEL_NOTICE, $message, $context);
    }

    /**
     * Registra un mensaje informativo.
     *
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @since 1.0.0
     */
    public function info($message, array $context = array()): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Registra un mensaje de depuración.
     *
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @since 1.0.0
     */
    public function debug($message, array $context = array()): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Registra un mensaje con el nivel especificado.
     *
     * @param mixed $level Nivel del log.
     * @param string $message Mensaje a registrar.
     * @param array $context Contexto adicional.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el nivel o mensaje no son válidos.
     * @since 1.0.0
     */
    public function log($level, $message, array $context = array()): void
    {
        // Validar nivel de log
        if (!$this->shouldLog($level)) {
            return;
        }

        // Validar mensaje
        if (!is_string($message) && !is_numeric($message)) {
            throw new \Psr\Log\InvalidArgumentException('El mensaje debe ser una cadena o número');
        }

        // Formatear mensaje
        $formattedMessage = $this->formatMessage($level, (string)$message, $context);
        
        // Escribir a error_log (simplificado para pruebas)
        error_log($formattedMessage);
    }

    /**
     * Verifica si se debe registrar el mensaje según el nivel mínimo.
     *
     * @param string $level Nivel del mensaje.
     * @return bool True si se debe registrar, false en caso contrario.
     * @since 1.0.0
     */
    private function shouldLog(string $level): bool
    {
        if (!isset(self::PRIORITIES[$level])) {
            return false;
        }
        
        return self::PRIORITIES[$level] >= self::PRIORITIES[$this->minLevel];
    }

    /**
     * Formatea el mensaje de log.
     *
     * @param string $level Nivel del log.
     * @param string $message Mensaje original.
     * @param array $context Contexto adicional.
     * @return string Mensaje formateado.
     * @since 1.0.0
     */
    private function formatMessage(string $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        
        return "[$timestamp][$level][{$this->category}] $message$contextStr";
    }

    /**
     * Establece el nivel mínimo de log.
     *
     * Los mensajes con un nivel de severidad menor al establecido
     * no se registrarán.
     *
     * @param string $minLevel Nuevo nivel mínimo de log.
     * @return void
     * @throws \InvalidArgumentException Si el nivel no es válido.
     * @since 1.0.0
     */
    public function setMinLevel(string $minLevel): void
    {
        if (!isset(self::PRIORITIES[$minLevel])) {
            throw new \InvalidArgumentException(sprintf('Nivel de log no válido: %s', $minLevel));
        }
        
        $this->minLevel = $minLevel;
    }

    /**
     * Método estático para compatibilidad con LoggerAuditoria.
     * 
     * Proporciona una interfaz estática compatible con LoggerAuditoria::log()
     * para facilitar la migración y mantener compatibilidad hacia atrás.
     * 
     * @param string|array|object|null $msg     Mensaje a registrar.
     * @param string                   $level   Nivel del log (info, warning, error, etc.).
     * @param array                    $context Contexto adicional para el mensaje.
     * @return void
     * @since 1.1.0
     */
    public static function logMessage($msg, $level = 'info', $context = []): void
    {
        // Crear instancia temporal para logging
        $logger = new self('auditoria');
        
        // Convertir $msg a string seguro
        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_null($msg)) {
            $msg = 'NULL';
        } else {
            $msg = (string)$msg;
        }
        
        // Convertir contexto a formato seguro
        $safeContext = [];
        if (!empty($context)) {
            if (is_array($context)) {
                $safeContext = $context;
            } elseif (is_object($context)) {
                $safeContext = (array)$context;
            } else {
                $safeContext = ['context' => (string)$context];
            }
        }
        
        // Usar el método de logging apropiado según el nivel
        switch (strtolower($level)) {
            case 'debug':
                $logger->debug($msg, $safeContext);
                break;
            case 'info':
                $logger->info($msg, $safeContext);
                break;
            case 'notice':
                $logger->notice($msg, $safeContext);
                break;
            case 'warning':
                $logger->warning($msg, $safeContext);
                break;
            case 'error':
                $logger->error($msg, $safeContext);
                break;
            case 'critical':
                $logger->critical($msg, $safeContext);
                break;
            case 'alert':
                $logger->alert($msg, $safeContext);
                break;
            case 'emergency':
                $logger->emergency($msg, $safeContext);
                break;
            default:
                $logger->info($msg, $safeContext);
        }
    }
}

