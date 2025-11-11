<?php

declare(strict_types=1);

/**
 * SSL Advanced Systems Trait
 * 
 * Implementa funcionalidades avanzadas para la gestión de conexiones SSL seguras,
 * incluyendo caché de certificados, gestión de timeouts dinámicos, configuración
 * avanzada de SSL y rotación automática de certificados.
 *
 * @since       1.0.0
 * @package     MiIntegracionApi\Core
 * @category    Core
 * @see         https://www.php.net/manual/es/book.openssl.php Documentación de OpenSSL en PHP
 */

namespace MiIntegracionApi\Core;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestión avanzada de sistemas SSL
 *
 * Este trait proporciona una capa de abstracción para operaciones SSL avanzadas,
 * incluyendo:
 * - Caché de certificados SSL para mejorar el rendimiento
 * - Gestión dinámica de timeouts basada en métricas de red
 * - Configuración flexible de parámetros SSL
 * - Rotación automática de certificados
 * - Estadísticas de rendimiento y latencia
 *
 * @since       1.0.0
 * @package     MiIntegracionApi\Core
 * @uses        \MiIntegracionApi\SSL\CertificateCache
 * @uses        \MiIntegracionApi\SSL\SSLTimeoutManager
 * @uses        \MiIntegracionApi\SSL\SSLConfigManager
 * @uses        \MiIntegracionApi\SSL\CertificateRotation
 */
trait SSLAdvancedSystemsTrait {
    /**
     * Instancia del gestor de caché de certificados
     *
     * @var \MiIntegracionApi\SSL\CertificateCache|null
     * @since 1.0.0
     */
    private $cert_cache = null;

    /**
     * Gestor de timeouts para conexiones SSL
     *
     * @var \MiIntegracionApi\SSL\SSLTimeoutManager|null
     * @since 1.0.0
     */
    private $timeout_manager = null;

    /**
     * Gestor de configuración SSL
     *
     * @var \MiIntegracionApi\SSL\SSLConfigManager|null
     * @since 1.0.0
     */
    private $ssl_config_manager = null;

    /**
     * Sistema de rotación de certificados
     *
     * @var \MiIntegracionApi\SSL\CertificateRotation|null
     * @since 1.0.0
     */
    private $cert_rotation = null;

    /**
     * Inicializa los componentes avanzados de gestión SSL
     *
     * Configura e inicializa todas las dependencias necesarias para el manejo avanzado de SSL,
     * incluyendo el caché de certificados, gestión de timeouts, configuración SSL y sistema
     * de rotación de certificados.
     *
     * Este método debe ser llamado durante la inicialización del plugin o cuando se necesite
     * reiniciar la configuración SSL.
     *
     * @return void
     * @since 1.0.0
     * @throws \RuntimeException Si falla la inicialización de algún componente
     */
    private function initSSLSystems(): void {
        // Inicializar caché de certificados
        $this->cert_cache = new \MiIntegracionApi\SSL\CertificateCache($this->logger);
        
        // Inicializar gestor de timeouts SSL
        $this->timeout_manager = new \MiIntegracionApi\SSL\SSLTimeoutManager($this->logger);
        
        // Inicializar gestor de configuración SSL
        $this->ssl_config_manager = new \MiIntegracionApi\SSL\SSLConfigManager($this->logger);
        
        // Inicializar sistema de rotación de certificados
        $this->cert_rotation = new \MiIntegracionApi\SSL\CertificateRotation($this->logger);
        
        // Registrar acciones de WordPress para el sistema de rotación
        add_action('miapi_ssl_certificate_rotation', [$this->cert_rotation, 'scheduledRotation']);
        
        // Registrar acción para guardar estadísticas de latencia periódicamente
        add_action('miapi_ssl_save_latency_stats', [$this, 'saveLatencyStats']);
        
        // CORRECCIÓN: Los cron jobs se manejan centralmente en RobustnessHooks
        // para evitar múltiples cargas del plugin
        // La programación se hace automáticamente desde RobustnessHooks
    }
    
