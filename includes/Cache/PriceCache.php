<?php declare(strict_types=1);
/**
 * Cache inteligente para precios de productos de Verial
 *
 * @since 1.4.1
 * @package MiIntegracionApi
 * @subpackage Cache
 */

namespace MiIntegracionApi\Cache;

use MiIntegracionApi\Helpers\Logger;

/**
 * Clase para manejar el cache persistente de precios de productos
 * 
 * Esta clase proporciona un sistema de cache inteligente que:
 * - Reduce llamadas a la API de Verial para precios
 * - Implementa TTL configurable por tipo de producto
 * - Maneja invalidación selectiva y limpieza automática
 * - Mantiene integridad de datos con validación
 */
class PriceCache {
    
    /**
     * Nombre de la tabla en la base de datos
     */
    const TABLE_NAME = 'mia_price_cache';
    
    /**
     * TTL por defecto (1 hora en segundos)
     */
    const DEFAULT_TTL = 3600;
    
    /**
     * Instancia del logger
     * @var Logger
     */
    private Logger $logger;
    
    /**
     * Nombre completo de la tabla con prefijo de WordPress
     * @var string
     */
    private string $table_name;
    
    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        
        // Verificar que WordPress esté disponible
        if (!isset($wpdb) || !function_exists('current_time')) {
            throw new \Exception('WordPress no está disponible. PriceCache requiere un entorno WordPress activo.');
        }
        
        $this->table_name = $wpdb->prefix . self::TABLE_NAME;
        $this->logger = new Logger('price_cache');
        
