<?php
/**
 * Clase para gestionar la caché de certificados SSL
 *
 * Esta clase implementa un sistema de caché de dos niveles (en memoria y en disco)
 * para almacenar certificados SSL y optimizar el rendimiento de las conexiones seguras.
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\SSL;

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\CacheManager;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase CertificateCache
 *
 * Proporciona un sistema de caché de dos niveles para certificados SSL:
 * 1. Caché en memoria (usando CacheManager)
 * 2. Caché en disco (archivos .pem en directorio de caché)
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 * @since      1.0.0
 */
class CertificateCache {
    /**
     * Instancia del logger para registro de eventos
     *
     * @var Logger
     * @since 1.0.0
     */
    private Logger $logger;

    /**
     * Instancia del gestor de caché en memoria
     *
     * @var CacheManager
     * @since 1.0.0
     */
    private CacheManager $cache;

    /**
     * Tiempo de vida de la caché en segundos (por defecto 12 horas)
     *
     * @var int
     * @since 1.0.0
     */
    private int $cache_ttl = 43200;

    /**
     * Ruta absoluta al directorio de caché de certificados
     *
     * @var string
     * @since 1.0.0
     */
    private string $cache_dir;

    /**
     * Constructor de la clase
     *
     * Inicializa el sistema de caché y asegura que el directorio de caché exista.
     *
     * @param Logger|null $logger Instancia del logger (opcional)
     * @param CacheManager|null $cache_manager Instancia del gestor de caché (opcional)
     * @since 1.0.0
     */
    public function __construct(?Logger $logger = null, ?CacheManager $cache_manager = null) {
        $this->logger = $logger ?? new \MiIntegracionApi\Helpers\Logger('ssl_cache');
        $this->cache = $cache_manager ?? CacheManager::get_instance();
        $this->cache_dir = plugin_dir_path(dirname(__FILE__)) . '../certs/cache';
        
        // Asegurar que el directorio de caché exista con los permisos adecuados
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
            // Establecer permisos seguros para el directorio
            @chmod($this->cache_dir, 0755);
        }
    }

    /**
     * Establece el tiempo de vida de la caché en segundos
     *
     * @param int $ttl Tiempo en segundos que los certificados permanecerán en caché
     * @return self Instancia actual para permitir encadenamiento de métodos
     * @throws \InvalidArgumentException Si el TTL no es un número positivo
     * @since 1.0.0
     */
    public function setCacheTTL(int $ttl): self {
        if ($ttl <= 0) {
            throw new \InvalidArgumentException('El TTL debe ser un número positivo');
        }
        $this->cache_ttl = $ttl;
        $this->logger->debug("[SSL Cache] Tiempo de vida de caché actualizado a {$ttl} segundos");
        return $this;
    }

    /**
     * Obtiene el contenido de un certificado, usando caché si está disponible
     *
     * La caché se verifica en este orden:
     * 1. Caché en memoria (más rápida)
     * 2. Caché en disco (más lenta pero persistente)
     * 3. Carga directa desde la fuente (más lenta, sin caché)
     *
     * @param string $cert_url URL o ruta del certificado
     * @param bool $force_refresh Si es true, ignora la caché y recarga el certificado
     * @return string|false Contenido del certificado en formato PEM o false en caso de error
     * @throws \RuntimeException Si no se puede acceder al directorio de caché
     * @since 1.0.0
     */
    public function getCertificate(string $cert_url, bool $force_refresh = false) {
        $cache_key = 'cert_' . md5($cert_url);
        $file_cache_path = $this->cache_dir . '/' . $cache_key . '.pem';
        
        // Verificar caché en memoria primero (más rápida)
        if (!$force_refresh) {
            $cached_content = $this->cache->get($cache_key);
            if ($cached_content !== false) {
                $this->logger->debug("[SSL Cache] Certificado cargado desde memoria: $cert_url");
                return $cached_content;
            }
            
            // Verificar caché en disco
            if (file_exists($file_cache_path) && is_readable($file_cache_path)) {
                $file_age = time() - filemtime($file_cache_path);
                
                if ($file_age < $this->cache_ttl) {
                    $content = file_get_contents($file_cache_path);
                    if ($content !== false) {
                        // Actualizar caché en memoria
                        $this->cache->set($cache_key, $content, $this->cache_ttl);
                        $this->logger->debug("[SSL Cache] Certificado cargado desde disco: $cert_url");
                        return $content;
                    }
                }
            }
        }
        
        // Si no hay caché o se fuerza refresco, descargar/cargar el certificado
        $content = $this->loadCertificate($cert_url);
        
        if ($content) {
            // Guardar en caché de memoria
            $this->cache->set($cache_key, $content, $this->cache_ttl);
            
            // Guardar en caché de disco
            file_put_contents($file_cache_path, $content);
            
            $this->logger->debug("[SSL Cache] Certificado cargado y guardado en caché: $cert_url");
        }
        
        return $content;
    }
    
    /**
     * Carga un certificado desde una URL o ruta local
     *
     * @param string $cert_url URL o ruta del sistema de archivos del certificado
     * @return string|false Contenido del certificado en formato PEM o false si hay error
     * @throws \RuntimeException Si la URL/ruta no es válida o el certificado no se puede cargar
     * @since 1.0.0
     */
    private function loadCertificate(string $cert_url) {
        // Si es una ruta local
        if (file_exists($cert_url) && is_readable($cert_url)) {
            return file_get_contents($cert_url);
        }
        
        // Si es una URL
        if (filter_var($cert_url, FILTER_VALIDATE_URL)) {
            $response = wp_remote_get($cert_url, [
                'timeout' => 15,
                'sslverify' => true,
                'user-agent' => 'Mi-Integracion-API/' . (defined('MIAPI_VERSION') ? MIAPI_VERSION : '2.0.0'),
            ]);
            
            if (is_wp_error($response)) {
                $this->logger->error("[SSL Cache] Error descargando certificado: " . $response->get_error_message());
                return false;
            }
            
            if (wp_remote_retrieve_response_code($response) !== 200) {
                $this->logger->error("[SSL Cache] Error HTTP al descargar certificado");
                return false;
            }
            
            return wp_remote_retrieve_body($response);
        }
        
        $this->logger->error("[SSL Cache] URL/ruta de certificado inválida: $cert_url");
        return false;
    }
    
    /**
     * Limpia la caché de certificados
     *
     * Puede limpiar un certificado específico o toda la caché según el parámetro.
     *
     * @param string|null $cert_url URL específica del certificado o null para limpiar toda la caché
     * @return bool true si la operación fue exitosa, false en caso contrario
     * @throws \RuntimeException Si hay problemas para eliminar archivos de caché
     * @since 1.0.0
     */
    public function clearCache(?string $cert_url = null): bool {
        if ($cert_url) {
            $cache_key = 'cert_' . md5($cert_url);
            $file_cache_path = $this->cache_dir . '/' . $cache_key . '.pem';
            
            // Limpiar caché en memoria
            $this->cache->delete($cache_key);
            
            // Limpiar caché en disco
            if (file_exists($file_cache_path)) {
                return unlink($file_cache_path);
            }
            
            return true;
        } else {
            // Limpiar toda la caché
            $this->cache->flush_group('ssl_certs');
            
            // Limpiar archivos de caché
            $files = glob($this->cache_dir . '/*.pem');
            $success = true;
            
            foreach ($files as $file) {
                if (!unlink($file)) {
                    $success = false;
                }
            }
            
            $this->logger->debug("[SSL Cache] Caché de certificados limpiada");
            return $success;
        }
    }
    
    /**
     * Obtiene estadísticas detalladas de la caché
     *
     * @return array<string, mixed> Array con las siguientes claves:
     *         - count: Número total de certificados en caché
     *         - total_size: Tamaño total en bytes de la caché
     *         - oldest: Fecha del certificado más antiguo (formato Y-m-d H:i:s)
     *         - newest: Fecha del certificado más reciente (formato Y-m-d H:i:s)
     *         - directory: Ruta del directorio de caché
     * @since 1.0.0
     */
    public function getCacheStats(): array {
        $files = glob($this->cache_dir . '/*.pem');
        $total_size = 0;
        $oldest = time();
        $newest = 0;
        
        foreach ($files as $file) {
            $total_size += filesize($file);
            $mtime = filemtime($file);
            $oldest = min($oldest, $mtime);
            $newest = max($newest, $mtime);
        }
        
        return [
            'count' => count($files),
            'total_size' => $total_size,
            'oldest' => $oldest ? date('Y-m-d H:i:s', $oldest) : null,
            'newest' => $newest ? date('Y-m-d H:i:s', $newest) : null,
            'directory' => $this->cache_dir,
        ];
    }

    /**
     * Valida la integridad de la caché de certificados
     *
     * Este método verifica que los certificados en caché sean válidos y no estén corruptos.
     * Actualmente es un método esqueleto para implementación futura.
     *
     * @return bool Siempre devuelve true en la implementación actual
     * @todo Implementar validación real de certificados
     * @since 1.0.0
     */
    public function validateCache(): bool {
        if (property_exists($this, 'logger') && $this->logger) {
            $this->logger->debug('[CertificateCache] validateCache() ejecutado');
        }
        // Aquí se podría agregar lógica de validación real en el futuro
        return true;
    }
}