    /**
     * Guarda las estadísticas de latencia para análisis de rendimiento
     *
     * Recopila y almacena las métricas de latencia de las conexiones SSL realizadas,
     * permitiendo un análisis del rendimiento a lo largo del tiempo. Las estadísticas
     * se almacenan en la base de datos de WordPress con un historial de 30 días.
     *
     * Este método se ejecuta periódicamente a través de un evento programado de WordPress.
     *
     * @return void
     * @since 1.0.0
     * @see SSLTimeoutManager::getLatencyStats() Para obtener las estadísticas de latencia
     */
    public function saveLatencyStats(): void {
        if (!$this->timeout_manager) {
            return;
        }
        
        $stats = $this->timeout_manager->getLatencyStats();
        if (!empty($stats)) {
            $stats['date'] = date('Y-m-d');
            
            // Almacenar un historial limitado de estadísticas diarias
            $history = get_option('miapi_ssl_latency_stats_history', []);
            $history[] = $stats;
            
            // Mantener solo los últimos 30 días
            if (count($history) > 30) {
                $history = array_slice($history, -30);
            }
            
            update_option('miapi_ssl_latency_stats_history', $history);
            $this->logger->info("[SSL Stats] Estadísticas de latencia guardadas: " . $stats['total_requests'] . " solicitudes");
        }
    }
    
    /**
     * Gestiona la rotación de certificados SSL
     *
     * Verifica si es necesario rotar los certificados según los criterios configurados
     * (fecha de expiración, revocación, etc.) y realiza la rotación si es necesario.
     *
     * La rotación puede ser forzada ignorando los criterios normales, lo que es útil
     * en situaciones donde se requiere una rotación inmediata por motivos de seguridad.
     *
     * @param bool $force_rotation Si es true, fuerza la rotación de certificados
     *                           independientemente de si es necesaria o no.
     * @return bool true si la operación fue exitosa o no fue necesaria,
     *              false si ocurrió un error durante la rotación.
     * @since 1.0.0
     * @see CertificateRotation::needsRotation() Para los criterios de rotación
     * @see CertificateRotation::rotateCertificates() Para el proceso de rotación
     */
    public function checkCertificateRotation(bool $force_rotation = false): bool {
        if (!$this->cert_rotation) {
            return false;
        }
        
        if ($force_rotation || $this->cert_rotation->needsRotation()) {
            return $this->cert_rotation->rotateCertificates($force_rotation);
        }
        
        return true;
    }
    
    /**
     * Obtiene un certificado SSL desde la caché o del sistema de archivos
     *
     * Este método proporciona una capa de abstracción para la obtención de certificados,
     * utilizando un sistema de caché para mejorar el rendimiento. Los certificados
     * se almacenan en caché según la configuración del sistema.
     *
     * @param string $cert_url Ruta local o URL remota del certificado a obtener.
     *                        Para archivos locales, puede ser una ruta relativa o absoluta.
     * @param bool $force_refresh Si es true, ignora la caché y fuerza la recarga
     *                          del certificado desde la fuente original.
     * @return string|false El contenido del certificado como string en formato PEM,
     *                     o false si no se pudo cargar el certificado.
     * @since 1.0.0
     * @see CertificateCache::getCertificate() Para más detalles sobre el sistema de caché
     * @throws \RuntimeException Si ocurre un error al leer el certificado
     */
    public function getCachedCertificate(string $cert_url, bool $force_refresh = false) {
        if (!$this->cert_cache) {
            return file_exists($cert_url) ? file_get_contents($cert_url) : false;
        }
        
        return $this->cert_cache->getCertificate($cert_url, $force_refresh);
    }
    
