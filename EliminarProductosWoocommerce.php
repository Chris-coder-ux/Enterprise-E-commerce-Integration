<?php
/**
 * Script de limpieza para productos WooCommerce problemáticos
 * Elimina productos que no se muestran correctamente
 *
 * @package     VerialIntegration
 * @version     1.0.0
 * @author      Sistema de Integración Verial
 */

// Asegurar que se ejecuta en contexto WordPress
if (!defined('ABSPATH')) {
    // Buscar wp-load.php en diferentes ubicaciones posibles
    $wp_load_paths = [
        dirname(__FILE__) . '/wp-load.php',
        dirname(dirname(__FILE__)) . '/wp-load.php',
        dirname(dirname(dirname(__FILE__))) . '/wp-load.php',
        $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php'
    ];
    
    $wp_loaded = false;
    foreach ($wp_load_paths as $path) {
        if (file_exists($path)) {
            require_once($path);
            $wp_loaded = true;
            break;
        }
    }
    
    if (!$wp_loaded) {
        die('Error: No se pudo cargar WordPress. Verifique la ruta de wp-load.php');
    }
}

/**
 * Clase para limpiar productos WooCommerce problemáticos
 *
 * Esta clase proporciona funcionalidades para identificar y eliminar
 * productos que no se muestran correctamente en WooCommerce.
 *
 * @package     VerialIntegration
 * @version     1.0.0
 */
class WooCommerceProductCleaner
{
    /**
     * Instancia del logger para registrar eventos
     * @var \MiIntegracionApi\Helpers\Logger|null
     */
    private $logger;
    
