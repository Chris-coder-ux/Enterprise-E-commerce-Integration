<?php
declare(strict_types=1);

/**
 * Gestor de recursos (assets) para el panel de administración
 *
 * Esta clase maneja la carga de hojas de estilo y scripts JavaScript
 * de manera eficiente en el panel de administración de WordPress.
 *
 * Características principales:
 * - Carga condicional de recursos por página
 * - Soporte para dependencias entre assets
 * - Sistema de versionado para cache busting
 * - Logging de operaciones
 * - Carga bajo demanda de recursos
 * - Soporte para estilos y scripts globales y específicos por página
 *
 * @package     MiIntegracionApi\Admin
 * @since       1.0.0
 * @version     1.0.0
 * @author      Christian <christian@example.com>
 * @copyright   Copyright (c) 2025, Your Company
 * @license     GPL-2.0+
 * @link        https://example.com/plugin-docs
 */


namespace MiIntegracionApi\Admin;

use Psr\Log\LoggerInterface;

/**
 * Gestiona la carga de recursos en el panel de administración
 *
 * Esta clase se encarga de registrar y encolar estilos y scripts
 * de manera óptima, siguiendo las mejores prácticas de WordPress.
 *
 * @package MiIntegracionApi\Admin
 * @since 1.0.0
 */
class AdminAssetManager
{
    /**
     * Instancia del logger para registrar eventos
     *
     * @var LoggerInterface $logger Instancia del logger PSR-3 compatible
     * @since 1.0.0
     */
    private LoggerInterface $logger;
    
    /**
     * Configuración de los assets a cargar
     *
     * @var array{
     *     global?: array<array{
     *         handle: string,
     *         src: string,
     *         type: 'script'|'style',
     *         dependencies?: string[],
     *         in_footer?: bool
     *     }>,
     *     pages: array<string, array<array{
     *         handle: string,
     *         src: string,
     *         type: 'script'|'style',
     *         dependencies?: string[],
     *         in_footer?: bool
     *     }>>
     * } $assetConfig Configuración estructurada de los assets
     * @since 1.0.0
     */
    private array $assetConfig;
    
    /**
     * URL base para los recursos
     *
     * @var string $assetsUrl URL absoluta al directorio de assets
     * @since 1.0.0
     */
    private string $assetsUrl;
    
    /**
     * Versión para cache busting
     *
     * @var string $version Número de versión o timestamp para evitar caché
     * @since 1.0.0
     */
    private string $version;
    
    /**
     * Constructor de la clase
     *
     * @param LoggerInterface $logger Instancia del logger
     * @param array $assetConfig Configuración de los assets
     * @param string $assetsUrl URL base de los recursos
     * @param string $version Versión para cache busting
     */
    public function __construct(
        LoggerInterface $logger, 
        array $assetConfig, 
        string $assetsUrl, 
        string $version
    ) {
        $this->logger = $logger;
        $this->assetConfig = $assetConfig;
        $this->assetsUrl = $assetsUrl;
        $this->version = $version;
    }
    
    /**
     * Encola los assets necesarios para la página actual
     *
     * Este método debe ser llamado desde el hook 'admin_enqueue_scripts'.
     * Se encarga de cargar tanto los recursos globales como los específicos
     * de cada página del panel de administración.
     *
     * @param string $hookSuffix El sufijo del hook de la página actual (ej: 'toplevel_page_mi-pagina')
     * @return void
     * @since 1.0.0
     * @hook admin_enqueue_scripts
     * @see https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
     * @throws \RuntimeException Si ocurre un error al cargar los assets
     */
    public function enqueueAssets(string $hookSuffix): void
    {
        $this->logger->info("Enqueueing assets for hook: $hookSuffix");
        
        // Enqueue global assets
        $this->enqueueGlobalAssets($hookSuffix);
        
        // Enqueue page-specific assets
        if (isset($this->assetConfig['pages'][$hookSuffix])) {
            $this->enqueuePageAssets($hookSuffix);
        }
    }
    