    /**
     * Prepara los argumentos para una petición HTTP con configuración SSL avanzada
     *
     * Este método mejora los argumentos de una petición HTTP con configuraciones SSL avanzadas,
     * incluyendo timeouts dinámicos, verificación de certificados y configuración de CA bundles.
     * Es utilizado internamente por los métodos que realizan solicitudes HTTP seguras.
     *
     * @param array $args Argumentos originales de la petición HTTP.
     * @param string $url URL de destino de la petición.
     * @param string $method Método HTTP a utilizar (GET, POST, etc.). Por defecto vacío.
     * @return array Argumentos de la petición con las mejoras SSL aplicadas.
     * @since 1.0.0
     * @see SSLConfigManager::applyWpRequestArgs() Para la configuración SSL
     * @see SSLTimeoutManager::applyWpRequestArgs() Para la gestión de timeouts
     */
    private function prepareRequestWithSSLSystems(array $args, string $url, string $method = ''): array {
        $is_local = $this->isLocalDevelopment();
        
        // Aplicar configuración SSL avanzada
        if ($this->ssl_config_manager) {
            $args = $this->ssl_config_manager->applyWpRequestArgs($args, $is_local);
        }
        
        // Aplicar configuración de timeouts
        if ($this->timeout_manager) {
            $args = $this->timeout_manager->applyWpRequestArgs($args, $url, 0, $method);
        }
        
        // Asegurar que tenemos un CA bundle válido
        if ($this->ssl_config_manager && $this->ssl_config_manager->getOption('verify_peer', true)) {
            $ca_bundle_path = $this->ssl_config_manager->getOption('ca_bundle_path', '');
            
            if (empty($ca_bundle_path)) {
                $ca_bundle_path = $this->findCaBundlePath();
            }
            
            if ($ca_bundle_path && file_exists($ca_bundle_path)) {
                $args['sslcertificates'] = $ca_bundle_path;
            }
        }
        
        return $args;
    }
    
    /**
     * Realiza una solicitud HTTP con reintentos automáticos y gestión avanzada de errores
     *
     * Este método implementa un sistema robusto de reintentos con backoff exponencial,
     * manejo de timeouts dinámicos y gestión de errores mejorada. Es especialmente
     * útil para operaciones críticas que requieren alta disponibilidad.
     *
     * Características principales:
     * - Reintentos automáticos con backoff exponencial
     * - Timeouts adaptativos basados en métricas históricas
     * - Manejo específico de errores de red y tiempo de espera
     * - Integración con el sistema de logging
     *
     * @param string $method Método HTTP a utilizar (GET, POST, PUT, DELETE, etc.)
     * @param string $url URL completa del recurso a solicitar
     * @param array<string, mixed> $args Argumentos adicionales para la petición.
     *                                 Ver documentación de wp_remote_request() para opciones.
     * @return array|\Exception
     *         - array: Respuesta exitosa con claves 'headers', 'body', 'response', etc.
     *         - \Exception: Excepción inesperada durante la ejecución o error de WordPress convertido
     *
     * @since 1.0.0
     * @throws \InvalidArgumentException Si los parámetros no son válidos
     * @see wp_remote_request() Para el formato de los argumentos y respuesta
     * @see SSLTimeoutManager::applyWpRequestArgs() Para la gestión de timeouts
     */
    private function doRequestWithRetry(string $method, string $url, array $args = []) {
        if (!$this->timeout_manager) {
            // Si no hay gestor de timeouts, usar el método estándar
            return $this->make_request($url, $args, $method);
        }
        
        // Preparar la solicitud con sistemas SSL mejorados
        $args = $this->prepareRequestWithSSLSystems($args, $url, $method);
        
        // Definir la función que realizará la solicitud
        $request_fn = function($url, $args, $retry_number) use ($method) {
            // Actualizar argumentos para este intento específico
            $args = $this->timeout_manager->applyWpRequestArgs($args, $url, $retry_number, $method);
            
            // Realizar la solicitud según el método
            $response = null;
            switch (strtoupper($method)) {
                case 'GET':
                    $response = wp_remote_get($url, $args);
                    break;
                case 'POST':
                    $response = wp_remote_post($url, $args);
                    break;
                case 'HEAD':
                    $response = wp_remote_head($url, $args);
                    break;
                default:
                    $args['method'] = strtoupper($method);
                    $response = wp_remote_request($url, $args);
                    break;
            }
            
            // Convertir a excepción para mantener consistencia
            if (is_wp_error($response)) {
                throw new \Exception(
                    'Error en solicitud HTTP: ' . $response->get_error_message(),
                    $response->get_error_code() ?: 0
                );
            }
            
            return $response;
        };
        
        // Ejecutar con sistema de reintentos
        return $this->timeout_manager->executeWithRetry($request_fn, $url, $args);
    }
    
