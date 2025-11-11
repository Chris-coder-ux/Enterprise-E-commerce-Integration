<?php declare(strict_types=1);

/**
 * Filtro de datos sensibles para el sistema de logging.
 *
 * Esta clase se encarga de proteger datos sensibles en los logs,
 * oscureciendo información confidencial como contraseñas, tokens, etc.
 *
 * @package MiIntegracionApi\Logging\Security
 * @since 1.0.0
 * @version 1.1.0
 */

namespace MiIntegracionApi\Logging\Security;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para filtrado de datos sensibles en logs.
 *
 * Protege información confidencial en los logs oscureciendo
 * claves sensibles, contraseñas, tokens y otros datos críticos.
 *
 * @package MiIntegracionApi\Logging\Security
 * @since 1.0.0
 */
class SensitiveDataFilter
{
    /**
     * Lista de claves sensibles que deben ser oscurecidas.
     *
     * @var array
     */
    private static array $sensitiveKeys = [
        'password', 'pass', 'pwd', 'secret', 'token', 'api_key', 
        'apikey', 'api_secret', 'apisecret', 'key', 'auth', 
        'credentials', 'credential', 'private', 'security',
        'hash', 'salt', 'iv', 'cipher', 'crypt', 'secure',
        'banco', 'tarjeta', 'cvv', 'account', 'cuenta', 'iban',
        'swift', 'bic', 'pin', 'pass_token', 'refresh', 'jwt',
        'authorization', 'bearer', 'session', 'cookie',
        'credit_card', 'card_number', 'expiry', 'cvc',
        'ssn', 'social_security', 'dni', 'cedula',
        'phone', 'telefono', 'email', 'correo'
    ];

    /**
     * Patrones de datos sensibles (expresiones regulares).
     *
     * @var array
     */
    private static array $sensitivePatterns = [
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/' => '[EMAIL]',
        '/\b\d{4}[-\s]?\d{4}[-\s]?\d{4}[-\s]?\d{4}\b/' => '[CARD_NUMBER]',
        '/\b\d{3}-\d{2}-\d{4}\b/' => '[SSN]',
        '/\b[A-Z]{2}\d{2}[A-Z0-9]{4}\d{7}([A-Z0-9]?){0,16}\b/' => '[IBAN]',
        '/\b[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?\b/' => '[SWIFT]',
    ];

    /**
     * Carácter de reemplazo para datos sensibles.
     *
     * @var string
     */
    private static string $replacementChar = '*';

    /**
     * Número de caracteres a mostrar al inicio y final de datos sensibles.
     *
     * @var int
     */
    private static int $visibleChars = 2;

    /**
     * Filtra datos sensibles en un array de contexto.
     *
     * @param array $context Contexto a filtrar.
     * @return array Contexto filtrado.
     */
    public static function filterContext(array $context): array
    {
        if (empty($context)) {
            return $context;
        }

        $filtered = [];
        
        foreach ($context as $key => $value) {
            $filteredKey = self::filterKey($key);
            $filteredValue = self::filterValue($value);
            $filtered[$filteredKey] = $filteredValue;
        }

        return $filtered;
    }

    /**
     * Filtra una clave si es sensible.
     *
     * @param string $key Clave a verificar.
     * @return string Clave filtrada.
     */
    public static function filterKey(string $key): string
    {
        foreach (self::$sensitiveKeys as $sensitiveKey) {
            if (stripos($key, $sensitiveKey) !== false) {
                return self::maskString($key);
            }
        }

        return $key;
    }

    /**
     * Filtra un valor si es sensible.
     *
     * @param mixed $value Valor a verificar.
     * @return mixed Valor filtrado.
     */
    public static function filterValue($value)
    {
        if (is_string($value)) {
            return self::filterString($value);
        }

        if (is_array($value)) {
            return self::filterContext($value);
        }

        if (is_object($value)) {
            return self::filterObject($value);
        }

        return $value;
    }

    /**
     * Filtra una cadena de texto buscando patrones sensibles.
     *
     * @param string $string Cadena a filtrar.
     * @return string Cadena filtrada.
     */
    public static function filterString(string $string): string
    {
        // Aplicar patrones de expresiones regulares
        foreach (self::$sensitivePatterns as $pattern => $replacement) {
            $string = preg_replace($pattern, $replacement, $string);
        }

        return $string;
    }

