<?php

declare(strict_types=1);

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper para gestionar el favicon del plugin
 * 
 * @package MiIntegracionApi\Helpers
 * @since 2.0.0
 * @author Christian
 */
class FaviconHelper
{
    /**
     * Agrega el favicon del plugin al head del sitio
     * 
     * @return void
     */
    public static function add_favicon(): void
    {
        $favicon_url = plugin_dir_url(dirname(__DIR__)) . 'assets/images/logo.ico';
        
        add_action('wp_head', function() use ($favicon_url) {
            echo '<link rel="icon" type="image/x-icon" href="' . esc_url($favicon_url) . '">' . "\n";
            echo '<link rel="shortcut icon" type="image/x-icon" href="' . esc_url($favicon_url) . '">' . "\n";
        });
        
        add_action('admin_head', function() use ($favicon_url) {
            echo '<link rel="icon" type="image/x-icon" href="' . esc_url($favicon_url) . '">' . "\n";
            echo '<link rel="shortcut icon" type="image/x-icon" href="' . esc_url($favicon_url) . '">' . "\n";
        });
    }
}