    /**
     * Mejora una conexión CURL con configuraciones SSL avanzadas
     *
     * Aplica configuraciones de seguridad y rendimiento a un manejador CURL,
     * incluyendo:
     * - Configuración de CA bundles personalizados
     * - Ajuste de timeouts dinámicos
     * - Configuración de versiones de protocolo SSL/TLS
     * - Verificación de certificados mejorada
     *
     * @param resource $ch Manejador CURL a configurar. Debe ser un recurso válido
     *                    devuelto por curl_init().
     * @param string $url URL de destino para la conexión CURL.
     * @param string $method Método HTTP a utilizar (GET, POST, etc.).
     *                      Si no se especifica, se intenta determinar automáticamente.
     *
     * @return resource El manejador CURL con las configuraciones aplicadas.
     *
     * @throws \InvalidArgumentException Si el manejador CURL no es válido.
     * @throws \RuntimeException Si falla la configuración de opciones CURL.
     *
     * @since 1.0.0
     * @see curl_setopt() Para más información sobre las opciones de CURL
     * @see https://curl.se/libcurl/c/curl_easy_setopt.html Documentación de opciones CURL
     */
    private function enhanceCurlWithSSLSystems($ch, string $url, string $method = '') {
        $is_local = $this->isLocalDevelopment();
        
        // Aplicar configuración SSL avanzada
        if ($this->ssl_config_manager) {
            $ch = $this->ssl_config_manager->applyCurlOptions($ch, $is_local);
        }
        
        // Aplicar configuración de timeouts
        if ($this->timeout_manager) {
            $ch = $this->timeout_manager->applyCurlTimeouts($ch, $url, 0, $method);
        }
        
        return $ch;
    }
    
    /**
     * Obtiene estadísticas detalladas del sistema de caché de certificados
     *
     * Proporciona métricas sobre el rendimiento y uso del caché de certificados,
     * incluyendo:
     * - Número total de certificados en caché
     * - Tamaño total de la caché en memoria
     * - Tasa de aciertos/fallos
     * - Tiempo medio de carga de certificados
     *
     * @return array<string, mixed> Un array asociativo con las estadísticas de caché que incluye:
     *         - 'total_certificates': (int) Número total de certificados en caché
     *         - 'hits': (int) Número de aciertos en la caché
     *         - 'misses': (int) Número de fallos en la caché
     *         - 'memory_usage': (string) Uso de memoria formateado (ej: '2.5 MB')
     *         - 'hit_rate': (float) Tasa de aciertos (0-1)
     *         - 'avg_load_time': (float) Tiempo medio de carga en segundos
     *
     * @since 1.0.0
     * @see CertificateCache::getStats() Para la implementación específica
     */
    public function getCertificateCacheStats(): array {
        if (!$this->cert_cache) {
            return ['enabled' => false];
        }
        
        $stats = $this->cert_cache->getCacheStats();
        $stats['enabled'] = true;
        
        return $stats;
    }
    
    /**
     * Obtiene estadísticas detalladas sobre timeouts y latencias de conexión
     *
     * Proporciona métricas sobre el rendimiento de las conexiones, incluyendo:
     * - Tiempos de respuesta promedio, mínimo y máximo
     * - Número de timeouts por host
     * - Tasa de éxito/fracaso de las conexiones
     * - Historial de latencias
     *
     * @param string|null $host Filtro opcional para obtener estadísticas de un host específico.
     *                         Si es null, se devuelven las estadísticas globales.
     * @return array<string, mixed> Un array con las estadísticas que incluye:
     *         - 'total_requests': (int) Número total de peticiones
     *         - 'successful_requests': (int) Peticiones exitosas
     *         - 'timeout_errors': (int) Errores por timeout
     *         - 'avg_latency': (float) Latencia media en segundos
     *         - 'min_latency': (float) Latencia mínima registrada
     *         - 'max_latency': (float) Latencia máxima registrada
     *         - 'last_updated': (string) Fecha de última actualización
     *         - 'by_host': (array) Estadísticas desglosadas por host
     *
     * @since 1.0.0
     * @see SSLTimeoutManager::getStats() Para la implementación específica
     */
    public function getTimeoutStats(?string $host = null): array {
        if (!$this->timeout_manager) {
            return ['enabled' => false];
        }
        
        $latency_stats = $this->timeout_manager->getLatencyStats($host);
        
        $stats = [
            'enabled' => true,
            'latency' => $latency_stats,
            'configuration' => [
                'method_timeouts' => $this->timeout_manager->getTimeoutConfig('example.com'),
                'error_policies' => [
                    'connection_timeout' => $this->timeout_manager->getErrorPolicy('connection_timeout'),
                    'ssl_error' => $this->timeout_manager->getErrorPolicy('ssl_error'),
                    'server_error' => $this->timeout_manager->getErrorPolicy('server_error'),
                ],
            ],
        ];
        
        // Añadir historial de estadísticas si existe
        $stats_history = get_option('miapi_ssl_latency_stats_history', []);
        if (!empty($stats_history)) {
            $stats['history'] = [
                'days' => count($stats_history),
                'last_recorded' => end($stats_history)['date'],
                'summary' => [
                    'total_requests' => array_sum(array_column($stats_history, 'total_requests')),
                ],
            ];
        }
        
        return $stats;
    }
    