    /**
     * Encuela los assets globales del plugin
     *
     * Carga los recursos que deben estar disponibles en todas las páginas
     * del panel de administración del plugin cuando el hook contiene 'mi-integracion-api'.
     *
     * @param string $hookSuffix El sufijo del hook de la página actual
     * @return void
     * @since 1.0.0
     * @see AdminAssetManager::enqueueAsset() Para el proceso de carga de cada asset
     * @see AdminAssetManager::enqueueDefaultAssets() Para la carga de assets por defecto
     */
    private function enqueueGlobalAssets(string $hookSuffix): void
    {
        if (strpos($hookSuffix, 'mi-integracion-api') !== false) {
            if (isset($this->assetConfig['global']) && is_array($this->assetConfig['global'])) {
                foreach ($this->assetConfig['global'] as $asset) {
                    if (is_array($asset) && !empty($asset)) {
                        $this->enqueueAsset($asset);
                    }
                }
            } else {
                $this->logger->info('No se encontró configuración de assets globales, usando assets por defecto');
                $this->enqueueDefaultAssets();
            }
        }
    }
    
    /**
     * Encuela los assets específicos de una página
     *
     * Carga los recursos que solo son necesarios en páginas específicas
     * del panel de administración, según la configuración proporcionada.
     *
     * @param string $hookSuffix El sufijo del hook de la página actual
     * @return void
     * @since 1.0.0
     * @see AdminAssetManager::enqueueAsset() Para el proceso de carga de cada asset
     */
    private function enqueuePageAssets(string $hookSuffix): void
    {
        if (isset($this->assetConfig['pages'][$hookSuffix]) && is_array($this->assetConfig['pages'][$hookSuffix])) {
            foreach ($this->assetConfig['pages'][$hookSuffix] as $asset) {
                if (is_array($asset) && !empty($asset)) {
                    $this->enqueueAsset($asset);
                }
            }
        }
    }
    
    /**
     * Carga los assets por defecto
     *
     * Se ejecuta cuando no se encuentra una configuración específica
     * para los assets. Proporciona una configuración mínima por defecto
     * que incluye solo los estilos básicos del panel de administración.
     *
     * @return void
     * @since 1.0.0
     * @see wp_enqueue_style() Función de WordPress para encolar estilos
     * @see wp_enqueue_script() Función de WordPress para encolar scripts
     * @uses $this->assetsUrl Para construir la ruta a los recursos
     * @uses $this->version Para el control de versiones
     */
    private function enqueueDefaultAssets(): void
    {
        // Assets por defecto si no hay configuración
        wp_enqueue_style(
            'mi-integracion-api-admin',
            $this->assetsUrl . 'css/admin.css',
            [],
            $this->version
        );
    }
    
    /**
     * Encuela un asset individual según su configuración
     *
     * Método auxiliar que maneja el registro y encolado de un solo
     * recurso (estilo o script) basado en su configuración.
     *
     * @param array{
     *     handle: string,
     *     src: string,
     *     type: 'script'|'style',
     *     dependencies?: string[],
     *     in_footer?: bool
     * } $assetConfig Configuración del asset a cargar
     * @return void
     * @throws \InvalidArgumentException Si la configuración del asset es inválida o falta algún campo requerido
     * @since 1.0.0
     * @see wp_enqueue_style() Para la carga de estilos CSS
     * @see wp_enqueue_script() Para la carga de scripts JavaScript
     * @see wp_register_style() Para el registro de estilos
     * @see wp_register_script() Para el registro de scripts
     */
    private function enqueueAsset(array $assetConfig): void
    {
        if ($assetConfig['type'] === 'script') {
            wp_enqueue_script(
                $assetConfig['handle'],
                $this->assetsUrl . $assetConfig['src'],
                $assetConfig['dependencies'],
                $this->version,
                $assetConfig['in_footer'] ?? true
            );
        } else {
            wp_enqueue_style(
                $assetConfig['handle'],
                $this->assetsUrl . $assetConfig['src'],
                $assetConfig['dependencies'],
                $this->version
            );
        }
    }
}
