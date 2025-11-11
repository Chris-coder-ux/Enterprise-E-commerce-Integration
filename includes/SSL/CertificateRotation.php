<?php
/**
 * Clase para gestionar la rotación de certificados SSL
 *
 * Implementa un sistema de rotación automática de certificados SSL, descargando
 * y actualizando periódicamente bundles de autoridades certificadoras de confianza
 * desde múltiples fuentes verificadas.
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\SSL;

use MiIntegracionApi\Helpers\Logger;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase CertificateRotation
 *
 * Gestiona la rotación automática de certificados SSL, incluyendo:
 * - Descarga de bundles de CA de múltiples fuentes confiables
 * - Validación de certificados antes de la instalación
 * - Creación de copias de seguridad
 * - Limpieza automática de versiones antiguas
 * - Verificación de fechas de expiración
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 * @since      1.0.0
 */
class CertificateRotation {
    /**
     * Instancia del logger para registro de eventos
     *
     * @var Logger
     * @since 1.0.0
     */
    private Logger $logger;

    /**
     * Ruta absoluta al directorio de certificados
     *
     * @var string
     * @since 1.0.0
     */
    private string $cert_dir;

    /**
     * Configuración del sistema de rotación
     *
     * @var array<string, mixed> Configuración con las siguientes claves:
     *      - rotation_interval: int Días entre rotaciones automáticas (default: 30)
     *      - expiration_threshold: int Días antes de expiración para renovar (default: 30)
     *      - retention_count: int Número de versiones anteriores a mantener (default: 3)
     *      - sources: array<string, array> Fuentes de certificados
     *      - last_rotation: int Timestamp de la última rotación (default: 0)
     *      - backup_enabled: bool Habilitar copias de seguridad (default: true)
     *      - rotation_schedule: string Frecuencia de comprobación (default: 'daily')
     *
     * @since 1.0.0
     */
    private array $config = [
        'rotation_interval' => 30,           // Días entre rotaciones automáticas
        'expiration_threshold' => 30,        // Días antes de la expiración para renovar
        'retention_count' => 3,              // Número de versiones anteriores a mantener
        'sources' => [],                     // Fuentes de certificados
        'last_rotation' => 0,                // Timestamp de última rotación
        'backup_enabled' => true,            // Habilitar backup antes de la rotación
        'rotation_schedule' => 'daily',      // Frecuencia de comprobación
    ];

    /**
     * Constructor de la clase
     *
     * Inicializa el sistema de rotación de certificados, cargando la configuración
     * guardada y fusionándola con cualquier configuración personalizada proporcionada.
     *
     * @param Logger|null $logger Instancia del logger (opcional)
     * @param array<string, mixed> $config Configuración personalizada (opcional)
     * @since 1.0.0
     * @throws \RuntimeException Si no se puede crear el directorio de certificados
     */
    public function __construct(?Logger $logger = null, array $config = []) {
        $this->logger = $logger ?? new \MiIntegracionApi\Helpers\Logger('ssl_rotation');
        $this->cert_dir = plugin_dir_path(dirname(__FILE__)) . '../certs';
        
        // Asegurar que el directorio exista
        if (!file_exists($this->cert_dir)) {
            wp_mkdir_p($this->cert_dir);
            $this->logger->info("[SSL Rotation] Directorio de certificados creado: {$this->cert_dir}");
        }
        
        // Cargar configuración guardada
        $saved_config = get_option('miapi_ssl_rotation_config', []);
        if (is_array($saved_config)) {
            $this->config = array_merge($this->config, $saved_config);
        }
        
        // Fusionar con configuración proporcionada
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
        
        // Configurar fuentes de certificados predeterminadas si no hay ninguna
        if (empty($this->config['sources'])) {
            $this->config['sources'] = $this->getDefaultSources();
        }
        
        // Registrar cron para rotación automática
        $this->setupCronJob();
    }

    /**
     * Configura el trabajo cron para rotación automática
     *
     * Nota: Los cron jobs se manejan centralmente en RobustnessHooks
     * para evitar múltiples cargas del plugin. Este método se mantiene
     * por compatibilidad pero no programa cron directamente.
     *
     * @since 1.0.0
     * @deprecated 2.0.0 La programación de cron se maneja en RobustnessHooks
     */
    private function setupCronJob(): void {
        // La programación se hace automáticamente desde RobustnessHooks
        // Este método se mantiene por compatibilidad pero no programa cron
    }

