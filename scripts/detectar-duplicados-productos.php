<?php
/**
 * Script de Detección de Productos Duplicados para WordPress
 * 
 * Instalación:
 * 1. Subir este archivo a wp-content/plugins/ o wp-content/mu-plugins/
 * 2. Activar el plugin desde WordPress Admin
 * 3. Ir a Verial → Detectar Duplicados
 * 
 * O usar como must-use plugin:
 * - Renombrar a detectar-duplicados-productos.php
 * - Colocar en wp-content/mu-plugins/
 * - Se activa automáticamente
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../');
    require_once(ABSPATH . 'wp-load.php');
}

class Verial_Duplicate_Detector {
    
    private $capability = 'manage_woocommerce';
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_verial_detect_duplicates', [$this, 'ajax_detect_duplicates']);
        add_action('wp_ajax_verial_clean_duplicates', [$this, 'ajax_clean_duplicates']);
        add_action('wp_ajax_verial_get_stats', [$this, 'ajax_get_stats']);
    }
    
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Detectar Duplicados - Verial',
            'Detectar Duplicados',
            $this->capability,
            'verial-duplicate-detector',
            [$this, 'render_page']
        );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_verial-duplicate-detector') {
            return;
        }
        
        // CSS inline
        wp_enqueue_style('verial-duplicate-detector', false);
        wp_add_inline_style('verial-duplicate-detector', $this->get_css());
        
        // JavaScript inline
        wp_enqueue_script('jquery');
        wp_add_inline_script('jquery', $this->get_js(), 'after');
    }
    
    public function render_page() {
        if (!current_user_can($this->capability)) {
            wp_die('No tienes permisos para acceder a esta página.');
        }
        
        ?>
        <div class="wrap verial-duplicate-detector">
            <h1>🔍 Detector de Productos Duplicados - Verial</h1>
            
            <div class="verial-stats-container">
                <div class="verial-stat-card">
                    <div class="stat-icon">📦</div>
                    <div class="stat-content">
                        <div class="stat-value" id="stat-total">-</div>
                        <div class="stat-label">Total Productos</div>
                    </div>
                </div>
                
                <div class="verial-stat-card">
                    <div class="stat-icon">⚠️</div>
                    <div class="stat-content">
                        <div class="stat-value" id="stat-duplicates">-</div>
                        <div class="stat-label">SKUs Duplicados</div>
                    </div>
                </div>
                
                <div class="verial-stat-card">
                    <div class="stat-icon">❌</div>
                    <div class="stat-content">
                        <div class="stat-value" id="stat-no-sku">-</div>
                        <div class="stat-label">Sin SKU</div>
                    </div>
                </div>
                
                <div class="verial-stat-card">
                    <div class="stat-icon">🔴</div>
                    <div class="stat-content">
                        <div class="stat-value" id="stat-problematic">-</div>
                        <div class="stat-label">SKUs Problemáticos</div>
                    </div>
                </div>
            </div>
            
            <div class="verial-actions">
                <button type="button" class="button button-primary verial-btn" id="btn-detect">
                    🔍 Detectar Duplicados
                </button>
                <button type="button" class="button button-secondary verial-btn" id="btn-refresh">
                    🔄 Actualizar Estadísticas
                </button>
                <button type="button" class="button button-secondary verial-btn" id="btn-export">
                    📥 Exportar Reporte
                </button>
            </div>
            
            <div class="verial-tabs">
                <button class="tab-button active" data-tab="duplicates">SKUs Duplicados</button>
                <button class="tab-button" data-tab="no-sku">Sin SKU</button>
                <button class="tab-button" data-tab="problematic">SKUs Problemáticos</button>
                <button class="tab-button" data-tab="all-products">Todos los Productos</button>
            </div>
            
            <div id="loading" class="verial-loading" style="display: none;">
                <div class="spinner"></div>
                <p>Analizando productos...</p>
            </div>
            
            <div id="results-container" class="verial-results">
                <div class="tab-content active" id="tab-duplicates">
                    <div class="verial-table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>SKU</th>
                                    <th>Cantidad</th>
                                    <th>IDs de Productos</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="duplicates-table-body">
                                <tr>
                                    <td colspan="4" class="no-data">Haz clic en "Detectar Duplicados" para comenzar</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="tab-content" id="tab-no-sku">
                    <div class="verial-table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Nombre</th>
                                    <th>Estado</th>
                                    <th>Fecha</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="no-sku-table-body">
                                <tr>
                                    <td colspan="5" class="no-data">Haz clic en "Detectar Duplicados" para comenzar</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="tab-content" id="tab-problematic">
                    <div class="verial-table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>SKU</th>
                                    <th>Nombre</th>
                                    <th>Problema</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="problematic-table-body">
                                <tr>
                                    <td colspan="5" class="no-data">Haz clic en "Detectar Duplicados" para comenzar</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <div class="tab-content" id="tab-all-products">
                    <div class="verial-search-box">
                        <input type="text" id="search-products" placeholder="Buscar por SKU, nombre o ID..." class="regular-text">
                        <button type="button" class="button" id="btn-search">Buscar</button>
                    </div>
                    <div class="verial-table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>SKU</th>
                                    <th>Nombre</th>
                                    <th>Estado</th>
                                    <th>Precio</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="all-products-table-body">
                                <tr>
                                    <td colspan="6" class="no-data">Haz clic en "Detectar Duplicados" para comenzar</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <div id="verial-modal" class="verial-modal" style="display: none;">
                <div class="verial-modal-content">
                    <span class="verial-modal-close">&times;</span>
                    <h2 id="modal-title">Confirmar Acción</h2>
                    <div id="modal-body"></div>
                    <div class="verial-modal-actions">
                        <button type="button" class="button button-primary" id="modal-confirm">Confirmar</button>
                        <button type="button" class="button button-secondary" id="modal-cancel">Cancelar</button>
                    </div>
                </div>
            </div>
        </div>
        
        <script>
        var verialAjaxUrl = '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';
        var verialNonce = '<?php echo esc_js(wp_create_nonce('verial_duplicate_detector')); ?>';
        </script>
        <?php
    }
    
    public function ajax_get_stats() {
        check_ajax_referer('verial_duplicate_detector', 'nonce');
        
        if (!current_user_can($this->capability)) {
            wp_send_json_error('Sin permisos');
        }
        
        global $wpdb;
        
        $stats = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product'"),
            'duplicates' => 0,
            'no_sku' => 0,
            'problematic' => 0
        ];
        
        // Contar productos sin SKU
        $stats['no_sku'] = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
              AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        
        // Contar SKUs duplicados
        $stats['duplicates'] = (int) $wpdb->get_var("
            SELECT COUNT(*)
            FROM (
                SELECT meta_value
                FROM {$wpdb->postmeta}
                WHERE meta_key = '_sku' AND meta_value != ''
                GROUP BY meta_value
                HAVING COUNT(*) > 1
            ) as dup
        ");
        
        // Contar SKUs problemáticos
        $stats['problematic'] = (int) $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
              AND pm.meta_key = '_sku'
              AND (pm.meta_value LIKE 'ID_%' OR pm.meta_value LIKE 'VERIAL_%' OR pm.meta_value = 'ID_unknown')
        ");
        
        wp_send_json_success($stats);
    }
    
    public function ajax_detect_duplicates() {
        check_ajax_referer('verial_duplicate_detector', 'nonce');
        
        if (!current_user_can($this->capability)) {
            wp_send_json_error('Sin permisos');
        }
        
        global $wpdb;
        
        $results = [
            'duplicates' => [],
            'no_sku' => [],
            'problematic' => [],
            'all_products' => []
        ];
        
        // Detectar SKUs duplicados
        $duplicates = $wpdb->get_results("
            SELECT 
                pm.meta_value as sku,
                COUNT(*) as count,
                GROUP_CONCAT(p.ID ORDER BY p.ID) as product_ids
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = '_sku'
              AND pm.meta_value != ''
              AND p.post_type = 'product'
            GROUP BY pm.meta_value
            HAVING COUNT(*) > 1
            ORDER BY count DESC
            LIMIT 100
        ");
        
        foreach ($duplicates as $dup) {
            $product_ids = explode(',', $dup->product_ids);
            $products = [];
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $products[] = [
                        'id' => $product_id,
                        'name' => $product->get_name(),
                        'status' => $product->get_status(),
                        'link' => get_edit_post_link($product_id)
                    ];
                }
            }
            
            $results['duplicates'][] = [
                'sku' => $dup->sku,
                'count' => (int) $dup->count,
                'products' => $products
            ];
        }
        
        // Detectar productos sin SKU
        $no_sku = $wpdb->get_results("
            SELECT DISTINCT p.ID, p.post_title, p.post_status, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
              AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ORDER BY p.post_date DESC
            LIMIT 100
        ");
        
        foreach ($no_sku as $product) {
            $results['no_sku'][] = [
                'id' => $product->ID,
                'name' => $product->post_title,
                'status' => $product->post_status,
                'date' => $product->post_date,
                'link' => get_edit_post_link($product->ID)
            ];
        }
        
        // Detectar SKUs problemáticos
        $problematic = $wpdb->get_results("
            SELECT p.ID, p.post_title, pm.meta_value as sku
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            WHERE p.post_type = 'product'
              AND pm.meta_key = '_sku'
              AND (pm.meta_value LIKE 'ID_%' OR pm.meta_value LIKE 'VERIAL_%' OR pm.meta_value = 'ID_unknown')
            ORDER BY p.post_date DESC
            LIMIT 100
        ");
        
        foreach ($problematic as $product) {
            $problem = 'SKU generado automáticamente';
            if (strpos($product->sku, 'VERIAL_') === 0) {
                $problem = 'SKU generado con hash (posible duplicado)';
            } elseif ($product->sku === 'ID_unknown') {
                $problem = 'SKU desconocido (producto sin ID válido)';
            }
            
            $results['problematic'][] = [
                'id' => $product->ID,
                'sku' => $product->sku,
                'name' => $product->post_title,
                'problem' => $problem,
                'link' => get_edit_post_link($product->ID)
            ];
        }
        
        // Obtener todos los productos (primeros 100)
        $all_products = $wpdb->get_results("
            SELECT p.ID, p.post_title, p.post_status, pm.meta_value as sku
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
            ORDER BY p.post_date DESC
            LIMIT 100
        ");
        
        foreach ($all_products as $product) {
            $wc_product = wc_get_product($product->ID);
            $results['all_products'][] = [
                'id' => $product->ID,
                'sku' => $product->sku ?: '(sin SKU)',
                'name' => $product->post_title,
                'status' => $product->post_status,
                'price' => $wc_product ? $wc_product->get_price() : 0,
                'link' => get_edit_post_link($product->ID)
            ];
        }
        
        wp_send_json_success($results);
    }
    
    public function ajax_clean_duplicates() {
        check_ajax_referer('verial_duplicate_detector', 'nonce');
        
        if (!current_user_can($this->capability)) {
            wp_send_json_error('Sin permisos');
        }
        
        $sku = sanitize_text_field($_POST['sku'] ?? '');
        $action = sanitize_text_field($_POST['action_type'] ?? '');
        $keep_id = (int) ($_POST['keep_id'] ?? 0);
        $delete_ids = array_map('intval', $_POST['delete_ids'] ?? []);
        
        if (empty($sku) || empty($action) || $keep_id <= 0) {
            wp_send_json_error('Parámetros inválidos');
        }
        
        $results = [
            'deleted' => [],
            'updated' => [],
            'errors' => []
        ];
        
        if ($action === 'delete') {
            foreach ($delete_ids as $delete_id) {
                $deleted = wp_delete_post($delete_id, true);
                if ($deleted) {
                    $results['deleted'][] = $delete_id;
                } else {
                    $results['errors'][] = "No se pudo eliminar producto ID: $delete_id";
                }
            }
        } elseif ($action === 'merge') {
            // Fusionar: mantener el más antiguo, actualizar con datos del más nuevo
            $keep_product = wc_get_product($keep_id);
            if ($keep_product) {
                foreach ($delete_ids as $delete_id) {
                    $delete_product = wc_get_product($delete_id);
                    if ($delete_product) {
                        // Actualizar producto mantenido con datos del eliminado si están vacíos
                        if (empty($keep_product->get_description()) && !empty($delete_product->get_description())) {
                            $keep_product->set_description($delete_product->get_description());
                        }
                        if (empty($keep_product->get_short_description()) && !empty($delete_product->get_short_description())) {
                            $keep_product->set_short_description($delete_product->get_short_description());
                        }
                        
                        $keep_product->save();
                        
                        // Eliminar duplicado
                        wp_delete_post($delete_id, true);
                        $results['deleted'][] = $delete_id;
                    }
                }
            }
        }
        
        wp_send_json_success($results);
    }
    
    private function get_css() {
        return '
        .verial-duplicate-detector {
            max-width: 1400px;
        }
        .verial-stats-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .verial-stat-card {
            background: #fff;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            display: flex;
            align-items: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-icon {
            font-size: 40px;
            margin-right: 15px;
        }
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #2271b1;
        }
        .stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        .verial-actions {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }
        .verial-btn {
            padding: 10px 20px;
            font-size: 14px;
        }
        .verial-tabs {
            display: flex;
            border-bottom: 2px solid #ddd;
            margin: 20px 0;
        }
        .tab-button {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #666;
            transition: all 0.3s;
        }
        .tab-button:hover {
            color: #2271b1;
        }
        .tab-button.active {
            color: #2271b1;
            border-bottom-color: #2271b1;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .verial-loading {
            text-align: center;
            padding: 40px;
        }
        .spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #2271b1;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .verial-table-container {
            margin: 20px 0;
            overflow-x: auto;
        }
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }
        .verial-search-box {
            margin: 20px 0;
            display: flex;
            gap: 10px;
        }
        .verial-search-box input {
            flex: 1;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .verial-modal {
            display: none;
            position: fixed;
            z-index: 100000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        .verial-modal-content {
            background-color: #fff;
            margin: 5% auto;
            padding: 20px;
            border: 1px solid #888;
            width: 80%;
            max-width: 600px;
            border-radius: 8px;
        }
        .verial-modal-close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }
        .verial-modal-close:hover {
            color: #000;
        }
        .verial-modal-actions {
            margin-top: 20px;
            text-align: right;
        }
        .verial-modal-actions .button {
            margin-left: 10px;
        }
        ';
    }
    
    private function get_js() {
        $js_file = __DIR__ . '/detectar-duplicados-js.js';
        if (file_exists($js_file)) {
            return file_get_contents($js_file);
        }
        
        // Si el archivo JS no existe, usar JS inline básico
        return 'console.error("Archivo JS no encontrado: detectar-duplicados-js.js");';
    }
}

// Inicializar si estamos en WordPress
if (function_exists('add_action')) {
    new Verial_Duplicate_Detector();
}