    /**
     * Constructor de la clase
     *
     * Inicializa el logger si está disponible, de lo contrario
     * utiliza un logger básico para evitar errores.
     */
    public function __construct()
    {
        // Intentar cargar el logger personalizado si existe
        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
            $this->logger = new \MiIntegracionApi\Helpers\Logger('product-cleaner');
        } else {
            // Logger básico como fallback
            $this->logger = new class {
                public function info($message, $context = []) {
                    error_log('[PRODUCT-CLEANER] INFO: ' . $message . ' ' . json_encode($context));
                }
                public function error($message, $context = []) {
                    error_log('[PRODUCT-CLEANER] ERROR: ' . $message . ' ' . json_encode($context));
                }
                public function warning($message, $context = []) {
                    error_log('[PRODUCT-CLEANER] WARNING: ' . $message . ' ' . json_encode($context));
                }
            };
        }
    }
    
    /**
     * Diagnóstico completo de productos problemáticos
     */
    public function diagnoseProblematicProducts(): array {
        global $wpdb;
        
        $diagnosis = [
            'total_products' => 0,
            'problematic_products' => [],
            'by_status' => [],
            'by_price' => [],
            'without_sku' => [],
            'without_name' => [],
            'recent_failed_sync' => []
        ];
        
        // Contar productos totales
        $diagnosis['total_products'] = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_status != 'trash'
        ");
        
        // Productos con estado problemático
        $problematic_statuses = ['draft', 'auto-draft', 'pending', 'private'];
        foreach ($problematic_statuses as $status) {
            $count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->posts} 
                WHERE post_type = 'product' 
                AND post_status = %s
            ", $status));
            $diagnosis['by_status'][$status] = $count;
        }
        
        // Productos con precio 0 o sin precio
        $diagnosis['by_price']['price_zero'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND pm.meta_key = '_price' 
            AND pm.meta_value = '0'
        ");
        
        // Productos sin SKU
        $diagnosis['without_sku'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        
        // Productos sin nombre
        $diagnosis['without_name'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'product' 
            AND post_status = 'publish'
            AND (post_title IS NULL OR post_title = '' OR post_title = 'Auto Draft')
        ");
        
        // Productos de sincronización reciente fallida (con meta _verial_sync_status = 'failed')
        $diagnosis['recent_failed_sync'] = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID) 
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = 'product' 
            AND pm.meta_key = '_verial_sync_status' 
            AND pm.meta_value = 'failed'
            AND p.post_date > DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");
        
        return $diagnosis;
    }
    
    /**
     * Obtener lista detallada de productos problemáticos
     */
    public function getProblematicProductsList(int $limit = 100): array {
        global $wpdb;
        
        $problematic_products = [];
        
        // 1. Productos publicados pero con precio 0
        $products_price_zero = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_status, pm_price.meta_value as price, pm_sku.meta_value as sku
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND pm_price.meta_value = '0'
            ORDER BY p.post_date DESC 
            LIMIT {$limit}
        ");
        
        foreach ($products_price_zero as $product) {
            $problematic_products[] = [
                'id' => $product->ID,
                'name' => $product->post_title,
                'status' => $product->post_status,
                'sku' => $product->sku,
                'price' => $product->price,
                'issue' => 'price_zero',
                'type' => 'published_zero_price'
            ];
        }
        
        // 2. Productos sin SKU
        $products_no_sku = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_status, pm_price.meta_value as price
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND (pm_sku.meta_value IS NULL OR pm_sku.meta_value = '')
            ORDER BY p.post_date DESC 
            LIMIT {$limit}
        ");
        
        foreach ($products_no_sku as $product) {
            $problematic_products[] = [
                'id' => $product->ID,
                'name' => $product->post_title,
                'status' => $product->post_status,
                'sku' => '',
                'price' => $product->price,
                'issue' => 'no_sku',
                'type' => 'published_no_sku'
            ];
        }
        
        // 3. Productos con nombres inválidos
        $products_bad_name = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_status, pm_sku.meta_value as sku, pm_price.meta_value as price
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
            WHERE p.post_type = 'product' 
            AND p.post_status = 'publish'
            AND (p.post_title IS NULL OR p.post_title = '' OR p.post_title = 'Auto Draft' OR p.post_title LIKE 'Producto VERIAL_%')
            ORDER BY p.post_date DESC 
            LIMIT {$limit}
        ");
        
        foreach ($products_bad_name as $product) {
            $problematic_products[] = [
                'id' => $product->ID,
                'name' => $product->post_title,
                'status' => $product->post_status,
                'sku' => $product->sku,
                'price' => $product->price,
                'issue' => 'invalid_name',
                'type' => 'published_bad_name'
            ];
        }
        
        // 4. Productos en estados no publicados (incluyendo auto-draft)
        $problematic_statuses = ['draft', 'auto-draft', 'pending', 'private'];
        foreach ($problematic_statuses as $status) {
            $products_wrong_status = $wpdb->get_results($wpdb->prepare("
                SELECT p.ID, p.post_title, p.post_status, pm_sku.meta_value as sku, pm_price.meta_value as price
                FROM {$wpdb->posts} p 
                LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
                LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
                WHERE p.post_type = 'product' 
                AND p.post_status = %s
                ORDER BY p.post_date DESC 
                LIMIT {$limit}
            ", $status));
            
            foreach ($products_wrong_status as $product) {
                $problematic_products[] = [
                    'id' => $product->ID,
                    'name' => $product->post_title,
                    'status' => $product->post_status,
                    'sku' => $product->sku,
                    'price' => $product->price,
                    'issue' => 'wrong_status',
                    'type' => $status
                ];
            }
        }
        
        // 5. Productos con metadatos de Verial problemáticos
        $verial_problematic = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_status, pm_sku.meta_value as sku, pm_price.meta_value as price
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
            INNER JOIN {$wpdb->postmeta} pm_verial ON p.ID = pm_verial.post_id 
            WHERE p.post_type = 'product' 
            AND pm_verial.meta_key = '_verial_sync_status' 
            AND pm_verial.meta_value = 'failed'
            ORDER BY p.post_date DESC 
            LIMIT {$limit}
        ");
        
        foreach ($verial_problematic as $product) {
            $problematic_products[] = [
                'id' => $product->ID,
                'name' => $product->post_title,
                'status' => $product->post_status,
                'sku' => $product->sku,
                'price' => $product->price,
                'issue' => 'verial_sync_failed',
                'type' => 'verial_sync_failed'
            ];
        }
        
        return $problematic_products;
    }
    
    /**
     * Eliminar productos problemáticos de forma segura
     */
    public function safeCleanup(array $product_ids, bool $dry_run = true): array {
        $results = [
            'total_processed' => count($product_ids),
            'deleted' => [],
            'skipped' => [],
            'errors' => [],
            'dry_run' => $dry_run
        ];
        
        foreach ($product_ids as $product_id) {
            try {
                $product = wc_get_product($product_id);
                
                if (!$product) {
                    $results['skipped'][] = [
                        'id' => $product_id,
                        'reason' => 'product_not_found'
                    ];
                    continue;
                }
                
                // Verificar si el producto tiene pedidos
                $has_orders = $this->productHasOrders($product_id);
                
                if ($has_orders) {
                    $results['skipped'][] = [
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'reason' => 'has_orders'
                    ];
                    continue;
                }
                
                // En dry_run solo registramos qué se eliminaría
                if ($dry_run) {
                    $results['deleted'][] = [
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'sku' => $product->get_sku(),
                        'status' => $product->get_status(),
                        'price' => $product->get_price()
                    ];
                } else {
                    // Eliminación real
                    $deleted = wp_delete_post($product_id, true);
                    
                    if ($deleted) {
                        $results['deleted'][] = [
                            'id' => $product_id,
                            'name' => $product->get_name(),
                            'sku' => $product->get_sku()
                        ];
                        
                        $this->logger->info("Producto eliminado", [
                            'product_id' => $product_id,
                            'sku' => $product->get_sku(),
                            'name' => $product->get_name()
                        ]);
                    } else {
                        $results['errors'][] = [
                            'id' => $product_id,
                            'reason' => 'delete_failed'
                        ];
                    }
                }
                
            } catch (Exception $e) {
                $results['errors'][] = [
                    'id' => $product_id,
                    'reason' => 'exception',
                    'message' => $e->getMessage()
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Verificar si un producto tiene pedidos asociados
     */
    private function productHasOrders(int $product_id): bool {
        global $wpdb;
        
        $order_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT order_id)
            FROM {$wpdb->prefix}woocommerce_order_items AS items
            LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS meta ON items.order_item_id = meta.order_item_id
            WHERE items.order_item_type = 'line_item'
            AND meta.meta_key IN ('_product_id', '_variation_id')
            AND meta.meta_value = %d
        ", $product_id));
        
        return $order_count > 0;
    }
    
    /**
     * Limpieza masiva por tipo de problema
     */
    public function cleanupByIssueType(string $issue_type, bool $dry_run = true): array {
        $problematic_products = $this->getProblematicProductsList(1000);
        
        $filtered_products = array_filter($problematic_products, function($product) use ($issue_type) {
            return $product['type'] === $issue_type;
        });
        
        $product_ids = array_column($filtered_products, 'id');
        
        return $this->safeCleanup($product_ids, $dry_run);
    }
    
    /**
     * Obtener TODOS los productos de WooCommerce
     */
    public function getAllProducts(int $limit = 10000): array {
        global $wpdb;
        
        $all_products = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_status, pm_sku.meta_value as sku, pm_price.meta_value as price
            FROM {$wpdb->posts} p 
            LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id AND pm_sku.meta_key = '_sku'
            LEFT JOIN {$wpdb->postmeta} pm_price ON p.ID = pm_price.post_id AND pm_price.meta_key = '_price'
            WHERE p.post_type = 'product' 
            AND p.post_status != 'trash'
            ORDER BY p.post_date DESC 
            LIMIT {$limit}
        ");
        
        $products = [];
        foreach ($all_products as $product) {
            $products[] = [
                'id' => $product->ID,
                'name' => $product->post_title,
                'status' => $product->post_status,
                'sku' => $product->sku,
                'price' => $product->price,
                'issue' => 'all_products',
                'type' => 'all_products'
            ];
        }
        
        return $products;
    }
    
    /**
     * Eliminación masiva de TODOS los productos
     */
    public function massDeleteAllProducts(bool $dry_run = true): array {
        $all_products = $this->getAllProducts(10000);
        $product_ids = array_column($all_products, 'id');
        
        return $this->safeCleanup($product_ids, $dry_run);
    }
}

// ============================================================================
// INTERFAZ DE USUARIO PARA EL SCRIPT
// ============================================================================

function run_product_cleanup_script() {
    $cleaner = new WooCommerceProductCleaner();
    
    echo "<h1>🧹 Limpiador de Productos WooCommerce</h1>";
    echo "<p><strong>⚠️ ADVERTENCIA:</strong> Este script eliminará productos permanentemente. Haz una copia de seguridad primero.</p>";
    
    // Diagnóstico inicial
    $diagnosis = $cleaner->diagnoseProblematicProducts();
    
    echo "<h2>📊 Diagnóstico de Productos Problemáticos</h2>";
    echo "<ul>";
    echo "<li><strong>Total de productos:</strong> {$diagnosis['total_products']}</li>";
    echo "<li><strong>Productos con precio 0:</strong> {$diagnosis['by_price']['price_zero']}</li>";
    echo "<li><strong>Productos sin SKU:</strong> {$diagnosis['without_sku']}</li>";
    echo "<li><strong>Productos sin nombre:</strong> {$diagnosis['without_name']}</li>";
    echo "</ul>";
    
    foreach ($diagnosis['by_status'] as $status => $count) {
        echo "<li><strong>Productos en estado '{$status}':</strong> {$count}</li>";
    }
    echo "</ul>";
    
    // Lista detallada de productos problemáticos
    $problematic_list = $cleaner->getProblematicProductsList(50);
    
    echo "<h2>📋 Productos Problemáticos (primeros 50)</h2>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>SKU</th>
            <th>Estado</th>
            <th>Precio</th>
            <th>Problema</th>
            <th>Tipo</th>
          </tr>";
    
    foreach ($problematic_list as $product) {
        echo "<tr>
                <td>{$product['id']}</td>
                <td>" . esc_html($product['name']) . "</td>
                <td>" . esc_html($product['sku']) . "</td>
                <td>{$product['status']}</td>
                <td>{$product['price']}</td>
                <td>{$product['issue']}</td>
                <td>{$product['type']}</td>
              </tr>";
    }
    echo "</table>";
    
    // Lista de TODOS los productos
    $all_products_list = $cleaner->getAllProducts(100);
    
    echo "<h2>📦 TODOS los Productos (primeros 100)</h2>";
    echo "<div style='background: #e7f3ff; border: 1px solid #b3d9ff; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
    echo "<strong>ℹ️ INFORMACIÓN:</strong> Esta tabla muestra TODOS los productos de tu tienda WooCommerce. ";
    echo "Si usas la opción de 'Eliminación Masiva Completa', se eliminarán todos estos productos.";
    echo "</div>";
    echo "<table border='1' cellpadding='8' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr>
            <th>ID</th>
            <th>Nombre</th>
            <th>SKU</th>
            <th>Estado</th>
            <th>Precio</th>
            <th>Acción</th>
          </tr>";
    
    foreach ($all_products_list as $product) {
        echo "<tr>
                <td>{$product['id']}</td>
                <td>" . esc_html($product['name']) . "</td>
                <td>" . esc_html($product['sku']) . "</td>
                <td>{$product['status']}</td>
                <td>{$product['price']}</td>
                <td>Se eliminará</td>
              </tr>";
    }
    echo "</table>";
    
    if (count($all_products_list) >= 100) {
        echo "<p><em>Mostrando solo los primeros 100 productos. Total de productos: " . $diagnosis['total_products'] . "</em></p>";
    }
    
    // Opciones de limpieza
    echo "<h2>🛠️ Opciones de Limpieza</h2>";
    
    echo "<form method='post' style='margin-bottom: 20px;'>";
    echo "<h3>Limpieza por Tipo</h3>";
    echo "<select name='cleanup_type'>";
    echo "<option value='published_zero_price'>Productos publicados con precio 0</option>";
    echo "<option value='published_no_sku'>Productos publicados sin SKU</option>";
    echo "<option value='published_bad_name'>Productos con nombres inválidos</option>";
    echo "<option value='draft'>Productos en borrador (draft)</option>";
    echo "<option value='auto-draft'>Productos auto-draft</option>";
    echo "<option value='pending'>Productos pendientes</option>";
    echo "<option value='private'>Productos privados</option>";
    echo "<option value='verial_sync_failed'>Productos con sincronización Verial fallida</option>";
    echo "</select>";
    echo "<br><br>";
    echo "<label><input type='checkbox' name='dry_run' value='1' checked> <strong>Modo simulación (solo mostrar qué se eliminaría)</strong></label>";
    echo "<br><br>";
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
    echo "<strong>⚠️ IMPORTANTE:</strong> Con el modo simulación activado, NO se eliminarán productos. Desmarca la casilla para eliminar realmente.";
    echo "</div>";
    echo "<input type='submit' name='cleanup_by_type' value='Ejecutar Limpieza por Tipo' style='background: #dc3232; color: white; padding: 10px; border: none; cursor: pointer;'>";
    echo "</form>";
    
    echo "<form method='post'>";
    echo "<h3>Limpieza Masiva</h3>";
    echo "<label><input type='checkbox' name='dry_run' value='1' checked> <strong>Modo simulación</strong></label>";
    echo "<br><br>";
    echo "<div style='background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; margin: 10px 0; border-radius: 4px;'>";
    echo "<strong>⚠️ IMPORTANTE:</strong> Con el modo simulación activado, NO se eliminarán productos. Desmarca la casilla para eliminar realmente.";
    echo "</div>";
    echo "<input type='submit' name='cleanup_all' value='Limpiar TODOS los productos problemáticos' style='background: #dc3232; color: white; padding: 10px; border: none; cursor: pointer;'>";
    echo "</form>";
    
    echo "<form method='post'>";
    echo "<h3>🚨 ELIMINACIÓN MASIVA COMPLETA</h3>";
    echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
    echo "<strong>⚠️ PELIGRO:</strong> Esta opción eliminará <strong>TODOS</strong> los productos de WooCommerce, sin excepción. ";
    echo "Solo úsala si estás seguro de que quieres limpiar completamente la tienda.";
    echo "</div>";
    echo "<label><input type='checkbox' name='dry_run' value='1' checked> <strong>Modo simulación</strong></label>";
    echo "<br><br>";
    echo "<label><input type='checkbox' name='confirm_mass_delete' value='1'> <strong>Confirmo que quiero eliminar TODOS los productos</strong></label>";
    echo "<br><br>";
    echo "<input type='submit' name='mass_delete_all' value='🚨 ELIMINAR TODOS LOS PRODUCTOS' style='background: #721c24; color: white; padding: 15px; border: none; cursor: pointer; font-weight: bold;'>";
    echo "</form>";
    
    // Procesar solicitudes
    if (isset($_POST['cleanup_by_type']) || isset($_POST['cleanup_all']) || isset($_POST['mass_delete_all'])) {
        $dry_run = isset($_POST['dry_run']) && $_POST['dry_run'] == '1';
        $cleanup_type = $_POST['cleanup_type'] ?? '';
        
        echo "<h2>🔧 Resultados de la Limpieza</h2>";
        
        if (isset($_POST['mass_delete_all'])) {
            // Verificar confirmación para eliminación masiva
            if (!isset($_POST['confirm_mass_delete']) || $_POST['confirm_mass_delete'] != '1') {
                echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
                echo "<strong>❌ ERROR:</strong> Debes confirmar que quieres eliminar TODOS los productos marcando la casilla de confirmación.";
                echo "</div>";
                return;
            }
            
            $results = $cleaner->massDeleteAllProducts($dry_run);
            echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; margin: 10px 0; border-radius: 4px;'>";
            echo "<strong>🚨 ELIMINACIÓN MASIVA COMPLETA:</strong> Se procesarán TODOS los productos de la tienda.";
            echo "</div>";
            
        } elseif (isset($_POST['cleanup_all'])) {
            $problematic_products = $cleaner->getProblematicProductsList(1000);
            $product_ids = array_column($problematic_products, 'id');
            $results = $cleaner->safeCleanup($product_ids, $dry_run);
        } else {
            $results = $cleaner->cleanupByIssueType($cleanup_type, $dry_run);
        }
        
        echo "<p><strong>Modo:</strong> " . ($dry_run ? "SIMULACIÓN" : "ELIMINACIÓN REAL") . "</p>";
        echo "<p><strong>Total procesados:</strong> {$results['total_processed']}</p>";
        echo "<p><strong>Eliminados:</strong> " . count($results['deleted']) . "</p>";
        echo "<p><strong>Omitidos:</strong> " . count($results['skipped']) . "</p>";
        echo "<p><strong>Errores:</strong> " . count($results['errors']) . "</p>";
        
        if (!empty($results['deleted'])) {
            echo "<h3>📝 Productos " . ($dry_run ? "que se eliminarían" : "eliminados") . "</h3>";
            echo "<ul>";
            foreach ($results['deleted'] as $deleted) {
                echo "<li>ID: {$deleted['id']} - SKU: " . ($deleted['sku'] ?: 'N/A') . " - Nombre: " . esc_html($deleted['name']) . "</li>";
            }
            echo "</ul>";
        }
        
        if (!empty($results['skipped'])) {
            echo "<h3>⏭️ Productos Omitidos</h3>";
            echo "<ul>";
            foreach ($results['skipped'] as $skipped) {
                echo "<li>ID: {$skipped['id']} - Razón: {$skipped['reason']}" . (isset($skipped['name']) ? " - Nombre: " . esc_html($skipped['name']) : "") . "</li>";
            }
            echo "</ul>";
        }
    }
}

// Ejecutar el script
run_product_cleanup_script();