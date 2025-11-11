<?php declare(strict_types=1);

/**
 * Implementación PSR-3 pura del sistema de logging.
 *
 * Esta clase contiene la implementación centralizada de logging
 * para todo el plugin Mi Integración API, siguiendo estrictamente
 * el estándar PSR-3.
 *
 * @package MiIntegracionApi\Logging\Core
 * @since 1.0.0
 * @version 1.1.0
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
 * Implementa la interfaz ILogger compatible con PSR-3 y proporciona un sistema centralizado
 * para registrar mensajes, errores y eventos en diferentes niveles de severidad.
 * Esta implementación es pura PSR-3 sin funcionalidades adicionales que serán
 * manejadas por otras clases del sistema de logging.
 *
 * @package MiIntegracionApi\Logging\Core
 * @implements \MiIntegracionApi\Logging\Interfaces\ILogger
 * @since 1.0.0
 * @version 1.1.0
 */
class LoggerComplex implements ILogger
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
     * @var string Nivel de error en tiempo de ejecución.
     */
    public const LEVEL_ERROR = 'error';
    
    /**
     * @var string Nivel de error crítico.
     */
    public const LEVEL_CRITICAL = 'critical';
    
    /**
     * @var string Nivel de alerta (acción inmediata requerida).
     */
    public const LEVEL_ALERT = 'alert';
    
    /**
     * @var string Nivel de emergencia (sistema inutilizable).
     */
    public const LEVEL_EMERGENCY = 'emergency';

    /**
     * Prioridades de niveles (mayor número = mayor prioridad).
     * 
     * @var array<string, int> Mapa de niveles de log a sus prioridades numéricas.
     * @since 1.0.0
     */
    public const PRIORITIES = [
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
     * @var string Identificador de categoría para agrupar mensajes de log relacionados.
     * @since 1.0.0
     */
    private string $category;

    /**
     * Nivel mínimo de log configurado.
     *
     * @var string Nivel mínimo de severidad para registrar mensajes.
     * @see Logger::PRIORITIES Para ver los niveles disponibles.
     * @since 1.0.0
     */
    private string $minLevel;

    /**
     * Constructor del Logger.
     *
     * Inicializa una nueva instancia del logger con la categoría y nivel mínimo especificados.
     *
     * @param string $category Categoría del logger. Por defecto: 'default'.
     * @param string $minLevel Nivel mínimo de log. Debe ser una de las constantes LEVEL_*.
     *                         Por defecto: self::LEVEL_DEBUG.
     * @throws \InvalidArgumentException Si el nivel mínimo no es válido.
     * @since 1.0.0
     */
    public function __construct(string $category = 'default', string $minLevel = self::LEVEL_DEBUG)
    {
        if (!isset(self::PRIORITIES[$minLevel])) {
            throw new \InvalidArgumentException(sprintf('Nivel de log no válido: %s', $minLevel));
        }
        
        $this->category = $category;
        $this->minLevel = $minLevel;
    }

    /**
     * Registra un mensaje de emergencia (nivel más alto).
     *
     * El sistema está inutilizable. Este es el nivel de log más severo y debe usarse
     * cuando la aplicación no puede continuar funcionando.
     *
     * @example
     * $logger->emergency('El sistema de base de datos no responde', [
     *     'error' => $e->getMessage(),
     *     'code' => $e->getCode()
     * ]);
     *
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el mensaje es inválido.
     * @since 1.0.0
     */
    public function emergency($message, array $context = array()): void
    {
        $this->log(self::LEVEL_EMERGENCY, $message, $context);
    }

    /**
     * Registra un mensaje de alerta (requiere acción inmediata).
     *
     * Debe usarse cuando se requiere atención inmediata. Ejemplo: el sitio web está caído,
     * base de datos inaccesible, etc. El sistema puede seguir funcionando pero requiere
     * intervención inmediata.
     *
     * @example
     * $logger->alert('Espacio en disco críticamente bajo', [
     *     'free_space' => disk_free_space('/'),
     *     'threshold' => '100MB'
     * ]);
     *
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el mensaje es inválido.
     * @since 1.0.0
     */
    public function alert($message, array $context = array()): void
    {
        $this->log(self::LEVEL_ALERT, $message, $context);
    }

    /**
     * Registra un mensaje de error crítico.
     *
     * Condiciones críticas que requieren atención inmediata pero no hacen que toda
     * la aplicación falle. Ejemplo: componente crítico inaccesible, excepción inesperada.
     *
     * @example
     * try {
     *     // Código que puede fallar
     * } catch (\Exception $e) {
     *     $logger->critical('Componente crítico falló', [
     *         'exception' => $e,
     *         'component' => 'payment_processor'
     *     ]);
     * }
     *
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el mensaje es inválido.
     * @since 1.0.0
     */
    public function critical($message, array $context = array()): void
    {
        $this->log(self::LEVEL_CRITICAL, $message, $context);
    }

    /**
     * Registra un mensaje de error.
     *
     * Condiciones de error en tiempo de ejecución que no requieren acción inmediata
     * pero deben ser registradas y monitoreadas. Estos errores no impiden que la
     * aplicación continúe funcionando, pero indican un problema que debe investigarse.
     *
     * @example
     * if (!file_exists($configFile)) {
     *     $logger->error('Archivo de configuración no encontrado', [
     *         'file' => $configFile,
     *         'fallback' => 'Usando configuración por defecto'
     *     ]);
     * }
     *
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el mensaje es inválido.
     * @since 1.0.0
     */
    public function error($message, array $context = array()): void
    {
        $this->log(self::LEVEL_ERROR, $message, $context);
    }

    /**
     * Registra un mensaje de advertencia.
     *
     * Condiciones excepcionales que no son errores pero pueden indicar problemas
     * que podrían convertirse en errores en el futuro. Ejemplo: uso de APIs obsoletas,
     * uso inapropiado de una API, cosas que no son necesariamente incorrectas.
     *
     * @example
     * if (version_compare(PHP_VERSION, '8.0.0', '<')) {
     *     $logger->warning('Versión de PHP obsoleta', [
     *         'current' => PHP_VERSION,
     *         'recommended' => '8.0.0 o superior',
     *         'eol' => 'El soporte para esta versión finalizará pronto'
     *     ]);
     * }
     *
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el mensaje es inválido.
     * @since 1.0.0
     */
    public function warning($message, array $context = array()): void
    {
        $this->log(self::LEVEL_WARNING, $message, $context);
    }

    /**
     * Registra un mensaje de aviso (noticia importante pero no crítica).
     *
     * Eventos normales pero significativos que no son necesariamente errores.
     * Ejemplo: usuario inicia sesión, se crea un nuevo registro importante, etc.
     *
     * @example
     * $logger->notice('Nuevo usuario registrado', [
     *     'user_id' => $user->getId(),
     *     'email' => $user->getEmail(),
     *     'ip' => $_SERVER['REMOTE_ADDR']
     * ]);
     *
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el mensaje es inválido.
     * @since 1.0.0
     */
    public function notice($message, array $context = array()): void
    {
        $this->log(self::LEVEL_NOTICE, $message, $context);
    }

    /**
     * Registra un mensaje informativo.
     *
     * Eventos interesantes del sistema que no son errores ni advertencias.
     * Ejemplo: usuario inicia sesión, se completa una tarea programada, etc.
     *
     * @example
     * $logger->info('Tarea programada completada', [
     *     'task' => 'limpiar_cache',
     *     'duration' => '1.23s',
     *     'memory_usage' => memory_get_peak_usage(true) / 1024 / 1024 . 'MB'
     * ]);
     *
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el mensaje es inválido.
     * @since 1.0.0
     */
    public function info($message, array $context = array()): void
    {
        $this->log(self::LEVEL_INFO, $message, $context);
    }

    /**
     * Registra un mensaje de depuración (debug).
     *
     * Información detallada que es útil solo para depurar la aplicación.
     * Estos mensajes normalmente solo se registran en entornos de desarrollo.
     *
     * @example
     * $logger->debug('Inicializando conexión a la base de datos', [
     *     'host' => $config->getHost(),
     *     'database' => $config->getDatabase(),
     *     'timeout' => $config->getTimeout()
     * ]);
     *
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException Si el mensaje es inválido.
     * @since 1.0.0
     */
    public function debug($message, array $context = array()): void
    {
        $this->log(self::LEVEL_DEBUG, $message, $context);
    }

    /**
     * Registra un mensaje con un nivel de severidad arbitrario.
     *
     * Este método es el núcleo del sistema de logging y es utilizado por todos
     * los métodos de nivel específico (debug, info, error, etc.).
     *
     * @example
     * // Uso directo con nivel personalizado
     * $logger->log('custom_level', 'Mensaje personalizado', ['data' => $value]);
     * 
     * // Uso con constantes de nivel
     * $logger->log(Logger::LEVEL_ERROR, 'Error en el proceso', ['step' => 'validación']);
     *
     * @param mixed  $level   Nivel de severidad (debe ser uno de los definidos en LEVEL_*).
     * @param string $message Mensaje a registrar o un objeto con __toString().
     * @param array  $context Variables para interpolar en el mensaje.
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
        if (!is_string($message) && !(is_object($message) && method_exists($message, '__toString'))) {
            throw new \InvalidArgumentException('El mensaje debe ser un string o un objeto que implemente __toString()');
        }

        // Procesar el mensaje con contexto
        $processedMessage = $this->interpolate((string)$message, $context);
        
        // Crear entrada de log
        $logEntry = $this->formatLogEntry((string)$level, $processedMessage, $context);
        
        // Escribir al archivo de log
        $this->writeToFile($logEntry);
    }

    /**
     * Verifica si un nivel de log debe ser registrado según la configuración actual.
     *
     * Este método compara la prioridad del nivel especificado con el nivel mínimo
     * configurado para determinar si el mensaje debe ser registrado.
     *
     * @param mixed $level Nivel de log a verificar (string o int).
     * @return bool True si el nivel debe ser registrado, false en caso contrario.
     * @since 1.0.0
     */
    private function shouldLog($level): bool
    {
        $levelString = is_string($level) ? $level : (string)$level;
        
        // Si el nivel no existe en las prioridades, no registrar
        if (!isset(self::PRIORITIES[$levelString])) {
            return false;
        }
        
        // Verificar si el nivel es igual o mayor al nivel mínimo configurado
        return self::PRIORITIES[$levelString] >= self::PRIORITIES[$this->minLevel];
    }

    /**
     * Interpola variables de contexto en el mensaje.
     *
     * Reemplaza los placeholders en el formato {key} con los valores correspondientes
     * del array de contexto. Solo reemplaza valores escalares u objetos con __toString().
     *
     * @example
     * // Mensaje con placeholders
     * $message = 'Usuario {user} realizó {action} en {resource}';
     * $context = ['user' => 'admin', 'action' => 'eliminó', 'resource' => 'documento.txt'];
     * // Resultado: 'Usuario admin realizó eliminó en documento.txt'
     *
     * @param string $message Mensaje con placeholders en formato {key}.
     * @param array  $context Array asociativo de variables para interpolar.
     * @return string Mensaje con los placeholders reemplazados.
     * @since 1.0.0
     */
    private function interpolate(string $message, array $context): string
    {
        if (empty($context)) {
            return $message;
        }

        $replace = [];
        foreach ($context as $key => $val) {
            // Solo reemplazar valores escalares u objetos convertibles a string
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Formatea una entrada de log con metadatos útiles.
     *
     * Crea una cadena de texto formateada que incluye:
     * - Marca de tiempo
     * - Categoría del logger
     * - Nivel de log
     * - Mensaje
     * - Contexto (opcional)
     * - Información de memoria (solo en modo depuración)
     *
     * @example
     * // Ejemplo de salida:
     * // [2023-01-01 12:00:00] default.ERROR: Error al procesar la solicitud | Context: {"user_id":123} | Memory: 2.5MB (Peak: 4.2MB)
     *
     * @param string $level   Nivel de log (ej: 'error', 'debug').
     * @param string $message Mensaje de log ya procesado.
     * @param array  $context Contexto original proporcionado.
     * @return string Cadena de texto formateada lista para ser registrada.
     * @since 1.0.0
     */
    private function formatLogEntry(string $level, string $message, array $context): string
    {
        $timestamp = date('Y-m-d H:i:s');
        $memoryUsage = memory_get_usage(true);
        $memoryPeak = memory_get_peak_usage(true);
        
        // Línea base del log: [timestamp] category.LEVEL: message
        $logLine = sprintf(
            "[%s] %s.%s: %s",
            $timestamp,
            $this->category,
            strtoupper($level),
            $message
        );
        
        // Añadir contexto si existe (excluyendo excepciones para evitar duplicados)
        if (!empty($context)) {
            $contextToLog = $context;
            
            // Si hay una excepción, la formateamos de manera especial
            if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                $e = $context['exception'];
                $contextToLog['exception'] = [
                    'class' => get_class($e),
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'file' => $e->getFile() . ':' . $e->getLine(),
                ];
            }
            
            $logLine .= ' | Context: ' . json_encode($contextToLog, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        
        // En modo depuración, añadir información de memoria
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $logLine .= sprintf(
                ' | Memory: %s (Peak: %s)',
                $this->formatBytes($memoryUsage),
                $this->formatBytes($memoryPeak)
            );
        }
        
        return $logLine . PHP_EOL;
    }

    /**
     * Escribe una entrada de log en el archivo correspondiente.
     *
     * Este método se encarga de:
     * 1. Obtener la ruta del archivo de log
     * 2. Crear el directorio si no existe
     * 3. Escribir la entrada de forma segura con bloqueo de archivo
     *
     * @param string $logEntry Entrada de log ya formateada.
     * @return void
     * @throws \RuntimeException Si no se puede escribir en el archivo de log.
     * @since 1.0.0
     */
    private function writeToFile(string $logEntry): void
    {
        $logFile = $this->getLogFilePath();
        $logDir = dirname($logFile);
        
        // Crear directorio si no existe con permisos 0755
        if (!is_dir($logDir) && !wp_mkdir_p($logDir)) {
            throw new \RuntimeException(sprintf('No se pudo crear el directorio de logs: %s', $logDir));
        }
        
        // Verificar permisos de escritura
        if (is_file($logFile) && !is_writable($logFile)) {
            throw new \RuntimeException(sprintf('El archivo de log no es escribible: %s', $logFile));
        }
        
        // Escribir al archivo con manejo de errores
        $result = @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        if ($result === false) {
            throw new \RuntimeException(sprintf('Error al escribir en el archivo de log: %s', $logFile));
        }
        
        // Limitar el tamaño del archivo de log (opcional)
        if (filesize($logFile) > (10 * 1024 * 1024)) { // 10MB
            $this->rotateLogs($logFile);
        }
    }
    
    /**
     * Rota los archivos de log cuando alcanzan un tamaño máximo.
     *
     * @param string $logFile Ruta al archivo de log actual.
     * @return void
     * @since 1.1.0
     */
    private function rotateLogs(string $logFile): void
    {
        // Implementación básica de rotación de logs
        $backupFile = $logFile . '.' . date('Y-m-d_His');
        if (file_exists($logFile)) {
            rename($logFile, $backupFile);
        }
    }

    /**
     * Obtiene la ruta completa al archivo de log para la categoría actual.
     *
     * El archivo de log se almacena en el directorio de subidas de WordPress,
     * dentro de una carpeta específica para la integración.
     *
     * @example
     * // Para categoría 'default' y directorio de subidas '/wp-content/uploads/':
     * // Devuelve: '/ruta/al/sitio/wp-content/uploads/mi-integracion-api-logs/default.log'
     *
     * @return string Ruta completa al archivo de log.
     * @since 1.0.0
     */
    private function getLogFilePath(): string
    {
        $uploadDir = wp_upload_dir();
        $logDir = $uploadDir['basedir'] . '/mi-integracion-api-logs';
        
        // Asegurar que el nombre del archivo sea seguro
        $safeCategory = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $this->category);
        $safeCategory = empty($safeCategory) ? 'default' : $safeCategory;
        
        return $logDir . '/' . $safeCategory . '.log';
    }

    /**
     * Convierte un número de bytes en una representación legible.
     *
     * @example
     * // Devuelve '1.5 MB'
     * $formatted = $this->formatBytes(1572864);
     *
     * @param int $bytes Número de bytes a formatear.
     * @param int $precision Número de decimales a mostrar (por defecto: 2).
     * @return string Cadena formateada con la unidad apropiada (B, KB, MB, GB).
     * @since 1.0.0
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        
        // Manejar valores negativos o cero
        $bytes = max($bytes, 0);
        if ($bytes === 0) {
            return '0 B';
        }
        
        // Calcular la unidad apropiada
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        // Calcular el valor formateado
        $bytes /= (1 << (10 * $pow));
        
        // Redondear al número de decimales especificado
        $rounded = round($bytes, $precision);
        
        // Formatear el número para eliminar ceros innecesarios
        $formatted = (string)$rounded;
        if (strpos($formatted, '.') !== false) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }
        
        return $formatted . ' ' . $units[$pow];
    }

    /**
     * Obtiene la categoría actual del logger.
     *
     * @return string La categoría del logger.
     * @since 1.0.0
     */
    public function getCategory(): string
    {
        return $this->category;
    }

    /**
     * Obtiene el nivel mínimo de log configurado actualmente.
     *
     * @return string El nivel mínimo de log (ej: 'debug', 'error').
     * @see Logger::PRIORITIES Para ver los niveles disponibles.
     * @since 1.0.0
     */
    public function getMinLevel(): string
    {
        return $this->minLevel;
    }

    /**
     * Establece un nuevo nivel mínimo de log.
     *
     * Solo se actualiza si el nivel proporcionado es válido.
     * Los mensajes con un nivel de severidad menor al establecido
     * no se registrarán.
     *
     * @example
     * // Solo registrar errores y superiores
     * $logger->setMinLevel(Logger::LEVEL_ERROR);
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
