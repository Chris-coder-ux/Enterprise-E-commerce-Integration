<?php

declare(strict_types=1);

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper para gestionar el logo del plugin
 * 
 * Proporciona métodos estáticos para obtener URLs del logo
 * en diferentes formatos y tamaños.
 * 
 * @package MiIntegracionApi\Helpers
 * @since 2.0.0
 * @author Christian
 */
class LogoHelper
{
    /**
     * Ruta base de los assets del plugin
     */
    private static string $assets_url;

    /**
     * Inicializa la URL base de assets
     */
    private static function init(): void
    {
        if (!isset(self::$assets_url)) {
            self::$assets_url = plugin_dir_url(dirname(__DIR__)) . 'assets/';
        }
    }

    /**
     * Obtiene la URL del logo en formato PNG
     * 
     * @param string $size Tamaño del logo (small, medium, large)
     * @return string URL del logo
     */
    public static function get_logo_url(string $size = 'medium'): string
    {
        self::init();
        
        $sizes = [
            'small' => 'logo-16.png',   // 16x16 para menú de plugins
            'medium' => 'logo.png',    // 32x32 para menú lateral
            'large' => 'logo.png'      // 64x64 para header
        ];

        return self::$assets_url . 'images/' . ($sizes[$size] ?? $sizes['medium']);
    }

    /**
     * Obtiene la URL del logo en formato SVG
     * 
     * @return string URL del logo SVG
     */
    public static function get_logo_svg_url(): string
    {
        self::init();
        return self::$assets_url . 'images/logo.svg';
    }

    /**
     * Obtiene la URL del favicon
     * 
     * @return string URL del favicon
     */
    public static function get_favicon_url(): string
    {
        self::init();
        return self::$assets_url . 'images/logo.ico';
    }

    /**
     * Genera el HTML del logo con atributos personalizados
     * 
     * @param array $attributes Atributos HTML adicionales
     * @param string $size Tamaño del logo
     * @return string HTML del logo
     */
    public static function get_logo_html(array $attributes = [], string $size = 'medium'): string
    {
        $default_attributes = [
            'src' => self::get_logo_url($size),
            'alt' => __('Mi Integración API', 'mi-integracion-api'),
            'class' => 'mi-integracion-api-logo',
            'width' => self::get_logo_width($size),
            'height' => self::get_logo_height($size)
        ];

        $attributes = array_merge($default_attributes, $attributes);
        
        $html = '<img';
        foreach ($attributes as $key => $value) {
            $html .= ' ' . esc_attr($key) . '="' . esc_attr($value) . '"';
        }
        $html .= '>';

        return $html;
    }

    /**
     * Obtiene el ancho del logo según el tamaño
     * 
     * @param string $size Tamaño del logo
     * @return int Ancho en píxeles
     */
    private static function get_logo_width(string $size): int
    {
        $widths = [
            'small' => 16,
            'medium' => 32,
            'large' => 64
        ];

        return $widths[$size] ?? $widths['medium'];
    }

    /**
     * Obtiene la altura del logo según el tamaño
     * 
     * @param string $size Tamaño del logo
     * @return int Altura en píxeles
     */
    private static function get_logo_height(string $size): int
    {
        $heights = [
            'small' => 16,
            'medium' => 32,
            'large' => 64
        ];

        return $heights[$size] ?? $heights['medium'];
    }

    /**
     * Genera CSS inline para el logo
     * 
     * @param string $size Tamaño del logo
     * @return string CSS inline
     */
    public static function get_logo_css(string $size = 'medium'): string
    {
        $width = self::get_logo_width($size);
        $height = self::get_logo_height($size);
        
        return sprintf(
            'width: %dpx; height: %dpx; background-image: url(%s); background-size: contain; background-repeat: no-repeat;',
            $width,
            $height,
            self::get_logo_url($size)
        );
    }
}