    /**
     * Limpia la caché de certificados SSL
     *
     * Este método permite limpiar la caché de certificados SSL, ya sea de forma selectiva
     * o completa. Es útil en escenarios como:
     * - Rotación de certificados
     * - Actualización de certificados revocados
     * - Resolución de problemas de certificados inválidos
     * - Liberación de memoria
     *
     * @param string|null $cert_url URL del certificado específico a eliminar de la caché.
     *                            Si es null, se limpia toda la caché de certificados.
     *                            El formato debe ser una URL válida (ej: 'https://api.ejemplo.com')
     *
     * @return bool True si la operación se completó con éxito, false en caso de error
     *              o si el sistema de caché no está inicializado.
     *
     * @since 1.0.0
     * @see CertificateCache::clearCache() Para la implementación específica
     * @see getCertificateCacheStats() Para ver estadísticas de la caché
     *
     * @example
     * // Limpiar un certificado específico
     * $result = $this->clearCertificateCache('https://api.ejemplo.com');
     *
     * // Limpiar toda la caché de certificados
     * $result = $this->clearCertificateCache();
     *
     * @throws \InvalidArgumentException Si la URL del certificado no tiene un formato válido
     */
    public function clearCertificateCache(?string $cert_url = null): bool {
        if (!$this->cert_cache) {
            return false;
        }
        
        return $this->cert_cache->clearCache($cert_url);
    }
    
