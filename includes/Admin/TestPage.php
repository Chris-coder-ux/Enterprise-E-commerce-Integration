<?php
/**
 * Página de administración para Tests de Desarrollo
 *
 * Esta página permite ejecutar los tests de la arquitectura en dos fases
 * directamente desde el panel de administración de WordPress, sin necesidad
 * de usar la terminal.
 *
 * @package MiIntegracionApi\Admin
 * @since 1.5.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

use MiIntegracionApi\Sync\ImageSyncManager;
use MiIntegracionApi\Core\BatchProcessor;
use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Helpers\MapProduct;

/**
 * Clase para la página de tests de desarrollo
 */
class TestPage {
    
    /**
     * Renderiza la página de tests
     */
    public static function render(): void {
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para acceder a esta página.');
        }
        
        // Procesar acciones si las hay
        $action = $_GET['action'] ?? '';
        $result = null;
        $connection_test = null;
        
        if ($action === 'test_connection') {
            check_admin_referer('test_connection');
            $connection_test = self::test_verial_connection();
        }
        
        if ($action === 'run_phase1' && isset($_POST['num_products'])) {
            check_admin_referer('test_phase1');
            $result = self::run_phase1_test((int)$_POST['num_products']);
        }
        
        if ($action === 'run_phase2' && isset($_POST['num_products'])) {
            check_admin_referer('test_phase2');
            $result = self::run_phase2_test((int)$_POST['num_products']);
        }
        