        // Crear tabla si no existe
        $this->create_table_if_not_exists();
    }
    
    /**
     * Crear tabla de cache si no existe
     */
    private function create_table_if_not_exists(): void {
        global $wpdb;
        
        // Verificar que WordPress esté disponible
        if (!defined('ABSPATH') || !function_exists('dbDelta')) {
            $this->logger->warning('WordPress no disponible, saltando creación de tabla');
            return;
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Usar el Installer centralizado para crear la tabla
        if (class_exists('MiIntegracionApi\\Core\\Installer')) {
            $result = \MiIntegracionApi\Core\Installer::create_price_cache_table();
            if ($result) {
                $this->logger->info('Tabla de cache de precios verificada/creada usando Installer centralizado');
            } else {
                $this->logger->error('Error creando tabla de cache de precios usando Installer centralizado');
            }
        } else {
            $this->logger->error('Clase Installer no encontrada, no se puede crear la tabla de cache de precios');
        }
    }
    
    /**
     * Obtener precio del cache
     * 
     * @param string $sku SKU del producto
     * @return array|null Datos del cache o null si no existe/expiró
     */
    public function get(string $sku): ?array {
        global $wpdb;
        
        try {
            $sku = sanitize_text_field($sku);
            
            // Buscar en cache
            $cached_data = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE sku = %s AND estado = 'activo'",
                $sku
            ), ARRAY_A);
            
            if (!$cached_data) {
                $this->logger->debug("Cache miss para SKU: {$sku}");
                return null;
            }
            
            // Verificar si ha expirado
            $fecha_expiracion = strtotime($cached_data['fecha_actualizacion']) + $cached_data['ttl'];
            
            if (time() > $fecha_expiracion) {
                $this->logger->debug("Cache expirado para SKU: {$sku}");
                
                // Marcar como expirado
                $this->invalidate($sku, 'expirado');
                return null;
            }
            
            $this->logger->debug("Cache hit para SKU: {$sku}");
            
            // Decodificar condiciones_tarifa_json si existe
            if (!empty($cached_data['condiciones_tarifa_json'])) {
                $cached_data['condiciones_tarifa'] = json_decode($cached_data['condiciones_tarifa_json'], true);
            }
            
            return $cached_data;
            
        } catch (\Exception $e) {
            $this->logger->error("Error obteniendo cache para SKU {$sku}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Guardar precio en cache
     * 
     * @param string $sku SKU del producto
     * @param float|null $precio Precio del producto
     * @param array|null $condiciones_tarifa Condiciones completas de tarifa
     * @param int|null $ttl TTL personalizado en segundos
     * @return bool True si se guardó correctamente
     */
    public function set(string $sku, ?float $precio, ?array $condiciones_tarifa = null, ?int $ttl = null): bool {
        global $wpdb;
        
        try {
            $sku = sanitize_text_field($sku);
            $ttl = $ttl ?? self::DEFAULT_TTL;
            
            // Validar datos
            if (empty($sku)) {
                $this->logger->error('SKU vacío al intentar guardar en cache');
                return false;
            }
            
            if ($precio !== null && ($precio < 0 || $precio > 999999.99)) {
                $this->logger->error("Precio inválido para SKU {$sku}: {$precio}");
                return false;
            }
            
            // Generar hash de los datos para detectar cambios
            $data_for_hash = [
                'precio' => $precio,
                'condiciones_tarifa' => $condiciones_tarifa
            ];
            $hash_datos = md5(serialize($data_for_hash));
            
            // Preparar datos para inserción/actualización
            $data = [
                'sku' => $sku,
                'precio' => $precio,
                'condiciones_tarifa_json' => $condiciones_tarifa ? json_encode($condiciones_tarifa) : null,
                'fecha_actualizacion' => current_time('mysql'),
                'ttl' => $ttl,
                'hash_datos' => $hash_datos,
                'estado' => 'activo'
            ];
            
            // Verificar si ya existe
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$this->table_name} WHERE sku = %s",
                $sku
            ));
            
            if ($existing) {
                // Actualizar registro existente
                $result = $wpdb->update(
                    $this->table_name,
                    $data,
                    ['sku' => $sku],
                    ['%s', '%f', '%s', '%s', '%d', '%s', '%s'],
                    ['%s']
                );
            } else {
                // Insertar nuevo registro
                $result = $wpdb->insert(
                    $this->table_name,
                    $data,
                    ['%s', '%f', '%s', '%s', '%d', '%s', '%s']
                );
            }
            
            if ($result === false) {
                $this->logger->error("Error guardando cache para SKU {$sku}: " . $wpdb->last_error);
                return false;
            }
            
            $this->logger->debug("Cache guardado correctamente para SKU: {$sku}");
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Excepción guardando cache para SKU {$sku}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invalidar cache de un producto específico
     * 
     * @param string $sku SKU del producto
     * @param string $estado Estado al que cambiar (invalidado|expirado)
     * @return bool True si se invalidó correctamente
     */
    public function invalidate(string $sku, string $estado = 'invalidado'): bool {
        global $wpdb;
        
        try {
            $sku = sanitize_text_field($sku);
            
            if (!in_array($estado, ['invalidado', 'expirado'])) {
                $estado = 'invalidado';
            }
            
            $result = $wpdb->update(
                $this->table_name,
                [
                    'estado' => $estado,
                    'updated_at' => current_time('mysql')
                ],
                ['sku' => $sku],
                ['%s', '%s'],
                ['%s']
            );
            
            if ($result === false) {
                $this->logger->error("Error invalidando cache para SKU {$sku}: " . $wpdb->last_error);
                return false;
            }
            
            $this->logger->debug("Cache invalidado para SKU: {$sku} (estado: {$estado})");
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Error invalidando cache para SKU {$sku}: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Limpiar cache expirado y entradas antiguas
     * 
     * @param int $dias_antiguedad Días de antiguedad para considerar limpieza
     * @return int Número de registros eliminados
     */
    public function cleanup(int $dias_antiguedad = 7): int {
        global $wpdb;
        
        try {
            $fecha_limite = date('Y-m-d H:i:s', strtotime("-{$dias_antiguedad} days"));
            
            // Eliminar registros expirados/invalidados antiguos
            $result = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$this->table_name} 
                 WHERE estado IN ('expirado', 'invalidado') 
                 AND updated_at < %s",
                $fecha_limite
            ));
            
            if ($result === false) {
                $this->logger->error("Error durante cleanup de cache: " . $wpdb->last_error);
                return 0;
            }
            
            if ($result > 0) {
                $this->logger->info("Cleanup de cache completado: {$result} registros eliminados");
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $this->logger->error("Excepción durante cleanup de cache: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Obtener estadísticas del cache
     * 
     * @return array Estadísticas del cache
     */
    public function get_stats(): array {
        global $wpdb;
        
        try {
            $stats = [];
            
            // Total de registros
            $stats['total_registros'] = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name}"
            );
            
            // Registros activos
            $stats['registros_activos'] = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE estado = 'activo'"
            );
            
            // Registros expirados
            $stats['registros_expirados'] = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE estado = 'expirado'"
            );
            
            // Registros invalidados
            $stats['registros_invalidados'] = $wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table_name} WHERE estado = 'invalidado'"
            );
            
            // Fecha del registro más antiguo
            $stats['fecha_mas_antigua'] = $wpdb->get_var(
                "SELECT MIN(created_at) FROM {$this->table_name}"
            );
            
            // Fecha del registro más reciente
            $stats['fecha_mas_reciente'] = $wpdb->get_var(
                "SELECT MAX(updated_at) FROM {$this->table_name}"
            );
            
            // Calcular hit rate aproximado (basado en registros activos vs total)
            if ($stats['total_registros'] > 0) {
                $stats['hit_rate_estimado'] = round(
                    ($stats['registros_activos'] / $stats['total_registros']) * 100, 
                    2
                );
            } else {
                $stats['hit_rate_estimado'] = 0;
            }
            
            return $stats;
            
        } catch (\Exception $e) {
            $this->logger->error("Error obteniendo estadísticas de cache: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Limpiar completamente el cache (usar con precaución)
     * 
     * @return bool True si se limpió correctamente
     */
    public function flush(): bool {
        global $wpdb;
        
        try {
            $result = $wpdb->query("TRUNCATE TABLE {$this->table_name}");
            
            if ($result === false) {
                $this->logger->error("Error limpiando cache completamente: " . $wpdb->last_error);
                return false;
            }
            
            $this->logger->warning("Cache de precios limpiado completamente");
            return true;
            
        } catch (\Exception $e) {
            $this->logger->error("Excepción limpiando cache: " . $e->getMessage());
            return false;
        }
    }
}