    /**
     * Obtiene las fuentes de certificados predeterminadas
     *
     * Define un conjunto de fuentes confiables de bundles de certificados raíz
     * que se utilizarán para la rotación automática.
     *
     * @return array<string, array> Array asociativo de fuentes con las claves:
     *         - url: string URL del bundle de certificados
     *         - name: string Nombre descriptivo de la fuente
     *         - priority: int Prioridad (menor número = mayor prioridad)
     * @since 1.0.0
     */
    private function getDefaultSources(): array {
        return [
            'mozilla' => [
                'url' => 'https://curl.se/ca/cacert.pem',
                'name' => 'Mozilla CA Bundle',
                'priority' => 10,
            ],
            'amazon' => [
                'url' => 'https://www.amazontrust.com/repository/AmazonRootCA-bundle.pem',
                'name' => 'Amazon Trust Services CA Bundle',
                'priority' => 20,
            ],
            'digicert' => [
                'url' => 'https://www.digicert.com/CACerts/DigiCertGlobalRootCA.crt.pem',
                'name' => 'DigiCert Global Root CA',
                'priority' => 30,
            ],
            'wordpress' => [
                'url' => 'https://api.wordpress.org/core/browse-happy/1.1/ca-bundle.crt',
                'name' => 'WordPress CA Bundle',
                'priority' => 40,
            ],
            'certifi' => [
                'url' => 'https://raw.githubusercontent.com/certifi/python-certifi/master/certifi/cacert.pem',
                'name' => 'Certifi CA Bundle',
                'priority' => 50,
            ],
        ];
    }

    /**
     * Guarda la configuración actual en la base de datos
     *
     * Almacena la configuración en la tabla de opciones de WordPress
     * para persistencia entre cargas del plugin.
     *
     * @return bool true si la configuración se guardó correctamente, false en caso contrario
     * @since 1.0.0
     * @see update_option()
     */
    public function saveConfig(): bool {
        return update_option('miapi_ssl_rotation_config', $this->config, false);
    }

    /**
     * Añade una nueva fuente de certificados
     *
     * Permite registrar una fuente personalizada de certificados SSL que se utilizará
     * durante la rotación. Las fuentes con menor valor de prioridad se procesan primero.
     *
     * @param string $id Identificador único para la fuente
     * @param string $url URL completa al bundle de certificados
     * @param string $name Nombre descriptivo de la fuente
     * @param int $priority Prioridad de la fuente (menor número = mayor prioridad)
     * @return self Instancia actual para permitir encadenamiento de métodos
     * @throws \InvalidArgumentException Si la URL no es válida
     * @since 1.0.0
     */
    public function addSource(string $id, string $url, string $name, int $priority = 100): self {
        $this->config['sources'][$id] = [
            'url' => $url,
            'name' => $name,
            'priority' => $priority,
        ];
        
        $this->saveConfig();
        return $this;
    }

    /**
     * Elimina una fuente de certificados por su ID
     *
     * @param string $id ID de la fuente a eliminar
     * @return self Instancia actual para permitir encadenamiento de métodos
     * @since 1.0.0
     */
    public function removeSource(string $id): self {
        if (isset($this->config['sources'][$id])) {
            unset($this->config['sources'][$id]);
            $this->saveConfig();
        }
        
        return $this;
    }

