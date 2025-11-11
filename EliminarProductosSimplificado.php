<?php
/**
 * Script para eliminar todos los productos de WooCommerce con consola en tiempo real
 *
 * @package     VerialIntegration
 * @version     1.2.0
 * @author      Sistema de Integración Verial
 */

// Asegurar contexto WordPress
if (!defined('ABSPATH')) {
    // Buscar wp-load.php en diferentes ubicaciones
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
        die('Error: No se pudo cargar WordPress.');
    }
}

// Solo permitir acceso a administradores
if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
    wp_die('❌ Acceso denegado. Solo administradores pueden ejecutar esta acción.');
}

// Función principal para eliminar productos con consola en tiempo real
function eliminar_todos_los_productos_con_consola($dry_run = true) {
    global $wpdb;

    // Obtener todos los productos (incluyendo variaciones)
    $args = array(
        'post_type'      => array('product', 'product_variation'),
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'orderby'        => 'ID',
        'order'          => 'ASC'
    );
    
    $productos = get_posts($args);
    $total_productos = count($productos);

    // Enviar encabezados para streaming
    if (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    
    echo "<!DOCTYPE html>
    <html>
    <head>
        <title>Eliminando Productos - WooCommerce Cleaner</title>
        <style>
            body { font-family: 'Courier New', monospace; background: #000; color: #00ff00; padding: 20px; margin: 0; }
            .console { background: #000; color: #00ff00; padding: 20px; border: 1px solid #00ff00; min-height: 400px; overflow-y: auto; }
            .log-line { margin: 2px 0; font-size: 14px; }
            .success { color: #00ff00; }
            .warning { color: #ffff00; }
            .error { color: #ff0000; }
            .info { color: #00ffff; }
            .progress { background: #003300; padding: 10px; margin: 10px 0; border: 1px solid #00ff00; }
            .header { background: #003300; padding: 15px; margin-bottom: 20px; border: 1px solid #00ff00; }
        </style>
    </head>
    <body>
    <div class='header'>
        <h1>📦 ELIMINANDO PRODUCTOS DE WOOCOMMERCE</h1>
        <p><strong>Modo:</strong> " . ($dry_run ? "SIMULACIÓN" : "ELIMINACIÓN REAL") . "</p>
        <p><strong>Total productos a procesar:</strong> $total_productos</p>
    </div>
    <div class='console' id='console'>
        <div class='log-line info'>[INICIO] Comenzando proceso de eliminación...</div>";
    
    flush();
    ob_flush();

    if ($total_productos == 0) {
        echo "<div class='log-line warning'>[INFO] No hay productos para eliminar.</div>";
        echo "</div></body></html>";
        return;
    }

    $eliminados = 0;
    $errores = 0;
    $procesados = 0;

    foreach ($productos as $id) {
        $procesados++;
        $producto = wc_get_product($id);
        
        if (!$producto) {
            echo "<div class='log-line error'>[ERROR] Producto ID $id no encontrado</div>";
            $errores++;
            flush();
            ob_flush();
            continue;
        }

        $nombre = $producto->get_name();
        $sku = $producto->get_sku() ?: 'N/A';
        $tipo = $producto->get_type();
        $porcentaje = round(($procesados / $total_productos) * 100, 1);

        if ($dry_run) {
            echo "<div class='log-line warning'>[SIMULACIÓN] Producto $procesados/$total_productos ({$porcentaje}%) - ID: $id - $tipo - '$nombre' - SKU: $sku</div>";
            $eliminados++;
        } else {
            // Eliminar permanentemente
            $resultado = wp_delete_post($id, true);
            if ($resultado) {
                echo "<div class='log-line success'>[ELIMINADO] Producto $procesados/$total_productos ({$porcentaje}%) - ID: $id - $tipo - '$nombre' - SKU: $sku</div>";
                $eliminados++;
            } else {
                echo "<div class='log-line error'>[FALLIDO] Producto $procesados/$total_productos ({$porcentaje}%) - ID: $id - $tipo - '$nombre' - SKU: $sku</div>";
                $errores++;
            }
        }

        // Scroll automático
        echo "<script>
            var consoleDiv = document.getElementById('console');
            consoleDiv.scrollTop = consoleDiv.scrollHeight;
        </script>";

        flush();
        ob_flush();
        
        // Pequeña pausa para mejor visualización
        usleep(100000); // 0.1 segundos
    }

    echo "<div class='log-line info'>[FINALIZADO] Proceso completado</div>";
    echo "<div class='progress'>
            <strong>RESUMEN FINAL:</strong><br>
            ✅ Productos procesados: $procesados<br>
            ✅ Eliminados correctamente: $eliminados<br>
            ❌ Errores: $errores<br>
            📊 Porcentaje completado: 100%
          </div>";
    echo "</div></body></html>";
}

// Interfaz web
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $confirmacion = isset($_POST['confirm']) && $_POST['confirm'] === 'yes';
    $dry_run = !isset($_POST['real_delete']) || $_POST['real_delete'] !== '1';

    if (!$confirmacion) {
        mostrar_formulario_con_error();
    } else {
        eliminar_todos_los_productos_con_consola($dry_run);
    }
} else {
    mostrar_formulario();
}

function mostrar_formulario() {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Eliminar Todos los Productos - WooCommerce Cleaner</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f0f0f0; margin: 0; padding: 0; }
            .container { max-width: 800px; margin: 20px auto; padding: 20px; }
            .header { background: #dc3545; color: white; padding: 20px; border-radius: 10px 10px 0 0; }
            .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; border: 1px solid #ddd; border-top: none; }
            .warning-box { background: #f8d7da; color: #721c24; padding: 20px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px 0; }
            .info-box { background: #d1ecf1; color: #0c5460; padding: 20px; border: 1px solid #bee5eb; border-radius: 5px; margin: 20px 0; }
            .form-group { margin: 20px 0; }
            .checkbox { margin: 10px 0; }
            .submit-btn { background: #dc3545; color: white; padding: 15px 30px; border: none; border-radius: 5px; font-size: 18px; font-weight: bold; cursor: pointer; width: 100%; }
            .submit-btn:hover { background: #c82333; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1>🚨 ELIMINAR TODOS LOS PRODUCTOS DE WOOCOMMERCE</h1>
            </div>
            <div class="content">
                <div class="warning-box">
                    <h2>⚠ ADVERTENCIA CRÍTICA</h2>
                    <p><strong>Esta acción eliminará TODOS los productos de tu tienda WooCommerce, incluyendo:</strong></p>
                    <ul>
                        <li>Productos simples y variables</li>
                        <li>Variaciones de productos</li>
                        <li>Todos los metadatos asociados</li>
                        <li>Precios, inventario, descripciones, etc.</li>
                    </ul>
                    <p style="font-size: 18px; font-weight: bold; color: #dc3545;">🔴 ESTA ACCIÓN NO SE PUEDE DESHACER</p>
                </div>

                <div class="info-box">
                    <h3>ℹ CÓMO FUNCIONA</h3>
                    <p>• <strong>Modo Simulación:</strong> Muestra qué productos se eliminarían sin hacer cambios reales</p>
                    <p>• <strong>Modo Real:</strong> Elimina permanentemente todos los productos</p>
                    <p>• <strong>Consola en tiempo real:</strong> Verás el progreso de eliminación uno por uno</p>
                </div>

                <form method="post">
                    <div class="form-group">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="confirm" value="yes" required>
                                <strong style="color: #dc3545; font-size: 16px;">CONFIRMO que quiero eliminar todos los productos de mi tienda WooCommerce</strong>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="real_delete" value="1">
                                <strong>Eliminar productos realmente (desmarca para modo simulación)</strong>
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <input type="submit" value="🗑 INICIAR ELIMINACIÓN DE TODOS LOS PRODUCTOS" class="submit-btn">
                    </div>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
}

function mostrar_formulario_con_error() {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error - WooCommerce Cleaner</title>
        <style>
            body { font-family: Arial, sans-serif; background: #f0f0f0; margin: 0; padding: 0; }
            .container { max-width: 800px; margin: 20px auto; padding: 20px; }
            .error-box { background: #f8d7da; color: #721c24; padding: 20px; border: 1px solid #f5c6cb; border-radius: 5px; margin: 20px 0; }
            .back-btn { background: #6c757d; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block; }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="error-box">
                <h2>❌ ERROR DE CONFIRMACIÓN</h2>
                <p>Debes confirmar marcando la casilla de verificación para proceder con la eliminación.</p>
                <a href="" class="back-btn">← Volver al formulario</a>
            </div>
        </div>
    </body>
    </html>
    <?php
}
?>