    /**
     * Obtiene la configuración SSL actual o un valor de configuración específico
     *
     * Este método proporciona acceso a la configuración SSL actual, que puede incluir:
     * - Versiones de protocolo habilitadas
     * - Algoritmos de cifrado permitidos
     * - Configuración de verificación de certificados
     * - Opciones de rendimiento y seguridad
     *
     * @param string|null $key Clave específica de configuración a recuperar.
     *                       Si es null, devuelve todo el array de configuración.
     *                       Las claves comunes incluyen:
     *                       - 'verify_peer': (bool) Verificar certificados del peer
     *                       - 'verify_peer_name': (bool) Verificar nombre del peer
     *                       - 'cipher_list': (string) Lista de cifrados permitidos
     *                       - 'ssl_version': (string) Versión SSL/TLS
     *                       - 'allow_self_signed': (bool) Permitir certificados autofirmados
     *                       - 'ca_bundle_path': (string) Ruta al bundle CA
     *
     * @return mixed Si se especifica una clave, devuelve su valor.
     *              Si no se especifica clave, devuelve un array con toda la configuración.
     *              Devuelve null si la clave no existe o si el gestor no está inicializado.
     *
     * @since 1.0.0
     * @see SSLConfigManager::getOption() Para obtener una opción específica
     * @see SSLConfigManager::getAllOptions() Para obtener toda la configuración
     * @see https://www.php.net/manual/es/context.ssl.php Opciones de contexto SSL
     * @example
     * $ssl_config = $this->getSSLConfig();
     * $verify_peer = $this->getSSLConfig('verify_peer');
     */
    public function getSSLConfig(?string $key = null) {
        // Verificar que el gestor de configuración SSL esté inicializado
        if ($this->ssl_config_manager === null) {
            $this->logger?->warning('[SSL Advanced] SSLConfigManager no inicializado');
            return null;
        }

        try {
            // Si se especifica una clave, devolver solo ese valor
            if ($key !== null) {
                return $this->ssl_config_manager->getOption($key);
            }

            // Si no se especifica clave, devolver toda la configuración
            return $this->ssl_config_manager->getAllOptions();

        } catch (\Exception $e) {
            $this->logger?->error('[SSL Advanced] Error obteniendo configuración SSL: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Obtiene el estado actual del sistema de rotación automática de certificados
     *
     * Este método proporciona información detallada sobre el estado de la rotación
     * automática de certificados, incluyendo:
     * - Estado de activación del sistema
     * - Próxima rotación programada
     * - Historial de rotaciones
     * - Errores recientes
     * - Estadísticas de uso
     *
     * @return array<string, mixed> Un array asociativo con la información de estado que incluye:
     *         - 'enabled': (bool) Si la rotación automática está habilitada
     *         - 'last_rotation': (string|null) Fecha de la última rotación exitosa
     *         - 'next_rotation': (string) Próxima rotación programada
     *         - 'rotation_interval': (int) Intervalo de rotación en segundos
     *         - 'total_rotations': (int) Número total de rotaciones realizadas
     *         - 'last_error': (string|null) Mensaje del último error, si lo hubiera
     *         - 'error_count': (int) Número de errores en la última rotación
     *         - 'is_rotating': (bool) Si hay una rotación en curso
     *
     * @since 1.0.0
     * @see CertificateRotation::getStatus() Para la implementación específica
     */
    public function getCertificateRotationStatus(): array {
        if (!$this->cert_rotation) {
            return ['enabled' => false];
        }
        
        $status = $this->cert_rotation->getStatus();
        $status['enabled'] = true;
        
        return $status;
    }
    
    /**
     * Actualiza la configuración SSL avanzada
     * 
     * @param array $options Nuevas opciones de configuración
     * @return bool Resultado de la operación
     */
    public function updateSSLConfiguration(array $options): bool {
        if (!$this->ssl_config_manager) {
            return false;
        }
        
        foreach ($options as $key => $value) {
            $this->ssl_config_manager->setOption($key, $value);
        }
        
        return $this->ssl_config_manager->saveConfig();
    }
    
    /**
     * Actualiza la configuración del gestor de timeouts dinámicos
     *
     * Este método permite actualizar la configuración de timeouts para diferentes
     * aspectos del sistema, incluyendo:
     * - Timeouts específicos por método HTTP (GET, POST, etc.)
     * - Timeouts personalizados por host o dominio
     * - Políticas de manejo de errores
     * - Umbrales de latencia para ajuste automático
     *
     * @param array<string, mixed> $timeout_config Array de configuración con las siguientes claves opcionales:
     *        - 'method_timeouts': (array) Timeouts específicos por método HTTP
     *            - Ejemplo: ['GET' => 30, 'POST' => 60]
     *        - 'timeout_hosts': (array) Configuración de timeouts por host
     *            - Ejemplo: ['api.ejemplo.com' => ['initial' => 5, 'max' => 30]]
     *        - 'error_policies': (array) Políticas de manejo de errores
     *            - Ejemplo: ['timeout' => ['retry_count' => 3, 'backoff' => 'exponential']]
     *        - 'latency_thresholds': (array) Umbrales para ajuste automático
     *
     * @return bool True si la configuración se actualizó correctamente, false en caso de error
     *              o si el gestor de timeouts no está inicializado.
     *
     * @since 1.0.0
     * @throws \InvalidArgumentException Si la configuración proporcionada no es válida
     * @see SSLTimeoutManager::setMethodTimeout() Para configurar timeouts por método
     * @see SSLTimeoutManager::setHostTimeout() Para configurar timeouts por host
     * @see SSLTimeoutManager::setErrorPolicy() Para configurar políticas de error
     *
     * @example
     * $config = [
     *     'method_timeouts' => [
     *         'GET' => 30,
     *         'POST' => 60
     *     ],
     *     'timeout_hosts' => [
     *         'api.ejemplo.com' => [
     *             'initial' => 5,
     *             'max' => 30
     *         ]
     *     ]
     * ];
     * $result = $this->updateTimeoutConfiguration($config);
     */
    public function updateTimeoutConfiguration(array $timeout_config): bool {
        if (!$this->timeout_manager) {
            return false;
        }
        
        // Actualizar configuración por método HTTP si está presente
        if (isset($timeout_config['method_timeouts']) && is_array($timeout_config['method_timeouts'])) {
            foreach ($timeout_config['method_timeouts'] as $method => $timeout) {
                $this->timeout_manager->setMethodTimeout($method, $timeout);
            }
        }
        
        // Actualizar configuración por host si está presente
        if (isset($timeout_config['timeout_hosts']) && is_array($timeout_config['timeout_hosts'])) {
            foreach ($timeout_config['timeout_hosts'] as $host => $host_config) {
                $this->timeout_manager->setHostTimeout($host, $host_config);
            }
        }
        
        // Actualizar políticas de error si están presentes
        if (isset($timeout_config['error_policies']) && is_array($timeout_config['error_policies'])) {
            foreach ($timeout_config['error_policies'] as $error_type => $policy) {
                $this->timeout_manager->setErrorPolicy($error_type, $policy);
            }
        }
        
        // Guardar la configuración
        $this->timeout_manager->saveConfig();
        
        return true;
    }
    
    /**
     * Determina si el entorno actual es de desarrollo local
     *
     * Este método verifica múltiples indicadores para determinar si el código
     * se está ejecutando en un entorno de desarrollo local, incluyendo:
     * - Variables de entorno (WP_ENV, WP_DEBUG, WP_ENVIRONMENT_TYPE)
     * - Nombres de dominio comunes de desarrollo (localhost, .local, .test, .localdomain)
     * - Direcciones IP locales (127.0.0.1, ::1)
     * - Presencia de archivos típicos de desarrollo (wp-config-local.php, etc.)
     *
     * @return bool True si se detecta un entorno de desarrollo local, false en caso contrario.
     *
     * @since 1.0.0
     * @see wp_get_environment_type() Función de WordPress para obtener el tipo de entorno
     * @see defined() Para verificar constantes definidas
     * @see $_SERVER['SERVER_NAME'] Para obtener el nombre del servidor
     *
     * @example
     * if ($this->isLocalDevelopment()) {
     *     // Código que solo se ejecuta en desarrollo local
     *     $this->disableSSLCertificateVerification();
     * }
     */
    private function isLocalDevelopment(): bool {
        // Verificar variables de entorno
        if (defined('WP_ENV') && WP_ENV === 'development') {
            return true;
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
    }

    /**
     * Asegura que la configuración SSL esté correctamente inicializada
     *
     * Este método verifica y asegura que todos los componentes necesarios para
     * la gestión SSL estén correctamente inicializados. Se encarga de:
     * - Verificar la inicialización del gestor de configuración SSL
     * - Cargar configuraciones por defecto si es necesario
     * - Registrar manejadores de errores para operaciones SSL
     * - Configurar el contexto SSL global si es necesario
     *
     * @return void
     *
     * @since 1.0.0
     * @throws \RuntimeException Si no se puede inicializar la configuración SSL
     * @see SSLConfigManager::initialize() Para la inicialización del gestor de configuración
     * @see set_error_handler() Para el manejo de errores personalizado
     */
    private function ensureSslConfiguration(): void {
        if (!$this->ssl_config_manager) {
            $this->logger->error('[SSL] El gestor de configuración SSL no está inicializado');
            return;
        }

        // Verificar y actualizar la configuración SSL
        $config = $this->ssl_config_manager->getAllOptions();
        
        // Verificar que tenemos un CA bundle válido
        if ($config['verify_peer']) {
            $ca_bundle_path = $config['ca_bundle_path'] ?? '';
            
            if (empty($ca_bundle_path)) {
                $ca_bundle_path = $this->findCaBundlePath();
                if ($ca_bundle_path) {
                    $this->ssl_config_manager->setOption('ca_bundle_path', $ca_bundle_path);
                    $this->ssl_config_manager->saveConfig();
                }
            }
            
            if (empty($ca_bundle_path) || !file_exists($ca_bundle_path)) {
                $this->logger->warning('[SSL] No se encontró un CA bundle válido');
            }
        }
        
        // Verificar y actualizar timeouts si es necesario
        if ($this->timeout_manager) {
            $timeout_config = $this->timeout_manager->getConfiguration();
            if (empty($timeout_config)) {
                $this->timeout_manager->updateConfiguration([
                    'default_timeout' => 30,
                    'max_timeout' => 60,
                    'retry_count' => 3,
                    'retry_delay' => 1
                ]);
            }
        }
        
        // Verificar caché de certificados
        if ($this->cert_cache) {
            $this->cert_cache->validateCache();
        }
        
        $this->logger->info('[SSL] Configuración SSL verificada y actualizada');
    }
}
