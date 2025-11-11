<?php

namespace MiIntegracionApi\Admin;

/**
 * Página de Debug para diagnosticar problemas del menú
 */
class DebugPage
{
    private $logger;

    public function __construct($logger)
    {
        $this->logger = $logger;
    }

    /**
     * Renderizar la página de debug
     */
    public function render(): void
    {
        // Configuración
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        ini_set('log_errors', 1);

        // Función de logging
        $debug_log = function($message) {
            $timestamp = date('Y-m-d H:i:s');
            $output = "[$timestamp] $message";
            echo $output . "<br>";
            $this->logger->info($output);
        };

        ?>
        <div class="wrap">
            <h1>🔍 Debug Completo del Menú - Mi Integración API</h1>
            <p><strong>Fecha:</strong> <?php echo date('Y-m-d H:i:s'); ?></p>
            <p><strong>WordPress:</strong> <?php echo get_bloginfo('version'); ?></p>
            <p><strong>Plugin:</strong> <?php echo defined('MIA_VERSION') ? constant('MIA_VERSION') : 'NO CARGADO'; ?></p>

            <?php
            $debug_log("🚀 INICIANDO DEBUG COMPLETO DEL MENÚ EN WORDPRESS");
            $debug_log("=================================================");

            // 1. VERIFICAR ENTORNO WORDPRESS
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; background-color: #d1ecf1;">';
            echo '<h2>1️⃣ VERIFICANDO ENTORNO WORDPRESS:</h2>';
            $debug_log("   ABSPATH: " . (defined('ABSPATH') ? ABSPATH : 'NO DEFINIDO'));
            $debug_log("   is_admin(): " . (is_admin() ? 'true' : 'false'));
            $debug_log("   current_user_can('manage_options'): " . (current_user_can('manage_options') ? 'true' : 'false'));
            $debug_log("   current_user_can('edit_posts'): " . (current_user_can('edit_posts') ? 'true' : 'false'));
            echo '</div>';

            // 2. VERIFICAR PLUGIN
            $pluginStatus = defined('MIA_VERSION') ? 'success' : 'error';
            $bgColor = $pluginStatus === 'success' ? '#d4edda' : '#f8d7da';
            $borderColor = $pluginStatus === 'success' ? '#c3e6cb' : '#f5c6cb';
            
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid ' . $borderColor . '; border-radius: 5px; background-color: ' . $bgColor . ';">';
            echo '<h2>2️⃣ VERIFICANDO PLUGIN:</h2>';
            if (defined('MIA_VERSION')) {
                $debug_log("   ✅ MIA_VERSION: " . constant('MIA_VERSION'));
                $debug_log("   ✅ MIA_PLUGIN_DIR: " . constant('MIA_PLUGIN_DIR'));
                $debug_log("   ✅ MIA_INITIALIZED: " . (defined('MIA_INITIALIZED') ? 'true' : 'false'));
            } else {
                $debug_log("   ❌ Plugin no está cargado");
                echo '<h3>❌ ERROR CRÍTICO: Plugin no está cargado</h3>';
                echo '<p>El plugin no se ha inicializado correctamente. Verifica:</p>';
                echo '<ul>';
                echo '<li>Que el plugin esté activado</li>';
                echo '<li>Que no haya errores en el log de WordPress</li>';
                echo '<li>Que el archivo mi-integracion-api.php se cargue correctamente</li>';
                echo '</ul>';
                echo '</div>';
                echo '</div>';
                return;
            }
            echo '</div>';

            // 3. VERIFICAR SISTEMA DE MENÚS (AdminMenu.php)
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; background-color: #d1ecf1;">';
            echo '<h2>3️⃣ VERIFICANDO SISTEMA DE MENÚS:</h2>';
            $debug_log("   Sistema de menús: AdminMenu.php (nativo de WordPress)");
            $debug_log("   ✅ Menús registrados directamente con add_menu_page() y add_submenu_page()");
            echo '</div>';

            // 4. VERIFICAR CLASES ADMIN
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; background-color: #d1ecf1;">';
            echo '<h2>4️⃣ VERIFICANDO CLASES ADMIN:</h2>';
            $adminClasses = [
                'MiIntegracionApi\\Admin\\AdminMenu',
                'MiIntegracionApi\\Admin\\DashboardPageView',
                'MiIntegracionApi\\Admin\\EndpointsPage',
                'MiIntegracionApi\\Admin\\CachePageView'
            ];

            foreach ($adminClasses as $class) {
                if (class_exists($class)) {
                    $debug_log("   ✅ $class existe");
                    
                    // Verificar métodos específicos para clases de renderizado
                    if (in_array($class, ['MiIntegracionApi\\Admin\\DashboardPageView', 'MiIntegracionApi\\Admin\\EndpointsPage', 'MiIntegracionApi\\Admin\\CachePageView'])) {
                        $methods = get_class_methods($class);
                        $debug_log("     Métodos disponibles: " . implode(', ', $methods));
                    }
                } else {
                    $debug_log("   ❌ $class NO existe");
                }
            }
            echo '</div>';

            // 5. VERIFICAR DEPENDENCY CONTAINER
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; background-color: #d1ecf1;">';
            echo '<h2>5️⃣ VERIFICANDO DEPENDENCY CONTAINER:</h2>';
            if (class_exists('MiIntegracionApi\\Core\\DependencyContainer')) {
                try {
                    $container = \MiIntegracionApi\Core\DependencyContainer::getInstance();
                    $debug_log("   ✅ DependencyContainer obtenido");
                    
                    // Verificar servicios críticos
                    $criticalServices = [
                        'MiIntegracionApi\\Admin\\TemplateRenderer'
                    ];
                    
                    foreach ($criticalServices as $service) {
                        if ($container->has($service)) {
                            $debug_log("     ✅ $service disponible");
                        } else {
                            $debug_log("     ❌ $service NO disponible");
                        }
                    }
                    
                } catch (Exception $e) {
                    $debug_log("   ❌ Error obteniendo DependencyContainer: " . $e->getMessage());
                }
            } else {
                $debug_log("   ❌ DependencyContainer no existe");
            }
            echo '</div>';

            // 6. VERIFICAR HOOKS REGISTRADOS
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; background-color: #d1ecf1;">';
            echo '<h2>6️⃣ VERIFICANDO HOOKS REGISTRADOS:</h2>';
            global $wp_filter;

            if (isset($wp_filter['admin_menu'])) {
                $callbackCount = count($wp_filter['admin_menu']->callbacks);
                $debug_log("   ✅ Hook 'admin_menu' registrado con $callbackCount callbacks");
                
                foreach ($wp_filter['admin_menu']->callbacks as $priority => $callbacks) {
                    foreach ($callbacks as $id => $callback) {
                        $debug_log("     Callback[$priority]: $id");
                    }
                }
            } else {
                $debug_log("   ❌ Hook 'admin_menu' NO registrado");
            }
            echo '</div>';

            // 7. VERIFICAR VARIABLES GLOBALES DE MENÚ
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; background-color: #d1ecf1;">';
            echo '<h2>7️⃣ VERIFICANDO VARIABLES GLOBALES DE MENÚ:</h2>';
            global $menu, $submenu;

            if (isset($menu) && is_array($menu)) {
                $debug_log("   ✅ Variable global \$menu tiene " . count($menu) . " elementos");
                
                // Buscar nuestro menú específico
                $foundOurMenu = false;
                foreach ($menu as $index => $item) {
                    if (is_array($item) && isset($item[2]) && $item[2] === 'mi-integracion-api') {
                        $debug_log("     🎯 ENCONTRADO NUESTRO MENÚ en índice $index:");
                        $debug_log("       Título: " . $item[0]);
                        $debug_log("       Menú: " . $item[1]);
                        $debug_log("       Slug: " . $item[2]);
                        $debug_log("       Capacidad: " . $item[3]);
                        $debug_log("       Callback: " . (is_callable($item[4]) ? 'CALLABLE' : 'NO CALLABLE'));
                        $debug_log("       Icono: " . $item[5]);
                        $debug_log("       Posición: " . $item[6]);
                        $foundOurMenu = true;
                        break;
                    }
                }
                
                if (!$foundOurMenu) {
                    $debug_log("     ❌ NUESTRO MENÚ NO ENCONTRADO en \$menu");
                    $debug_log("     Menús disponibles:");
                    foreach ($menu as $index => $item) {
                        if (is_array($item) && isset($item[2])) {
                            $debug_log("       [$index] {$item[0]} -> {$item[2]} (capacidad: {$item[3]})");
                        }
                    }
                }
            } else {
                $debug_log("   ❌ Variable global \$menu no está definida o está vacía");
            }

            if (isset($submenu) && is_array($submenu)) {
                $debug_log("   ✅ Variable global \$submenu tiene " . count($submenu) . " elementos");
                
                if (isset($submenu['mi-integracion-api'])) {
                    $debug_log("     🎯 NUESTROS SUBMENÚS encontrados:");
                    foreach ($submenu['mi-integracion-api'] as $index => $item) {
                        $debug_log("     [$index] {$item[0]} -> {$item[2]} (capacidad: {$item[3]})");
                    }
                } else {
                    $debug_log("     ❌ NUESTROS SUBMENÚS NO encontrados");
                    $debug_log("     Submenús disponibles:");
                    foreach ($submenu as $parent => $items) {
                        $debug_log("       Parent '$parent': " . count($items) . " items");
                    }
                }
            } else {
                $debug_log("   ❌ Variable global \$submenu no está definida o está vacía");
            }
            echo '</div>';

            // 8. INTENTAR CREAR EL MENÚ MANUALMENTE
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px; background-color: #fff3cd;">';
            echo '<h2>8️⃣ INTENTANDO CREAR EL MENÚ MANUALMENTE:</h2>';
            try {
                $debug_log("   🔧 Llamando a add_menu_page manualmente...");
                
                $hookname = add_menu_page(
                    'Mi Integración API (TEST)',
                    'Mi Integración API (TEST)',
                    'manage_options',
                    'mi-integracion-api-test',
                    function() {
                        echo '<div class="wrap"><h1>Menú de Test</h1><p>Este es un menú de prueba.</p></div>';
                    },
                    'dashicons-admin-generic',
                    999
                );
                
                $debug_log("   ✅ Menú de test creado con hookname: $hookname");
                
                // Verificar si apareció en las variables globales
                global $menu;
                if (isset($menu) && is_array($menu)) {
                    $foundTestMenu = false;
                    foreach ($menu as $index => $item) {
                        if (is_array($item) && isset($item[2]) && $item[2] === 'mi-integracion-api-test') {
                            $debug_log("     🎯 MENÚ DE TEST ENCONTRADO en índice $index");
                            $foundTestMenu = true;
                            break;
                        }
                    }
                    
                    if (!$foundTestMenu) {
                        $debug_log("     ❌ MENÚ DE TEST NO ENCONTRADO en \$menu");
                    }
                }
                
            } catch (Exception $e) {
                $debug_log("   ❌ Error creando menú de test: " . $e->getMessage());
            }
            echo '</div>';

            // 9. VERIFICAR PERMISOS DEL USUARIO
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; background-color: #d1ecf1;">';
            echo '<h2>9️⃣ VERIFICANDO PERMISOS DEL USUARARIO:</h2>';
            if (function_exists('wp_get_current_user')) {
                $currentUser = wp_get_current_user();
                $debug_log("   Usuario actual: " . $currentUser->user_login);
                $debug_log("   ID del usuario: " . $currentUser->ID);
                $debug_log("   Roles: " . implode(', ', $currentUser->roles));
                
                // Verificar capacidades específicas
                $capabilities = ['manage_options', 'edit_posts', 'edit_pages', 'activate_plugins'];
                foreach ($capabilities as $cap) {
                    if (function_exists('current_user_can')) {
                        $debug_log("     $cap: " . (current_user_can($cap) ? 'SÍ' : 'NO'));
                    }
                }
            } else {
                $debug_log("   ❌ Función wp_get_current_user no disponible");
            }
            echo '</div>';

            // 10. VERIFICAR CONFLICTOS DE PLUGINS
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #bee5eb; border-radius: 5px; background-color: #d1ecf1;">';
            echo '<h2>🔟 VERIFICANDO CONFLICTOS:</h2>';
            if (function_exists('get_plugins')) {
                $activePlugins = get_option('active_plugins');
                $debug_log("   Plugins activos: " . count($activePlugins));
                
                foreach ($activePlugins as $plugin) {
                    if (strpos($plugin, 'mi-integracion-api') !== false) {
                        $debug_log("     🎯 NUESTRO PLUGIN: $plugin");
                    }
                }
            } else {
                $debug_log("   ❌ Función get_plugins no disponible");
            }
            echo '</div>';

            // 11. VERIFICAR LOGS DE ERROR
            echo '<div style="margin: 20px 0; padding: 15px; border: 1px solid #ffeaa7; border-radius: 5px; background-color: #fff3cd;">';
            echo '<h2>1️⃣1️⃣ VERIFICANDO LOGS DE ERROR:</h2>';
            $errorLogPath = ini_get('error_log');
            if ($errorLogPath && file_exists($errorLogPath)) {
                $logSize = filesize($errorLogPath);
                $debug_log("   Log de errores: $errorLogPath (tamaño: " . round($logSize / 1024, 2) . " KB)");
                
                // Leer las últimas líneas del log
                $lines = file($errorLogPath);
                $recentLines = array_slice($lines, -20); // Últimas 20 líneas
                
                $debug_log("   Últimas líneas del log:");
                foreach ($recentLines as $line) {
                    if (strpos($line, 'MIA') !== false || strpos($line, 'mi-integracion-api') !== false) {
                        echo '<pre style="color: red; background: #f8f9fa; padding: 10px; border-radius: 3px; overflow-x: auto;">' . htmlspecialchars(trim($line)) . '</pre>';
                    }
                }
            } else {
                $debug_log("   ❌ Log de errores no disponible o no encontrado");
            }
            echo '</div>';

            $debug_log("🏁 DEBUG COMPLETO FINALIZADO");
            ?>

            <div style="margin: 20px 0; padding: 15px; border: 1px solid #c3e6cb; border-radius: 5px; background-color: #d4edda;">
                <h2>📋 RESUMEN Y RECOMENDACIONES:</h2>
                <p><strong>Este debug se ejecutó desde DENTRO de WordPress ✅</strong></p>
                <p>Si el menú sigue sin aparecer, revisa los resultados anteriores para identificar el problema específico.</p>
            </div>
        </div>
        <?php
    }
}