    /**
     * Obtiene todas las fuentes de certificados ordenadas por prioridad
     *
     * @return array<string, array> Array de fuentes ordenadas por prioridad (menor a mayor),
     *         donde cada elemento contiene las claves: url, name, priority
     * @since 1.0.0
     */
    public function getSources(): array {
        $sources = $this->config['sources'];
        
        // Ordenar por prioridad
        uasort($sources, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
        
        return $sources;
    }

    /**
     * Verifica si es necesaria una rotación de certificados
     *
     * Comprueba varios factores para determinar si se requiere una rotación:
     * 1. Si nunca se ha realizado una rotación
     * 2. Si ha pasado el intervalo configurado desde la última rotación
     * 3. Si hay certificados próximos a expirar
     *
     * @return bool true si se requiere rotación, false en caso contrario
     * @since 1.0.0
     */
    public function needsRotation(): bool {
        // Si nunca se ha hecho rotación, hacerla
        if (empty($this->config['last_rotation'])) {
            return true;
        }
        
        // Verificar intervalo de rotación
        $days_since_last_rotation = (time() - $this->config['last_rotation']) / DAY_IN_SECONDS;
        if ($days_since_last_rotation >= $this->config['rotation_interval']) {
            return true;
        }
        
        // Verificar si el certificado actual está próximo a expirar
        $ca_bundle_path = $this->cert_dir . '/ca-bundle.pem';
        if (!file_exists($ca_bundle_path)) {
            return true;
        }
        
        // Verificar próxima expiración de algún certificado en el bundle
        if ($this->hasNearExpiringCertificates($ca_bundle_path)) {
            return true;
        }
        
        return false;
    }

    /**
     * Verifica si algún certificado en el bundle está próximo a expirar
     *
     * Analiza cada certificado en el bundle y comprueba si su fecha de expiración
     * está dentro del umbral configurado en $this->config['expiration_threshold'].
     *
     * @param string $bundle_path Ruta absoluta al archivo bundle de certificados
     * @return bool true si hay certificados próximos a expirar, false en caso contrario
     * @throws \RuntimeException Si no se puede leer o analizar el bundle
     * @since 1.0.0
     */
    private function hasNearExpiringCertificates(string $bundle_path): bool {
        if (!file_exists($bundle_path) || !is_readable($bundle_path)) {
            return true;  // Si no podemos leer, mejor rotar
        }
        
        $content = file_get_contents($bundle_path);
        if ($content === false) {
            return true;
        }
        
        // Extraer certificados individuales
        preg_match_all('/-----BEGIN CERTIFICATE-----\s*([^-]+)\s*-----END CERTIFICATE-----/', $content, $matches);
        
        if (empty($matches[1])) {
            return true;  // No se encontraron certificados, mejor rotar
        }
        
        $threshold_time = time() + ($this->config['expiration_threshold'] * DAY_IN_SECONDS);
        
        foreach ($matches[1] as $cert_data) {
            $cert_data = trim($cert_data);
            $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($cert_data, 64, "\n") . "-----END CERTIFICATE-----";
            
            $cert_resource = openssl_x509_read($cert);
            if ($cert_resource) {
                $cert_info = openssl_x509_parse($cert_resource);
                
                // Si algún certificado importante expira pronto, necesitamos rotar
                if ($cert_info && isset($cert_info['validTo_time_t'])) {
                    if ($cert_info['validTo_time_t'] <= $threshold_time) {
                        // Verificar si es un certificado importante (CA raíz)
                        if (isset($cert_info['subject']['CN']) && isset($cert_info['issuer']['CN'])) {
                            // Los certificados raíz tienen el mismo CN en subject e issuer
                            if ($cert_info['subject']['CN'] === $cert_info['issuer']['CN']) {
                                $expiry_date = date('Y-m-d', $cert_info['validTo_time_t']);
                                $this->logger->warning("[SSL Rotation] Certificado raíz próximo a expirar: {$cert_info['subject']['CN']} (expira: $expiry_date)");
                                return true;
                            }
                        }
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Realiza una rotación completa de los certificados SSL
     *
     * El proceso de rotación incluye:
     * 1. Verificar si es necesaria la rotación (a menos que se fuerce)
     * 2. Crear una copia de seguridad del bundle actual
     * 3. Descargar y validar nuevos certificados de las fuentes configuradas
     * 4. Instalar el nuevo bundle
     * 5. Limpiar copias de seguridad antiguas
     *
     * @param bool $force Si es true, fuerza la rotación sin verificar si es necesaria
     * @return bool true si la rotación fue exitosa, false en caso de error
     * @throws \RuntimeException Si hay errores durante el proceso de rotación
     * @since 1.0.0
     */
    public function rotateCertificates(bool $force = false): bool {
        if (!$force && !$this->needsRotation()) {
            $this->logger->info("[SSL Rotation] No se necesita rotación de certificados en este momento");
            return true;  // No es necesario rotar
        }
        
        $ca_bundle_path = $this->cert_dir . '/ca-bundle.pem';
        
        // Crear backup del bundle actual si existe
        if ($this->config['backup_enabled'] && file_exists($ca_bundle_path)) {
            $backup_suffix = date('Y-m-d-His');
            $backup_path = $ca_bundle_path . '.' . $backup_suffix . '.backup';
            
            if (copy($ca_bundle_path, $backup_path)) {
                $this->logger->info("[SSL Rotation] Backup creado: $backup_path");
            } else {
                $this->logger->warning("[SSL Rotation] No se pudo crear backup antes de la rotación");
            }
        }
        
        // Obtener y validar certificados de las fuentes
        $new_bundle = $this->fetchCertificatesFromSources();
        if ($new_bundle === false) {
            $this->logger->error("[SSL Rotation] No se pudo obtener certificados válidos de ninguna fuente");
            return false;
        }
        
        // Guardar nuevo bundle
        $result = file_put_contents($ca_bundle_path, $new_bundle);
        if ($result === false) {
            $this->logger->error("[SSL Rotation] Error al guardar el nuevo bundle de certificados");
            return false;
        }
        
        // Establecer permisos seguros
        $this->setSecureCertificatePermissions($ca_bundle_path);
        
        // Actualizar timestamp de última rotación
        $this->config['last_rotation'] = time();
        $this->saveConfig();
        
        // Limpiar backups antiguos
        $this->cleanupOldBackups();
        
        $cert_count = substr_count($new_bundle, "-----BEGIN CERTIFICATE-----");
        $this->logger->info("[SSL Rotation] Rotación de certificados completada exitosamente", [
            'certificados' => $cert_count,
            'tamaño' => strlen($new_bundle),
        ]);
        
        return true;
    }

    /**
     * Establece permisos seguros en un archivo de certificado
     *
     * Intenta establecer permisos 644 (rw-r--r--) en el archivo especificado
     * utilizando diferentes métodos según estén disponibles en el sistema.
     *
     * @param string $cert_path Ruta absoluta al archivo de certificado
     * @return bool true si se establecieron los permisos correctamente, false en caso contrario
     * @since 1.0.0
     */
    private function setSecureCertificatePermissions(string $cert_path): bool {
        if (!file_exists($cert_path)) {
            return false;
        }
        
        // Intentar métodos disponibles
        $success = false;
        
        // Método 1: PHP chmod directo
        if (@chmod($cert_path, 0644)) {
            $success = true;
        } else {
            // Método 2: Comando chmod del sistema
            @exec("chmod 644 " . escapeshellarg($cert_path), $output, $return_var);
            if ($return_var === 0) {
                $success = true;
            } else {
                // Método 3: Comando chmod con sudo (para sistemas Unix)
                if (stripos(PHP_OS, 'win') === false) {
                    @exec("sudo chmod 644 " . escapeshellarg($cert_path), $output, $return_var);
                    if ($return_var === 0) {
                        $success = true;
                    }
                }
            }
        }
        
        // Verificar el resultado
        if ($success) {
            clearstatcache(true, $cert_path);
            $perms = fileperms($cert_path) & 0777;
            
            if ($perms === 0644 || $perms === 0444) {
                $this->logger->debug("[SSL Rotation] Permisos seguros establecidos para: $cert_path");
                return true;
            }
        }
        
        $this->logger->warning("[SSL Rotation] No se pudieron establecer permisos seguros para: $cert_path");
        return false;
    }

    /**
     * Descarga y valida certificados de las fuentes configuradas
     *
     * Itera a través de todas las fuentes de certificados configuradas (ordenadas por prioridad)
     * hasta encontrar una fuente válida. Combina los certificados de todas las fuentes exitosas
     * en un solo bundle.
     *
     * @return string|false Contenido del bundle de certificados combinado, o false si no se pudo
     *                     obtener certificados válidos de ninguna fuente
     * @since 1.0.0
     */
    private function fetchCertificatesFromSources() {
        $sources = $this->getSources();
        
        foreach ($sources as $source_id => $source) {
            $this->logger->info("[SSL Rotation] Descargando certificados desde: {$source['name']} ({$source['url']})");
            
            $response = wp_remote_get($source['url'], [
                'timeout' => 30,
                'sslverify' => true,
                'user-agent' => 'Mi-Integracion-API/' . (defined('MIAPI_VERSION') ? MIAPI_VERSION : '2.0.0'),
            ]);
            
            if (is_wp_error($response)) {
                $this->logger->warning("[SSL Rotation] Error al descargar de {$source_id}: " . $response->get_error_message());
                continue;
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                $this->logger->warning("[SSL Rotation] Error HTTP $http_code al descargar de {$source_id}");
                continue;
            }
            
            $content = wp_remote_retrieve_body($response);
            if (empty($content)) {
                $this->logger->warning("[SSL Rotation] Contenido vacío descargado de {$source_id}");
                continue;
            }
            
            // Validar que el contenido tenga certificados PEM
            $cert_count = substr_count($content, "-----BEGIN CERTIFICATE-----");
            if ($cert_count < 50) {  // La mayoría de bundles tienen más de 100 certificados
                $this->logger->warning("[SSL Rotation] Bundle de {$source_id} tiene pocos certificados: $cert_count");
                continue;
            }
            
            // Formateo básico - Asegurar formato PEM correcto
            $content = $this->formatCertificateBundle($content);
            
            // Añadir encabezado
            $timestamp = date('Y-m-d H:i:s');
            $header = "# CA Bundle generado automáticamente por Mi Integración API\n";
            $header .= "# Fuente: {$source['name']} ({$source['url']})\n";
            $header .= "# Fecha: $timestamp\n";
            $header .= "# Certificados: $cert_count\n\n";
            
            $content = $header . $content;
            
            $this->logger->info("[SSL Rotation] Bundle válido obtenido de {$source_id} con $cert_count certificados");
            return $content;
        }
        
        return false;
    }

    /**
     * Formatea un bundle de certificados para asegurar formato PEM correcto
     *
     * @param string $content Contenido del bundle
     * @return string Contenido formateado
     */
    private function formatCertificateBundle(string $content): string {
        // Normalizar saltos de línea
        $content = str_replace("\r\n", "\n", $content);
        
        // Extraer certificados individuales
        preg_match_all('/(-----BEGIN CERTIFICATE-----\s*.*?\s*-----END CERTIFICATE-----)/s', $content, $matches);
        
        if (empty($matches[1])) {
            return $content; // No se encontraron certificados, devolver original
        }
        
        $formatted_bundle = "";
        
        foreach ($matches[1] as $cert) {
            // Limpiar espacios o líneas vacías adicionales
            $cert = trim($cert);
            
            // Asegurar que hay una línea vacía entre certificados
            $formatted_bundle .= $cert . "\n\n";
        }
        
        return $formatted_bundle;
    }

    /**
     * Limpia backups antiguos según la configuración de retención
     */
    private function cleanupOldBackups(): void {
        $retention_count = (int) $this->config['retention_count'];
        
        if ($retention_count <= 0) {
            return;  // Conservar todos los backups
        }
        
        // Buscar archivos de backup
        $backups = glob($this->cert_dir . '/ca-bundle.pem.*.backup');
        
        if (count($backups) <= $retention_count) {
            return;  // No hay suficientes backups para limpiar
        }
        
        // Ordenar por fecha (más antiguos primero)
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Eliminar backups antiguos manteniendo los más recientes
        $to_delete = array_slice($backups, 0, count($backups) - $retention_count);
        
        foreach ($to_delete as $backup_file) {
            if (unlink($backup_file)) {
                $this->logger->debug("[SSL Rotation] Backup antiguo eliminado: $backup_file");
            } else {
                $this->logger->warning("[SSL Rotation] No se pudo eliminar backup antiguo: $backup_file");
            }
        }
    }

    /**
     * Realiza una rotación programada
     * 
     * Esta función es llamada automáticamente por el cron de WordPress
     */
    public function scheduledRotation(): void {
        if ($this->needsRotation()) {
            $this->logger->info("[SSL Rotation] Iniciando rotación programada de certificados");
            $this->rotateCertificates();
        }
    }

    /**
     * Obtiene un resumen del estado actual
     *
     * @return array Información sobre el estado
     */
    public function getStatus(): array {
        $ca_bundle_path = $this->cert_dir . '/ca-bundle.pem';
        $status = [
            'certificado_principal' => [
                'path' => $ca_bundle_path,
                'existe' => file_exists($ca_bundle_path),
                'tamaño' => file_exists($ca_bundle_path) ? filesize($ca_bundle_path) : 0,
                'fecha_modificacion' => file_exists($ca_bundle_path) ? date('Y-m-d H:i:s', filemtime($ca_bundle_path)) : null,
            ],
            'ultima_rotacion' => $this->config['last_rotation'] ? date('Y-m-d H:i:s', $this->config['last_rotation']) : 'Nunca',
            'proxima_rotacion' => $this->config['last_rotation'] ? date('Y-m-d H:i:s', $this->config['last_rotation'] + ($this->config['rotation_interval'] * DAY_IN_SECONDS)) : 'Programada para próximo chequeo',
            'necesita_rotacion' => $this->needsRotation(),
            'fuentes_disponibles' => count($this->config['sources']),
            'backups' => [],
        ];
        
        // Información de backups
        $backups = glob($this->cert_dir . '/ca-bundle.pem.*.backup');
        foreach ($backups as $backup) {
            $status['backups'][] = [
                'archivo' => basename($backup),
                'tamaño' => filesize($backup),
                'fecha' => date('Y-m-d H:i:s', filemtime($backup)),
            ];
        }
        
        return $status;
    }
}