    /**
     * Filtra un objeto buscando propiedades sensibles.
     *
     * @param object $object Objeto a filtrar.
     * @return object Objeto filtrado.
     */
    public static function filterObject($object)
    {
        if (method_exists($object, '__toString')) {
            return self::filterString((string) $object);
        }

        // Para objetos que no se pueden convertir a string,
        // devolver una representación genérica
        return '[OBJECT:' . get_class($object) . ']';
    }

    /**
     * Enmascara una cadena mostrando solo algunos caracteres.
     *
     * @param string $string Cadena a enmascarar.
     * @return string Cadena enmascarada.
     */
    public static function maskString(string $string): string
    {
        $length = strlen($string);
        
        if ($length <= self::$visibleChars * 2) {
            return str_repeat(self::$replacementChar, $length);
        }

        $start = substr($string, 0, self::$visibleChars);
        $end = substr($string, -self::$visibleChars);
        $middle = str_repeat(self::$replacementChar, $length - (self::$visibleChars * 2));

        return $start . $middle . $end;
    }

    /**
     * Verifica si una clave es sensible.
     *
     * @param string $key Clave a verificar.
     * @return bool True si es sensible.
     */
    public static function isSensitiveKey(string $key): bool
    {
        foreach (self::$sensitiveKeys as $sensitiveKey) {
            if (stripos($key, $sensitiveKey) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Verifica si un valor contiene datos sensibles.
     *
     * @param mixed $value Valor a verificar.
     * @return bool True si contiene datos sensibles.
     */
    public static function containsSensitiveData($value): bool
    {
        if (is_string($value)) {
            foreach (self::$sensitivePatterns as $pattern => $replacement) {
                if (preg_match($pattern, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Añade una nueva clave sensible.
     *
     * @param string $key Clave sensible a añadir.
     * @return void
     */
    public static function addSensitiveKey(string $key): void
    {
        if (!in_array(strtolower($key), array_map('strtolower', self::$sensitiveKeys))) {
            self::$sensitiveKeys[] = $key;
        }
    }

    /**
     * Añade múltiples claves sensibles.
     *
     * @param array $keys Claves sensibles a añadir.
     * @return void
     */
    public static function addSensitiveKeys(array $keys): void
    {
        foreach ($keys as $key) {
            self::addSensitiveKey($key);
        }
    }

    /**
     * Añade un nuevo patrón sensible.
     *
     * @param string $pattern Patrón de expresión regular.
     * @param string $replacement Reemplazo para el patrón.
     * @return void
     */
    public static function addSensitivePattern(string $pattern, string $replacement): void
    {
        self::$sensitivePatterns[$pattern] = $replacement;
    }

    /**
     * Establece el carácter de reemplazo.
     *
     * @param string $char Carácter de reemplazo.
     * @return void
     */
    public static function setReplacementChar(string $char): void
    {
        self::$replacementChar = $char;
    }

    /**
     * Establece el número de caracteres visibles.
     *
     * @param int $chars Número de caracteres visibles.
     * @return void
     */
    public static function setVisibleChars(int $chars): void
    {
        self::$visibleChars = max(0, $chars);
    }

    /**
     * Obtiene la lista actual de claves sensibles.
     *
     * @return array Lista de claves sensibles.
     */
    public static function getSensitiveKeys(): array
    {
        return self::$sensitiveKeys;
    }

    /**
     * Obtiene la lista actual de patrones sensibles.
     *
     * @return array Lista de patrones sensibles.
     */
    public static function getSensitivePatterns(): array
    {
        return self::$sensitivePatterns;
    }

    /**
     * Obtiene información de debug sobre el filtro.
     *
     * @return array Información de debug.
     */
    public static function getDebugInfo(): array
    {
        return [
            'sensitive_keys_count' => count(self::$sensitiveKeys),
            'sensitive_patterns_count' => count(self::$sensitivePatterns),
            'replacement_char' => self::$replacementChar,
            'visible_chars' => self::$visibleChars,
            'sensitive_keys' => self::$sensitiveKeys,
            'sensitive_patterns' => array_keys(self::$sensitivePatterns),
        ];
    }
}
