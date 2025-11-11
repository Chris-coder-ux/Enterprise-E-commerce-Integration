<?php
/**
 * Configuración centralizada para la API de Verial
 *
 * @package MiIntegracionApi
 * @author  Christian
 * @version 1.0
 */

declare(strict_types=1);

if (!class_exists('VerialApiConfig')) {
    class VerialApiConfig {
    
    /**
     * Instancia singleton de la configuración
     */
    private static ?VerialApiConfig $instance = null;
    
    /**
     * URL base de la API de Verial
     */
    private string $verial_base_url;
    
    /**
     * ID de sesión para la API de Verial
     */
    private int $verial_session_id;
    
    /**
     * Timeout para las peticiones HTTP
     */
    private int $timeout;
    
    /**
     * Headers por defecto para las peticiones
     */
    private array $headers;
    
    /**
     * Configuración adicional
     */
    private array $config;
    
    /**
     * Constructor
     *
     * @param array $config Configuración opcional para sobrescribir valores por defecto
     */
    public function __construct(array $config = []) {
        $this->loadDefaultConfig();
        $this->mergeConfig($config);
        $this->validateConfig();
    }
    
    /**
     * Obtiene la instancia singleton de VerialApiConfig
     *
     * @param array $config Configuración opcional para la primera inicialización
     * @return VerialApiConfig Instancia única de la configuración
     */
    public static function getInstance(array $config = []): VerialApiConfig {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        return self::$instance;
    }
    
    /**
     * Carga la configuración desde WordPress o valores por defecto
     */
    private function loadDefaultConfig(): void {
        // Intentar cargar configuración desde WordPress si está disponible
        if (function_exists('get_option')) {
            $wp_options = get_option('mi_integracion_api_ajustes', []);
            $this->verial_base_url = $wp_options['mia_url_base'] ?? 'http://x.verial.org:8000/WcfServiceLibraryVerial/';
            $this->verial_session_id = (int) ($wp_options['mia_numero_sesion'] ?? 18);
        } else {
            // Valores por defecto si WordPress no está disponible
            $this->verial_base_url = 'http://x.verial.org:8000/WcfServiceLibraryVerial/';
            $this->verial_session_id = 18;
        }
        
        $this->timeout = 30;
        $this->headers = [
            'Content-Type' => 'application/json'
        ];
        $this->config = [];
    }
    
    /**
     * Mezcla configuración personalizada con la por defecto
     *
     * @param array $config Configuración personalizada
     */
    private function mergeConfig(array $config): void {
        if (isset($config['verial_base_url'])) {
            $this->verial_base_url = $config['verial_base_url'];
        }
        
        if (isset($config['verial_session_id'])) {
            $this->verial_session_id = (int) $config['verial_session_id'];
        }
        
        if (isset($config['timeout'])) {
            $this->timeout = (int) $config['timeout'];
        }
        
        if (isset($config['headers']) && is_array($config['headers'])) {
            $this->headers = array_merge($this->headers, $config['headers']);
        }
        
        $this->config = $config;
    }
    
    /**
     * Valida la configuración
     *
     * @throws InvalidArgumentException Si la configuración no es válida
     */
    private function validateConfig(): void {
        if (empty($this->verial_base_url)) {
            throw new InvalidArgumentException('La URL base de Verial no puede estar vacía');
        }
        
        if (!filter_var($this->verial_base_url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('La URL base de Verial no es válida: ' . $this->verial_base_url);
        }
        
        if ($this->verial_session_id <= 0) {
            throw new InvalidArgumentException('El ID de sesión debe ser un número positivo');
        }
        
        if ($this->timeout <= 0) {
            throw new InvalidArgumentException('El timeout debe ser un número positivo');
        }
    }
    
    /**
     * Obtiene la URL base de la API de Verial
     */
    public function getVerialBaseUrl(): string {
        return $this->verial_base_url;
    }
    
    /**
     * Obtiene el ID de sesión
     */
    public function getVerialSessionId(): int {
        return $this->verial_session_id;
    }
    
    /**
     * Obtiene el timeout
     */
    public function getTimeout(): int {
        return $this->timeout;
    }
    
    /**
     * Obtiene los headers
     */
    public function getHeaders(): array {
        return $this->headers;
    }
    
    /**
     * Establece la URL base
     *
     * @param string $url URL base
     */
    public function setVerialBaseUrl(string $url): self {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('URL no válida: ' . $url);
        }
        $this->verial_base_url = $url;
        return $this;
    }
    
    /**
     * Establece el ID de sesión
     *
     * @param int $sessionId ID de sesión
     */
    public function setVerialSessionId(int $sessionId): self {
        if ($sessionId <= 0) {
            throw new InvalidArgumentException('El ID de sesión debe ser positivo');
        }
        $this->verial_session_id = $sessionId;
        return $this;
    }
    
    /**
     * Establece el timeout
     *
     * @param int $timeout Timeout en segundos
     */
    public function setTimeout(int $timeout): self {
        if ($timeout <= 0) {
            throw new InvalidArgumentException('El timeout debe ser positivo');
        }
        $this->timeout = $timeout;
        return $this;
    }
    
    /**
     * Añade o modifica un header
     *
     * @param string $name Nombre del header
     * @param string $value Valor del header
     */
    public function setHeader(string $name, string $value): self {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * Obtiene toda la configuración como array (compatible con el formato anterior)
     */
    public function toArray(): array {
        return [
            'verial_base_url' => $this->verial_base_url,
            'verial_session_id' => $this->verial_session_id,
            'timeout' => $this->timeout,
            'headers' => $this->headers
        ];
    }

    /**
     * Obtiene el mapping de métodos de pago desde la configuración.
     * Puede ser sobrescrito en el array de configuración pasado al constructor usando la clave 'payment_mapping'.
     *
     * @return array<string,int>
     */
    public function getPaymentMethodMapping(): array {
        // Default mapping (puede ser personalizado)
        $default = [
            'stripe' => 8,
            'paypal' => 9,
            'bacs' => 1,
            'cheque' => 2,
            'cod' => 3,
            'bank_transfer' => 1,
            'cash_on_delivery' => 3,
            'default' => 8
        ];

        if (isset($this->config['payment_mapping']) && is_array($this->config['payment_mapping'])) {
            return array_merge($default, $this->config['payment_mapping']);
        }

        return $default;
    }
    
    /**
     * Carga configuración desde un archivo .env (si existe)
     */
    public function loadFromEnv(): self {
        $envFile = __DIR__ . '/.env';
        if (file_exists($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines as $line) {
                if (strpos($line, '=') !== false && $line[0] !== '#') {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");
                    
                    switch ($key) {
                        case 'VERIAL_BASE_URL':
                            $this->verial_base_url = $value;
                            break;
                        case 'VERIAL_SESSION_ID':
                            $this->verial_session_id = (int) $value;
                            break;
                        case 'VERIAL_TIMEOUT':
                            $this->timeout = (int) $value;
                            break;
                    }
                }
            }
            $this->validateConfig();
        }
        return $this;
    }
    
    /**
     * Obtiene la URL completa para un endpoint específico
     *
     * @param string $endpoint Nombre del endpoint (ej: 'GetArticulosWS')
     * @param array $params Parámetros opcionales
     */
    public function getEndpointUrl(string $endpoint, array $params = []): string {
        $url = rtrim($this->verial_base_url, '/') . '/' . $endpoint;
        
        // Añadir parámetro de sesión automáticamente
        $params['x'] = $this->verial_session_id;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        return $url;
    }
    
    /**
     * Verifica si la configuración es válida para producción
     */
    public function isProductionReady(): bool {
        // No debe usar URL de test en producción
        $isTestUrl = strpos($this->verial_base_url, 'http://x.verial.org:8000/WcfServiceLibraryVerial/') !== false;
        
        // Debe tener un ID de sesión real (no 18 que es de test)
        $isTestSession = $this->verial_session_id === 18;
        
        return !$isTestUrl && !$isTestSession;
    }
    
    /**
     * Obtiene información del entorno
     */
    public function getEnvironmentInfo(): array {
        return [
            'environment' => $this->isProductionReady() ? 'production' : 'development',
            'base_url' => $this->verial_base_url,
            'session_id' => $this->verial_session_id,
            'timeout' => $this->timeout,
            'is_test_environment' => !$this->isProductionReady()
        ];
    }
    }
}

// Compatibilidad con el formato anterior
// Si se requiere el archivo directamente, devolver un array como antes
if (!defined('LOADING_VERIALCONFIG_CLASS_ONLY')) {
    $config = new VerialApiConfig();
    return $config->toArray();
}