        // Renderizar página
        self::render_page($result, $connection_test);
    }
    
    /**
     * Verifica la conexión con la API de Verial
     */
    private static function test_verial_connection(): array {
        $logger = new Logger('test-connection-verial');
        $start_time = microtime(true);
        
        try {
            $apiConnector = ApiConnector::get_instance();
            
            // 1. Verificar sesión
            $session_number = $apiConnector->get_session_number();
            $session_status = !empty($session_number) ? 'ok' : 'missing';
            
            // 2. Verificar configuración base (obtener desde opciones de WordPress)
            $base_url = get_option('verial_api_base_url', '');
            if (empty($base_url)) {
                // Intentar obtener desde VerialApiConfig si está disponible
                if (class_exists('MiIntegracionApi\\Core\\VerialApiConfig')) {
                    $config = \MiIntegracionApi\Core\VerialApiConfig::getInstance();
                    $base_url = $config->getBaseUrl() ?? '';
                }
            }
            $config_status = !empty($base_url) ? 'ok' : 'unknown';
            
            // 3. Probar conexión con endpoint simple (GetPaisesWS)
            $connection_test = [
                'endpoint' => 'GetPaisesWS',
                'status' => 'unknown',
                'response_time' => 0,
                'error' => null,
                'data' => null
            ];
            
            $test_start = microtime(true);
            try {
                $params = [
                    'x' => $session_number
                ];
                
                $response = $apiConnector->get('GetPaisesWS', $params);
                $test_end = microtime(true);
                $connection_test['response_time'] = round(($test_end - $test_start) * 1000, 2); // en ms
                
                if ($response->isSuccess()) {
                    $connection_test['status'] = 'success';
                    $data = $response->getData();
                    $connection_test['data'] = [
                        'paises_count' => count($data['Paises'] ?? []),
                        'has_data' => !empty($data['Paises'])
                    ];
                } else {
                    $connection_test['status'] = 'error';
                    $connection_test['error'] = $response->getMessage();
                }
            } catch (\Exception $e) {
                $test_end = microtime(true);
                $connection_test['response_time'] = round(($test_end - $test_start) * 1000, 2);
                $connection_test['status'] = 'exception';
                $connection_test['error'] = $e->getMessage();
            }
            
            // 4. Probar obtener productos (GetArticulosWS)
            $products_test = [
                'endpoint' => 'GetArticulosWS',
                'status' => 'unknown',
                'response_time' => 0,
                'error' => null,
                'data' => null
            ];
            
            $test_start = microtime(true);
            try {
                // Probar primero con paginación (igual que BatchProcessor)
                $params = [
                    'x' => $session_number,
                    'id_articulo' => 0, // 0 para todos los artículos (igual que BatchProcessor)
                    'inicio' => 1,
                    'fin' => 100
                ];
                
                $response = $apiConnector->get('GetArticulosWS', $params);
                $test_end = microtime(true);
                $products_test['response_time'] = round(($test_end - $test_start) * 1000, 2);
                
                if ($response->isSuccess()) {
                    $products_test['status'] = 'success';
                    $data = $response->getData();
                    
                    // Logging detallado para diagnóstico
                    $logger->debug('Respuesta GetArticulosWS', [
                        'data_type' => gettype($data),
                        'data_keys' => is_array($data) ? array_keys($data) : 'not_array',
                        'has_articulos' => isset($data['Articulos']),
                        'has_articulos_lowercase' => isset($data['articulos']),
                        'has_body' => isset($data['body']),
                        'data_sample' => is_array($data) ? array_slice($data, 0, 3, true) : 'not_array'
                    ]);
                    
                    // Procesar respuesta igual que BatchProcessor (líneas 2274-2326)
                    $articulos_data = $data;
                    
                    // Si la respuesta tiene 'body' (formato ApiConnector), decodificar el JSON
                    if (isset($articulos_data['body'])) {
                        $json_data = json_decode($articulos_data['body'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $logger->error('Error decodificando JSON de body', [
                                'error' => json_last_error_msg()
                            ]);
                        } else {
                            $articulos_data = $json_data;
                        }
                    }
                    // Si la respuesta tiene 'contenido_body' (formato legacy), decodificar el JSON
                    elseif (isset($articulos_data['contenido_body'])) {
                        $json_data = json_decode($articulos_data['contenido_body'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $logger->error('Error decodificando JSON de contenido_body', [
                                'error' => json_last_error_msg()
                            ]);
                        } else {
                            $articulos_data = $json_data;
                        }
                    }
                    
                    // Buscar en diferentes claves posibles según la documentación de Verial
                    $articulos = null;
                    if (isset($articulos_data['Articulos'])) {
                        $articulos = $articulos_data['Articulos'];
                    } elseif (isset($articulos_data['articulos'])) {
                        $articulos = $articulos_data['articulos'];
                    }
                    
                    $articulos = is_array($articulos) ? $articulos : [];
                    
                    $logger->info('Artículos procesados', [
                        'articulos_count' => count($articulos),
                        'has_articulos' => !empty($articulos),
                        'first_product_id' => !empty($articulos) ? ($articulos[0]['Id'] ?? null) : null
                    ]);
                    
                    $products_test['data'] = [
                        'articulos_count' => count($articulos),
                        'has_data' => !empty($articulos),
                        'first_product_id' => !empty($articulos) ? ($articulos[0]['Id'] ?? null) : null,
                        'response_structure' => is_array($data) ? array_keys($data) : 'not_array',
                        'articulos_data_structure' => is_array($articulos_data) ? array_keys($articulos_data) : 'not_array'
                    ];
                } else {
                    $products_test['status'] = 'error';
                    $products_test['error'] = $response->getMessage();
                }
            } catch (\Exception $e) {
                $test_end = microtime(true);
                $products_test['response_time'] = round(($test_end - $test_start) * 1000, 2);
                $products_test['status'] = 'exception';
                $products_test['error'] = $e->getMessage();
                $logger->error('Excepción en test de productos', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
            
            $end_time = microtime(true);
            $total_time = round($end_time - $start_time, 2);
            
            // Determinar estado general
            $overall_status = 'success';
            $overall_message = 'Conexión con Verial OK';
            
            if ($session_status !== 'ok') {
                $overall_status = 'error';
                $overall_message = 'Número de sesión no configurado';
            } elseif ($connection_test['status'] !== 'success' || $products_test['status'] !== 'success') {
                $overall_status = 'warning';
                $overall_message = 'Conexión parcial o con errores';
            }
            
            return [
                'success' => $overall_status === 'success',
                'status' => $overall_status,
                'message' => $overall_message,
                'session' => [
                    'number' => $session_number,
                    'status' => $session_status
                ],
                'config' => [
                    'base_url' => $base_url,
                    'status' => $config_status
                ],
                'connection_test' => $connection_test,
                'products_test' => $products_test,
                'total_time' => $total_time
            ];
            
        } catch (\Exception $e) {
            $logger->error('Error en test de conexión', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'status' => 'error',
                'message' => 'Error ejecutando test de conexión: ' . $e->getMessage(),
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Ejecuta el test de Fase 1
     */
    private static function run_phase1_test(int $num_products): array {
        $logger = new Logger('test-desarrollo-fase1');
        $apiConnector = ApiConnector::get_instance();
        $imageSyncManager = new ImageSyncManager($apiConnector, $logger);
        
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        try {
            // Verificar que ApiConnector tiene sesión configurada
            $session_number = $apiConnector->get_session_number();
            if (empty($session_number)) {
                return [
                    'success' => false,
                    'phase' => 1,
                    'error' => 'No se ha configurado el número de sesión de Verial. Por favor, configura la sesión en la configuración del plugin.',
                    'message' => 'Error: Sesión no configurada'
                ];
            }
            
            // Obtener IDs de productos
            $logger->info('Obteniendo IDs de productos desde API', [
                'session_number' => $session_number,
                'num_products_requested' => $num_products
            ]);
            
            $product_ids = $imageSyncManager->getAllProductIds();
            $total_products_available = count($product_ids);
            
            $logger->info('IDs de productos obtenidos', [
                'total_products_available' => $total_products_available,
                'num_products_requested' => $num_products
            ]);
            
            if (empty($product_ids)) {
                return [
                    'success' => false,
                    'phase' => 1,
                    'error' => 'No se encontraron productos en la API de Verial. Verifica la conexión y el número de sesión.',
                    'message' => 'Error: No hay productos disponibles',
                    'session_number' => $session_number,
                    'total_products_available' => 0
                ];
            }
            
            $product_ids_test = array_slice($product_ids, 0, min($num_products, count($product_ids)));
            
            $logger->info('Iniciando procesamiento de imágenes', [
                'products_to_process' => count($product_ids_test),
                'product_ids' => $product_ids_test
            ]);
            
            $processed = 0;
            $errors = 0;
            $duplicates = 0;
            $total_images = 0;
            
            foreach ($product_ids_test as $product_id) {
                $logger->debug('Procesando imágenes del producto', ['product_id' => $product_id]);
                
                $result = $imageSyncManager->processProductImages($product_id);
                
                if (is_array($result) && isset($result['attachments'])) {
                    $processed++;
                    $attachments = $result['attachments'] ?? 0;
                    $duplicates_count = $result['duplicates'] ?? 0;
                    $errors_count = $result['errors'] ?? 0;
                    
                    $duplicates += $duplicates_count;
                    $total_images += $attachments;
                    $errors += $errors_count;
                    
                    $logger->debug('Resultado del procesamiento', [
                        'product_id' => $product_id,
                        'attachments' => $attachments,
                        'duplicates' => $duplicates_count,
                        'errors' => $errors_count
                    ]);
                } else {
                    $errors++;
                    $logger->warning('Error procesando producto', [
                        'product_id' => $product_id,
                        'result' => $result
                    ]);
                }
            }
            
            $end_time = microtime(true);
            $end_memory = memory_get_usage(true);
            $duration = $end_time - $start_time;
            $memory_used = $end_memory - $start_memory;
            
            // Verificar imágenes en media library
            $total_attachments = 0;
            foreach ($product_ids_test as $product_id) {
                $attachments = MapProduct::get_attachments_by_article_id($product_id);
                $total_attachments += count($attachments);
            }
            
            // Verificar metadatos
            $meta_checks = [
                '_verial_article_id' => 0,
                '_verial_image_hash' => 0,
                '_verial_image_order' => 0
            ];
            
            foreach ($product_ids_test as $product_id) {
                $attachments = MapProduct::get_attachments_by_article_id($product_id);
                foreach ($attachments as $attachment_id) {
                    foreach (array_keys($meta_checks) as $meta_key) {
                        $meta_value = get_post_meta($attachment_id, $meta_key, true);
                        if (!empty($meta_value)) {
                            $meta_checks[$meta_key]++;
                        }
                    }
                }
            }
            
            return [
                'success' => true,
                'phase' => 1,
                'processed' => $processed,
                'errors' => $errors,
                'duplicates' => $duplicates,
                'total_images' => $total_images,
                'total_attachments' => $total_attachments,
                'duration' => round($duration, 2),
                'memory_used' => round($memory_used / 1024 / 1024, 2),
                'meta_checks' => $meta_checks,
                'message' => 'Fase 1 ejecutada correctamente',
                'total_products_available' => $total_products_available,
                'products_requested' => $num_products,
                'products_processed' => count($product_ids_test),
                'session_number' => $session_number
            ];
            
        } catch (\Exception $e) {
            $logger->error('Excepción en test de Fase 1', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return [
                'success' => false,
                'phase' => 1,
                'error' => $e->getMessage(),
                'message' => 'Error ejecutando Fase 1',
                'trace' => $e->getTraceAsString()
            ];
        }
    }
    
    /**
     * Ejecuta el test de Fase 2
     */
    private static function run_phase2_test(int $num_products): array {
        $logger = new Logger('test-desarrollo-fase2');
        $apiConnector = ApiConnector::get_instance();
        $batchProcessor = new BatchProcessor($apiConnector, $logger);
        
        $start_time = microtime(true);
        $start_memory = memory_get_usage(true);
        
        try {
            // Procesar batch de productos
            $inicio = 1;
            $fin = $num_products;
            
            $result = $batchProcessor->processProductBatch($inicio, $fin, 10);
            
            $end_time = microtime(true);
            $end_memory = memory_get_usage(true);
            $duration = $end_time - $start_time;
            $memory_used = $end_memory - $start_memory;
            
            $success = $result['success'] ?? false;
            $processed = $result['processed'] ?? 0;
            $errors = $result['errors'] ?? 0;
            $skipped = $result['skipped'] ?? 0;
            
            // Verificar productos con imágenes
            $products_with_images = 0;
            $products_without_images = 0;
            $total_product_images = 0;
            
            $args = [
                'post_type' => 'product',
                'posts_per_page' => $num_products,
                'meta_query' => [
                    [
                        'key' => '_verial_product_id',
                        'compare' => 'EXISTS'
                    ]
                ],
                'orderby' => 'ID',
                'order' => 'DESC'
            ];
            
            $wc_products = get_posts($args);
            
            foreach ($wc_products as $wc_product) {
                $product = wc_get_product($wc_product->ID);
                
                if (!$product) {
                    continue;
                }
                
                $image_id = $product->get_image_id();
                $gallery_ids = $product->get_gallery_image_ids();
                
                $has_images = !empty($image_id) || !empty($gallery_ids);
                
                if ($has_images) {
                    $products_with_images++;
                    $total_product_images += 1 + count($gallery_ids);
                } else {
                    $products_without_images++;
                }
            }
            
            // Verificar timeouts (revisar logs)
            $log_file = wp_upload_dir()['basedir'] . '/mi-integracion-api/logs/';
            $timeout_errors = 0;
            
            if (is_dir($log_file)) {
                $log_files = glob($log_file . '*.log');
                foreach ($log_files as $file) {
                    $content = file_get_contents($file);
                    if (strpos($content, 'timeout') !== false || strpos($content, 'Lock wait timeout') !== false) {
                        $timeout_errors++;
                    }
                }
            }
            
            return [
                'success' => $success && $errors === 0,
                'phase' => 2,
                'processed' => $processed,
                'errors' => $errors,
                'skipped' => $skipped,
                'products_with_images' => $products_with_images,
                'products_without_images' => $products_without_images,
                'total_product_images' => $total_product_images,
                'timeout_errors' => $timeout_errors,
                'duration' => round($duration, 2),
                'memory_used' => round($memory_used / 1024 / 1024, 2),
                'memory_peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'message' => $success ? 'Fase 2 ejecutada correctamente' : 'Fase 2 completada con errores'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'phase' => 2,
                'error' => $e->getMessage(),
                'message' => 'Error ejecutando Fase 2'
            ];
        }
    }
    
    /**
     * Renderiza la página HTML
     */
    private static function render_page(?array $result, ?array $connection_test = null): void {
        ?>
        <div class="wrap">
            <h1>🧪 Tests de Desarrollo - Arquitectura en Dos Fases</h1>
            
            <div class="notice notice-info">
                <p><strong>ℹ️ Información:</strong> Estos tests ejecutan la sincronización real con la API de Verial.
                Asegúrate de tener conexión a internet y que la API esté disponible.</p>
            </div>
            
            <?php if ($connection_test): ?>
                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h2>🔌 Verificación de Conexión con Verial</h2>
                    
                    <div class="notice notice-<?php echo $connection_test['success'] ? 'success' : ($connection_test['status'] === 'warning' ? 'warning' : 'error'); ?> is-dismissible">
                        <p><strong>
                            <?php 
                            if ($connection_test['success']) {
                                echo '✅ ' . esc_html($connection_test['message']);
                            } elseif ($connection_test['status'] === 'warning') {
                                echo '⚠️ ' . esc_html($connection_test['message']);
                            } else {
                                echo '❌ ' . esc_html($connection_test['message']);
                            }
                            ?>
                        </strong></p>
                    </div>
                    
                    <table class="widefat">
                        <tr>
                            <th colspan="2" style="background: #f0f0f0;"><strong>Configuración</strong></th>
                        </tr>
                        <tr>
                            <th>Número de sesión:</th>
                            <td>
                                <?php if ($connection_test['session']['status'] === 'ok'): ?>
                                    <span style="color: green;">✅ <?php echo esc_html($connection_test['session']['number']); ?></span>
                                <?php else: ?>
                                    <span style="color: red;">❌ No configurado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ($connection_test['config']['status'] === 'ok'): ?>
                        <tr>
                            <th>URL Base:</th>
                            <td>
                                <span style="color: green;">✅ <?php echo esc_html($connection_test['config']['base_url']); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Tiempo total de verificación:</th>
                            <td><?php echo esc_html($connection_test['total_time']); ?> segundos</td>
                        </tr>
                        
                        <tr>
                            <th colspan="2" style="background: #f0f0f0;"><strong>Test: GetPaisesWS</strong></th>
                        </tr>
                        <tr>
                            <th>Estado:</th>
                            <td>
                                <?php if ($connection_test['connection_test']['status'] === 'success'): ?>
                                    <span style="color: green;">✅ Conectado</span>
                                <?php elseif ($connection_test['connection_test']['status'] === 'error'): ?>
                                    <span style="color: red;">❌ Error</span>
                                <?php else: ?>
                                    <span style="color: orange;">⚠️ Excepción</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Tiempo de respuesta:</th>
                            <td><?php echo esc_html($connection_test['connection_test']['response_time']); ?> ms</td>
                        </tr>
                        <?php if ($connection_test['connection_test']['status'] === 'success' && isset($connection_test['connection_test']['data'])): ?>
                            <tr>
                                <th>Países obtenidos:</th>
                                <td><?php echo esc_html($connection_test['connection_test']['data']['paises_count']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <?php if ($connection_test['connection_test']['error']): ?>
                            <tr>
                                <th>Error:</th>
                                <td style="color: red;"><?php echo esc_html($connection_test['connection_test']['error']); ?></td>
                            </tr>
                        <?php endif; ?>
                        
                        <tr>
                            <th colspan="2" style="background: #f0f0f0;"><strong>Test: GetArticulosWS</strong></th>
                        </tr>
                        <tr>
                            <th>Estado:</th>
                            <td>
                                <?php if ($connection_test['products_test']['status'] === 'success'): ?>
                                    <span style="color: green;">✅ Conectado</span>
                                <?php elseif ($connection_test['products_test']['status'] === 'error'): ?>
                                    <span style="color: red;">❌ Error</span>
                                <?php else: ?>
                                    <span style="color: orange;">⚠️ Excepción</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Tiempo de respuesta:</th>
                            <td><?php echo esc_html($connection_test['products_test']['response_time']); ?> ms</td>
                        </tr>
                        <?php if ($connection_test['products_test']['status'] === 'success' && isset($connection_test['products_test']['data'])): ?>
                            <tr>
                                <th>Artículos obtenidos:</th>
                                <td><?php echo esc_html($connection_test['products_test']['data']['articulos_count']); ?></td>
                            </tr>
                            <?php if (!empty($connection_test['products_test']['data']['first_product_id'])): ?>
                                <tr>
                                    <th>Primer ID de producto:</th>
                                    <td><?php echo esc_html($connection_test['products_test']['data']['first_product_id']); ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($connection_test['products_test']['error']): ?>
                            <tr>
                                <th>Error:</th>
                                <td style="color: red;"><?php echo esc_html($connection_test['products_test']['error']); ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            <?php endif; ?>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>🔌 Verificar Conexión con Verial</h2>
                <p>Verifica que la conexión con la API de Verial esté funcionando correctamente antes de ejecutar los tests.</p>
                
                <form method="post" action="<?php echo admin_url('admin.php?page=mi-integracion-api-tests&action=test_connection'); ?>">
                    <?php wp_nonce_field('test_connection'); ?>
                    <p class="submit">
                        <button type="submit" class="button button-secondary">🔍 Verificar Conexión</button>
                    </p>
                </form>
            </div>

            <?php if ($result): ?>
                <div class="notice notice-<?php echo $result['success'] ? 'success' : 'error'; ?> is-dismissible">
                    <p><strong><?php echo $result['success'] ? '✅' : '❌'; ?> <?php echo esc_html($result['message']); ?></strong></p>
                </div>
                
                <div class="card" style="max-width: 800px; margin-top: 20px;">
                    <h2>📊 Resultados del Test - Fase <?php echo esc_html($result['phase']); ?></h2>
                    
                    <?php if ($result['phase'] === 1): ?>
                        <table class="widefat">
                            <?php if (isset($result['error'])): ?>
                                <tr>
                                    <th colspan="2" style="color: red;">
                                        <strong>❌ Error:</strong> <?php echo esc_html($result['error']); ?>
                                    </th>
                                </tr>
                                <?php if (isset($result['session_number'])): ?>
                                <tr>
                                    <th>Número de sesión:</th>
                                    <td><?php echo esc_html($result['session_number']); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if (isset($result['total_products_available'])): ?>
                                <tr>
                                    <th>Productos disponibles en API:</th>
                                    <td><?php echo esc_html($result['total_products_available']); ?></td>
                                </tr>
                                <?php endif; ?>
                            <?php else: ?>
                                <tr>
                                    <th>Productos disponibles en API:</th>
                                    <td><?php echo esc_html($result['total_products_available'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Productos solicitados:</th>
                                    <td><?php echo esc_html($result['products_requested'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Productos procesados:</th>
                                    <td><?php echo esc_html($result['processed']); ?></td>
                                </tr>
                                <tr>
                                    <th>Errores:</th>
                                    <td><?php echo esc_html($result['errors']); ?></td>
                                </tr>
                                <tr>
                                    <th>Duplicados detectados:</th>
                                    <td><?php echo esc_html($result['duplicates']); ?></td>
                                </tr>
                                <tr>
                                    <th>Total de imágenes procesadas:</th>
                                    <td><?php echo esc_html($result['total_images']); ?></td>
                                </tr>
                                <tr>
                                    <th>Imágenes en media library:</th>
                                    <td><?php echo esc_html($result['total_attachments']); ?></td>
                                </tr>
                                <tr>
                                    <th>Tiempo total:</th>
                                    <td><?php echo esc_html($result['duration']); ?> segundos</td>
                                </tr>
                                <tr>
                                    <th>Memoria usada:</th>
                                    <td><?php echo esc_html($result['memory_used']); ?> MB</td>
                                </tr>
                                <tr>
                                    <th>Número de sesión:</th>
                                    <td><?php echo esc_html($result['session_number'] ?? 'N/A'); ?></td>
                                </tr>
                                <tr>
                                    <th>Metadatos verificados:</th>
                                    <td>
                                        _verial_article_id: <?php echo esc_html($result['meta_checks']['_verial_article_id']); ?><br>
                                        _verial_image_hash: <?php echo esc_html($result['meta_checks']['_verial_image_hash']); ?><br>
                                        _verial_image_order: <?php echo esc_html($result['meta_checks']['_verial_image_order']); ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    <?php else: ?>
                        <table class="widefat">
                            <tr>
                                <th>Éxito:</th>
                                <td><?php echo $result['success'] ? '✅ Sí' : '❌ No'; ?></td>
                            </tr>
                            <tr>
                                <th>Productos procesados:</th>
                                <td><?php echo esc_html($result['processed']); ?></td>
                            </tr>
                            <tr>
                                <th>Errores:</th>
                                <td><?php echo esc_html($result['errors']); ?></td>
                            </tr>
                            <tr>
                                <th>Productos con imágenes:</th>
                                <td><?php echo esc_html($result['products_with_images']); ?></td>
                            </tr>
                            <tr>
                                <th>Productos sin imágenes:</th>
                                <td><?php echo esc_html($result['products_without_images']); ?></td>
                            </tr>
                            <tr>
                                <th>Total de imágenes asignadas:</th>
                                <td><?php echo esc_html($result['total_product_images']); ?></td>
                            </tr>
                            <tr>
                                <th>Errores de timeout:</th>
                                <td><?php echo esc_html($result['timeout_errors']); ?></td>
                            </tr>
                            <tr>
                                <th>Tiempo total:</th>
                                <td><?php echo esc_html($result['duration']); ?> segundos</td>
                            </tr>
                            <tr>
                                <th>Memoria usada:</th>
                                <td><?php echo esc_html($result['memory_used']); ?> MB</td>
                            </tr>
                            <tr>
                                <th>Memoria pico:</th>
                                <td><?php echo esc_html($result['memory_peak']); ?> MB</td>
                            </tr>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Fase 1: Sincronización de Imágenes</h2>
                <p>Esta fase sincroniza las imágenes desde Verial y las guarda en la media library de WordPress.</p>
                
                <form method="post" action="<?php echo admin_url('admin.php?page=mi-integracion-api-tests&action=run_phase1'); ?>">
                    <?php wp_nonce_field('test_phase1'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="num_products_phase1">Número de productos:</label>
                            </th>
                            <td>
                                <input type="number" id="num_products_phase1" name="num_products" value="10" min="1" max="100" class="small-text">
                                <p class="description">Cantidad de productos a procesar (recomendado: 10 para pruebas)</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">🚀 Ejecutar Fase 1</button>
                    </p>
                </form>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>Fase 2: Sincronización de Productos</h2>
                <p>Esta fase sincroniza los productos y asigna las imágenes desde la media library.</p>
                <p class="description"><strong>⚠️ Importante:</strong> Ejecuta primero la Fase 1 para que las imágenes estén disponibles.</p>
                
                <form method="post" action="<?php echo admin_url('admin.php?page=mi-integracion-api-tests&action=run_phase2'); ?>">
                    <?php wp_nonce_field('test_phase2'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="num_products_phase2">Número de productos:</label>
                            </th>
                            <td>
                                <input type="number" id="num_products_phase2" name="num_products" value="10" min="1" max="100" class="small-text">
                                <p class="description">Cantidad de productos a procesar (recomendado: 10 para pruebas)</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <button type="submit" class="button button-primary">🚀 Ejecutar Fase 2</button>
                    </p>
                </form>
            </div>
            
            <div class="card" style="max-width: 800px; margin-top: 20px;">
                <h2>📖 Guía de Uso</h2>
                <ol>
                    <li><strong>Ejecuta Fase 1 primero</strong>: Sincroniza las imágenes desde Verial</li>
                    <li><strong>Espera a que termine</strong>: Verifica los resultados mostrados</li>
                    <li><strong>Ejecuta Fase 2 después</strong>: Sincroniza productos y asigna imágenes</li>
                    <li><strong>Verifica resultados</strong>: Revisa que productos tienen imágenes asignadas</li>
                </ol>
                
                <h3>💡 Consejos</h3>
                <ul>
                    <li>Empieza con 1-5 productos para pruebas rápidas</li>
                    <li>Aumenta gradualmente a 10, 20, 50 productos</li>
                    <li>Revisa los logs en <code>wp-content/uploads/mi-integracion-api/logs/</code></li>
                    <li>Haz backup de Local antes de ejecutar tests masivos</li>
                </ul>
            </div>
        </div>
        <?php
    }
}

