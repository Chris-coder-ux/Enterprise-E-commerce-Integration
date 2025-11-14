<?php declare(strict_types=1);
/**
 * EmergencyLoader - Nivel 3: Autoloader de emergencia para clases críticas
 * 
 * Proporciona un sistema de autoloading mínimo para clases absolutamente
 * críticas que permitan mostrar errores útiles si todo falla.
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase EmergencyLoader
 * 
 * Sistema de autoloading de emergencia que garantiza que las clases
 * más críticas estén disponibles incluso en situaciones de fallo total.
 */
class EmergencyLoader {
    /**
     * Estado de inicialización del autoloader
     *
     * @var bool
     */
    private static bool $initialized = false;

    /**
     * Mapeo de clases críticas que se deben poder cargar siempre
     * Organizado por directorios para mejor mantenimiento
     * 
     * @var array
     */
    private static array $critical_classes = [
        // ===== CORE - Clases principales del sistema =====
        'MiIntegracionApi\\Core\\MiIntegracionApi' => 'includes/Core/MiIntegracionApi.php',
        'MiIntegracionApi\\Core\\ComposerAutoloader' => 'includes/Core/ComposerAutoloader.php',
        'MiIntegracionApi\\Core\\ApiConnector' => 'includes/Core/ApiConnector.php',
        'MiIntegracionApi\\Core\\Sync_Manager' => 'includes/Core/Sync_Manager.php',
        'MiIntegracionApi\\Core\\BatchProcessor' => 'includes/Core/BatchProcessor.php',
        'MiIntegracionApi\\Core\\ConfigManager' => 'includes/Core/ConfigManager.php',
        'MiIntegracionApi\\Core\\Config_Manager' => 'includes/Core/Config_Manager.php',
        'MiIntegracionApi\\Core\\DataSanitizer' => 'includes/Core/DataSanitizer.php',
        'MiIntegracionApi\\Core\\MemoryManager' => 'includes/Core/MemoryManager.php',
        'MiIntegracionApi\\Core\\LogManager' => 'includes/Core/LogManager.php',
        'MiIntegracionApi\\Core\\SyncMetrics' => 'includes/Core/SyncMetrics.php',
        'MiIntegracionApi\\Core\\CompressionManager' => 'includes/Core/CompressionManager.php',
        'MiIntegracionApi\\Core\\RetryManager' => 'includes/Core/RetryManager.php',
        'MiIntegracionApi\\Core\\RetryConfigurationManager' => 'includes/Core/RetryConfigurationManager.php',
        'MiIntegracionApi\\Core\\RetryPolicyManager' => 'includes/Core/RetryPolicyManager.php',
        'MiIntegracionApi\\Core\\Installer' => 'includes/Core/Installer.php',
        'MiIntegracionApi\\Core\\REST_API_Handler' => 'includes/Core/REST_API_Handler.php',
        'MiIntegracionApi\\Core\\DataValidator' => 'includes/Core/DataValidator.php',
        'MiIntegracionApi\\Core\\BatchAutomationManager' => 'includes/Core/BatchAutomationManager.php',
        'MiIntegracionApi\\Core\\VerialIntegrationManager' => 'includes/Core/VerialIntegrationManager.php',
        'MiIntegracionApi\\Core\\SyncLoggingHelper' => 'includes/Core/SyncLoggingHelper.php',
        'MiIntegracionApi\\Core\\SyncTransientsMigrator' => 'includes/Core/SyncTransientsMigrator.php',
        'MiIntegracionApi\\Core\\CacheInterface' => 'includes/Core/CacheInterface.php',
        'MiIntegracionApi\\Core\\SimpleCache' => 'includes/Core/SimpleCache.php',
        'MiIntegracionApi\\Core\\Module_Loader' => 'includes/Core/Module_Loader.php',
        'MiIntegracionApi\\Core\\SSLAdvancedSystemsTrait' => 'includes/Core/SSLAdvancedSystemsTrait.php',
        'MiIntegracionApi\\Core\\REST\\VerialEndpointsRegistrar' => 'includes/Core/REST/VerialEndpointsRegistrar.php',
        'MiIntegracionApi\\Core\\LogCleaner' => 'includes/Core/LogCleaner.php',

        // ===== VALIDATION - Validadores del sistema =====
        'MiIntegracionApi\\Core\\Validation\\SyncValidator' => 'includes/Core/Validation/SyncValidator.php',
        'MiIntegracionApi\\Core\\Validation\\ConcreteSyncValidator' => 'includes/Core/Validation/ConcreteSyncValidator.php',
        'MiIntegracionApi\\Core\\Validation\\ProductValidator' => 'includes/Core/Validation/ProductValidator.php',
        'MiIntegracionApi\\Core\\Validation\\OrderValidator' => 'includes/Core/Validation/OrderValidator.php',
        'MiIntegracionApi\\Core\\Validation\\CustomerValidator' => 'includes/Core/Validation/CustomerValidator.php',

        // ===== SERVICES - Servicios de negocio =====
        'MiIntegracionApi\\Services\\ClientService' => 'includes/Services/ClientService.php',
        'MiIntegracionApi\\Services\\OrderService' => 'includes/Services/OrderService.php',
        'MiIntegracionApi\\Services\\PaymentService' => 'includes/Services/PaymentService.php',
        'MiIntegracionApi\\Services\\GeographicService' => 'includes/Services/GeographicService.php',
        'MiIntegracionApi\\Services\\TrackingService' => 'includes/Services/TrackingService.php',
        'MiIntegracionApi\\Services\\VerialApiClient' => 'includes/Services/VerialApiClient.php',

        // ===== DTOS - Data Transfer Objects =====
        'MiIntegracionApi\\DTOs\\BaseDTO' => 'includes/DTOs/BaseDTO.php',
        'MiIntegracionApi\\DTOs\\ApiResponse' => 'includes/DTOs/ApiResponse.php',
        'MiIntegracionApi\\DTOs\\ProductDTO' => 'includes/DTOs/ProductDTO.php',
        'MiIntegracionApi\\DTOs\\CustomerDTO' => 'includes/DTOs/CustomerDTO.php',
        'MiIntegracionApi\\DTOs\\OrderDTO' => 'includes/DTOs/OrderDTO.php',
        'MiIntegracionApi\\DTOs\\InfoError' => 'includes/DTOs/InfoError.php',
        'MiIntegracionApi\\DTOs\\ProductData' => 'includes/DTOs/ProductData.php',
        'MiIntegracionApi\\DTOs\\CategoryData' => 'includes/DTOs/CategoryData.php',
        'MiIntegracionApi\\DTOs\\ClientDTO' => 'includes/DTOs/ClientDTO.php',

        // ===== DIAGNOSTICS - Diagnósticos del sistema =====
        'MiIntegracionApi\\Diagnostics\\AjaxDiagnostic' => 'includes/Diagnostics/AjaxDiagnostic.php',
        'MiIntegracionApi\\Diagnostics\\BatchDiagnostics' => 'includes/Diagnostics/BatchDiagnostics.php',

        // ===== ADMIN - Panel de administración =====
        'MiIntegracionApi\\Admin\\AdminMenu' => 'includes/Admin/AdminMenu.php',
        'MiIntegracionApi\\Admin\\DashboardPageView' => 'includes/Admin/DashboardPageView.php',
        'MiIntegracionApi\\Admin\\VerificationPerformanceDashboard' => 'includes/Admin/VerificationPerformanceDashboard.php',
        'MiIntegracionApi\\Admin\\AjaxSync' => 'includes/Admin/AjaxSync.php',
        'MiIntegracionApi\\Admin\\AjaxSingleSync' => 'includes/Admin/AjaxSingleSync.php',
        'MiIntegracionApi\\Admin\\AjaxDashboard' => 'includes/Admin/AjaxDashboard.php',
        'MiIntegracionApi\\Admin\\OrderSyncDashboard' => 'includes/Admin/OrderSyncDashboard.php',
        'MiIntegracionApi\\Admin\\DetectionDashboard' => 'includes/Admin/DetectionDashboard.php',
        'MiIntegracionApi\\Admin\\SettingsRegistration' => 'includes/Admin/SettingsRegistration.php',
        'MiIntegracionApi\\Admin\\Assets' => 'includes/Admin/Assets.php',
        'MiIntegracionApi\\Admin\\RetrySettingsManager' => 'includes/Admin/RetrySettingsManager.php',
        'MiIntegracionApi\\Admin\\MemoryMonitoringManager' => 'includes/Admin/MemoryMonitoringManager.php',
        'MiIntegracionApi\\Admin\\Ajax\\SyncDiagnosticAjax' => 'includes/Admin/Ajax/SyncDiagnosticAjax.php',
        'MiIntegracionApi\\Admin\\Ajax\\PerformanceBaselineAjax' => 'includes/Admin/Ajax/PerformanceBaselineAjax.php',
        'MiIntegracionApi\\Admin\\Cache\\CacheTTLSettings' => 'includes/Admin/Cache/CacheTTLSettings.php',
        'MiIntegracionApi\\Admin\\Examples\\Recovery_Interface' => 'includes/Admin/Examples/Recovery_Interface.php',

        // ===== ENDPOINTS - Endpoints de la API REST =====
        'MiIntegracionApi\\Endpoints\\Base' => 'includes/Endpoints/Base.php',
        'MiIntegracionApi\\Endpoints\\LogsEndpoint' => 'includes/Endpoints/LogsEndpoint.php',
        'MiIntegracionApi\\Endpoints\\GetClientesWS' => 'includes/Endpoints/GetClientesWS.php',
        'MiIntegracionApi\\Endpoints\\GetMascotasWS' => 'includes/Endpoints/GetMascotasWS.php',
        'MiIntegracionApi\\Endpoints\\GetArticulosWS' => 'includes/Endpoints/GetArticulosWS.php',
        'MiIntegracionApi\\Endpoints\\GetPaisesWS' => 'includes/Endpoints/GetPaisesWS.php',
        'MiIntegracionApi\\Endpoints\\GetAgentesWS' => 'includes/Endpoints/GetAgentesWS.php',
        'MiIntegracionApi\\Endpoints\\GetCategoriasWS' => 'includes/Endpoints/GetCategoriasWS.php',
        'MiIntegracionApi\\Endpoints\\GetAsignaturasWS' => 'includes/Endpoints/GetAsignaturasWS.php',
        'MiIntegracionApi\\Endpoints\\GetHistorialPedidosWS' => 'includes/Endpoints/GetHistorialPedidosWS.php',
        'MiIntegracionApi\\Endpoints\\GetColeccionesWS' => 'includes/Endpoints/GetColeccionesWS.php',
        'MiIntegracionApi\\Endpoints\\GetFabricantesWS' => 'includes/Endpoints/GetFabricantesWS.php',
        'MiIntegracionApi\\Endpoints\\GetImagenesArticulosWS' => 'includes/Endpoints/GetImagenesArticulosWS.php',
        'MiIntegracionApi\\Endpoints\\GetLocalidadesWS' => 'includes/Endpoints/GetLocalidadesWS.php',
        'MiIntegracionApi\\Endpoints\\GetArbolCamposConfigurablesArticulosWS' => 'includes/Endpoints/GetArbolCamposConfigurablesArticulosWS.php',
        'MiIntegracionApi\\Endpoints\\GetStockArticulosWS' => 'includes/Endpoints/GetStockArticulosWS.php',
        'MiIntegracionApi\\Endpoints\\GetValoresValidadosCampoConfigurableArticulosWS' => 'includes/Endpoints/GetValoresValidadosCampoConfigurableArticulosWS.php',
        'MiIntegracionApi\\Endpoints\\GetNextNumDocsWS' => 'includes/Endpoints/GetNextNumDocsWS.php',
        'MiIntegracionApi\\Endpoints\\GetNumArticulosWS' => 'includes/Endpoints/GetNumArticulosWS.php',
        'MiIntegracionApi\\Endpoints\\GetVersionWS' => 'includes/Endpoints/GetVersionWS.php',
        'MiIntegracionApi\\Endpoints\\GetCursosWS' => 'includes/Endpoints/GetCursosWS.php',
        'MiIntegracionApi\\Endpoints\\GetCategoriasWebWS' => 'includes/Endpoints/GetCategoriasWebWS.php',
        'MiIntegracionApi\\Endpoints\\GetCondicionesTarifaWS' => 'includes/Endpoints/GetCondicionesTarifaWS.php',
        'MiIntegracionApi\\Endpoints\\GetMetodosPagoWS' => 'includes/Endpoints/GetMetodosPagoWS.php',
        'MiIntegracionApi\\Endpoints\\NuevoClienteWS' => 'includes/Endpoints/NuevoClienteWS.php',
        'MiIntegracionApi\\Endpoints\\NuevaDireccionEnvioWS' => 'includes/Endpoints/NuevaDireccionEnvioWS.php',
        'MiIntegracionApi\\Endpoints\\NuevaMascotaWS' => 'includes/Endpoints/NuevaMascotaWS.php',
        'MiIntegracionApi\\Endpoints\\UpdateDocClienteWS' => 'includes/Endpoints/UpdateDocClienteWS.php',
        'MiIntegracionApi\\Endpoints\\NuevoDocClienteWS' => 'includes/Endpoints/NuevoDocClienteWS.php',
        'MiIntegracionApi\\Endpoints\\BorrarMascotaWS' => 'includes/Endpoints/BorrarMascotaWS.php',
        'MiIntegracionApi\\Endpoints\\EstadoPedidosWS' => 'includes/Endpoints/EstadoPedidosWS.php',
        'MiIntegracionApi\\Endpoints\\GetCamposConfigurablesArticulosWS' => 'includes/Endpoints/GetCamposConfigurablesArticulosWS.php',
        'MiIntegracionApi\\Endpoints\\GetFormasEnvioWS' => 'includes/Endpoints/GetFormasEnvioWS.php',

        // ===== SYNC - Sincronización de datos =====
        'MiIntegracionApi\\Sync\\SyncManager' => 'includes/Sync/SyncManager.php',
        'MiIntegracionApi\\Sync\\BatchProcessor' => 'includes/Sync/BatchProcessor.php',
        'MiIntegracionApi\\Sync\\ImageSyncManager' => 'includes/Sync/ImageSyncManager.php',
        'MiIntegracionApi\\Sync\\ImageProcessor' => 'includes/Sync/ImageProcessor.php',
        'MiIntegracionApi\\Sync\\ImageProcessorInterface' => 'includes/Sync/ImageProcessor.php',
        'MiIntegracionApi\\Sync\\AdaptiveThrottler' => 'includes/Sync/AdaptiveThrottler.php',
        'MiIntegracionApi\\Sync\\ImageSyncConfig' => 'includes/Sync/ImageSyncConfig.php',
        'MiIntegracionApi\\Sync\\SyncPedidos' => 'includes/Sync/SyncPedidos.php',
        'MiIntegracionApi\\Sync\\SyncClientes' => 'includes/Sync/SyncClientes.php',
        'MiIntegracionApi\\Sync\\WooCommerceLoader' => 'includes/Sync/WooCommerceLoader.php',

        // ===== DETECCION - Sistema de detección automática =====
        'MiIntegracionApi\\Deteccion\\StockDetector' => 'includes/Deteccion/StockDetector.php',
        'MiIntegracionApi\\Deteccion\\StockDetectorIntegration' => 'includes/Deteccion/StockDetectorIntegration.php',
        'MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier' => 'includes/Deteccion/WooCommerceProductNotifier.php',
        
        // ===== ADMIN - Configuración de notificaciones =====
        'MiIntegracionApi\\Admin\\NotificationConfig' => 'includes/Admin/NotificationConfig.php',

        // ===== HOOKS - Hooks de WordPress =====
        'MiIntegracionApi\\Hooks\\RobustnessHooks' => 'includes/Hooks/RobustnessHooks.php',
        'MiIntegracionApi\\Hooks\\AdminHookRegistry' => 'includes/Hooks/AdminHookRegistry.php',
        'MiIntegracionApi\\Hooks\\CoreHookRegistry' => 'includes/Hooks/CoreHookRegistry.php',
        'MiIntegracionApi\\Hooks\\HookPriorities' => 'includes/Hooks/HookPriorities.php',
        'MiIntegracionApi\\Hooks\\HookRegistryInterface' => 'includes/Hooks/HookRegistryInterface.php',
        'MiIntegracionApi\\Hooks\\HooksInit' => 'includes/Hooks/HooksInit.php',
        'MiIntegracionApi\\Hooks\\HooksManager' => 'includes/Hooks/HooksManager.php',
        'MiIntegracionApi\\Hooks\\SyncHooks' => 'includes/Hooks/SyncHooks.php',
        'MiIntegracionApi\\Hooks\\UnifiedSystemHooks' => 'includes/Hooks/UnifiedSystemHooks.php',
        'MiIntegracionApi\\Hooks\\WooCommerceHookRegistry' => 'includes/Hooks/WooCommerceHookRegistry.php',

        // ===== AJAX - Manejo de AJAX =====
        'MiIntegracionApi\\Ajax\\CacheHandler' => 'includes/Ajax/CacheHandler.php',

        // ===== CACHE - Gestión de caché =====
        'MiIntegracionApi\\Cache\\Cache_Admin_Panel' => 'includes/Cache/Cache_Admin_Panel.php',
        'MiIntegracionApi\\Cache\\HTTP_Cache_Manager' => 'includes/Cache/HTTP_Cache_Manager.php',
        'MiIntegracionApi\\Cache\\PriceCache' => 'includes/Cache/PriceCache.php',
        'MiIntegracionApi\\CacheManager' => 'includes/CacheManager.php',

        // ===== CLI - Comandos de línea de comandos =====
        'MiIntegracionApi\\Cli\\SyncDiagnosticCommand' => 'includes/Cli/SyncDiagnosticCommand.php',

        // ===== IMPROVEMENTS - Optimizaciones =====
        'MiIntegracionApi\\Improvements\\BatchSizeOptimizedTrait' => 'includes/Improvements/BatchSizeOptimizedTrait.php',
        'MiIntegracionApi\\Improvements\\MemoryOptimizedTrait' => 'includes/Improvements/MemoryOptimizedTrait.php',
        'MiIntegracionApi\\Improvements\\TimeoutOptimizedTrait' => 'includes/Improvements/TimeoutOptimizedTrait.php',

        // ===== TOOLS - Herramientas del sistema =====
        'MiIntegracionApi\\Tools\\Connection_Tester' => 'includes/Tools/Connection_Tester.php',
        'MiIntegracionApi\\Tools\\SSLCommands' => 'includes/Tools/SSLCommands.php',
        'MiIntegracionApi\\Tools\\SSLDiagnosticsTool' => 'includes/Tools/SSLDiagnosticsTool.php',
        'MiIntegracionApi\\Tools\\SyncCommands' => 'includes/Tools/SyncCommands.php',
        'MiIntegracionApi\\Tools\\LogCleanupCommand' => 'includes/Tools/LogCleanupCommand.php',

        // ===== WOOCOMMERCE - Integración con WooCommerce =====
        'MiIntegracionApi\\Helpers\\MapProduct' => 'includes/Helpers/MapProduct.php',
        'MiIntegracionApi\\WooCommerce\\WooCommerceHooks' => 'includes/WooCommerce/WooCommerceHooks.php',
        'MiIntegracionApi\\WooCommerce\\OrderManager' => 'includes/WooCommerce/OrderManager.php',
        'MiIntegracionApi\\WooCommerce\\SyncHelper' => 'includes/WooCommerce/SyncHelper.php',

        // ===== COMPATIBILITY - Compatibilidad =====
        'MiIntegracionApi\\Compatibility\\CompatibilityReport' => 'includes/Compatibility/CompatibilityReport.php',
        'MiIntegracionApi\\Compatibility\\ThemeCompatibility' => 'includes/Compatibility/ThemeCompatibility.php',
        'MiIntegracionApi\\Compatibility\\WooCommercePluginCompatibility' => 'includes/Compatibility/WooCommercePluginCompatibility.php',
        'MiIntegracionApi\\Compatibility\\ThemePluginCompatibility' => 'includes/Compatibility/ThemePluginCompatibility.php',

        // ===== SSL - Certificados SSL =====
        'MiIntegracionApi\\SSL\\CertificateCache' => 'includes/SSL/CertificateCache.php',
        'MiIntegracionApi\\SSL\\SSLTimeoutManager' => 'includes/SSL/SSLTimeoutManager.php',
        'MiIntegracionApi\\SSL\\SSLConfigManager' => 'includes/SSL/SSLConfigManager.php',
        'MiIntegracionApi\\SSL\\CertificateRotation' => 'includes/SSL/CertificateRotation.php',

        // ===== TRAITS - Traits reutilizables =====
        'MiIntegracionApi\\Traits\\Singleton' => 'includes/Traits/Singleton.php',
        'MiIntegracionApi\\Traits\\EndpointLogger' => 'includes/Traits/EndpointLogger.php',
        'MiIntegracionApi\\Traits\\Cacheable' => 'includes/Traits/Cacheable.php',
        'MiIntegracionApi\\Traits\\ErrorHandler' => 'includes/Traits/ErrorHandler.php',
        'MiIntegracionApi\\Traits\\CacheableTrait' => 'includes/Traits/CacheableTrait.php',
        'MiIntegracionApi\\Traits\\ErrorHandlerTrait' => 'includes/Traits/ErrorHandlerTrait.php',
        'MiIntegracionApi\\Traits\\LoggerTrait' => 'includes/Traits/LoggerTrait.php',
        'MiIntegracionApi\\Traits\\ValidationTrait' => 'includes/Traits/ValidationTrait.php',

        // ===== HELPERS - Utilidades y ayudantes =====
        'MiIntegracionApi\\Helpers\\Logger' => 'includes/Helpers/Logger.php',
        'MiIntegracionApi\\Helpers\\SyncStatusHelper' => 'includes/Helpers/SyncStatusHelper.php',
        'MiIntegracionApi\\Helpers\\WooCommerceHelper' => 'includes/Helpers/WooCommerceHelper.php',
        'MiIntegracionApi\\Helpers\\VerificationPerformanceTracker' => 'includes/Helpers/VerificationPerformanceTracker.php',
        'MiIntegracionApi\\Helpers\\BatchSizeHelper' => 'includes/Helpers/BatchSizeHelper.php',
        'MiIntegracionApi\\Helpers\\EndpointArgs' => 'includes/Helpers/EndpointArgs.php',
        'MiIntegracionApi\\Helpers\\Utils' => 'includes/Helpers/Utils.php',
        'MiIntegracionApi\\Helpers\\RestHelpers' => 'includes/Helpers/RestHelpers.php',
        'MiIntegracionApi\\Helpers\\AuthHelper' => 'includes/Helpers/AuthHelper.php',
        'MiIntegracionApi\\Helpers\\SettingsHelper' => 'includes/Helpers/SettingsHelper.php',
        'MiIntegracionApi\\Helpers\\SecurityValidator' => 'includes/Helpers/SecurityValidator.php',
        'MiIntegracionApi\\Helpers\\ApiHelpers' => 'includes/Helpers/ApiHelpers.php',
        'MiIntegracionApi\\Helpers\\ApiLoggerIntegration' => 'includes/Helpers/ApiLoggerIntegration.php',
        'MiIntegracionApi\\Helpers\\BatchSizeDebug' => 'includes/Helpers/BatchSizeDebug.php',
        'MiIntegracionApi\\Helpers\\Crypto' => 'includes/Helpers/Crypto.php',
        'MiIntegracionApi\\Helpers\\DataSanitizer' => 'includes/Helpers/DataSanitizer.php',
        'MiIntegracionApi\\Helpers\\DbLogs' => 'includes/Helpers/DbLogs.php',
        'MiIntegracionApi\\Helpers\\FilterCustomers' => 'includes/Helpers/FilterCustomers.php',
        'MiIntegracionApi\\Helpers\\FilterOrders' => 'includes/Helpers/FilterOrders.php',
        'MiIntegracionApi\\Helpers\\FilterProducts' => 'includes/Helpers/FilterProducts.php',
        'MiIntegracionApi\\Helpers\\Formatting' => 'includes/Helpers/Formatting.php',
        'MiIntegracionApi\\Helpers\\HposCompatibility' => 'includes/Helpers/HposCompatibility.php',
        'MiIntegracionApi\\Helpers\\IdGenerator' => 'includes/Helpers/IdGenerator.php',
        'MiIntegracionApi\\Helpers\\IndexHelper' => 'includes/Helpers/IndexHelper.php',
        'MiIntegracionApi\\Helpers\\BatchApiHelper' => 'includes/Helpers/BatchApiHelper.php',
        'MiIntegracionApi\\Helpers\\ApiCallOptimizer' => 'includes/Helpers/ApiCallOptimizer.php',
        'MiIntegracionApi\\Helpers\\ILogger' => 'includes/Helpers/ILogger.php',
        'MiIntegracionApi\\Helpers\\JwtAuthHelper' => 'includes/Helpers/JwtAuthHelper.php',
        'MiIntegracionApi\\Helpers\\LegacyCallTracker' => 'includes/Helpers/LegacyCallTracker.php',
        'MiIntegracionApi\\Helpers\\LoggerAuditoria' => 'includes/Helpers/LoggerAuditoria.php',
        'MiIntegracionApi\\Helpers\\MapCustomer' => 'includes/Helpers/MapCustomer.php',
        'MiIntegracionApi\\Helpers\\MapOrder' => 'includes/Helpers/MapOrder.php',
        'MiIntegracionApi\\Helpers\\MetaHelper' => 'includes/Helpers/MetaHelper.php',
        'MiIntegracionApi\\Helpers\\ResponseProcessor' => 'includes/Helpers/ResponseProcessor.php',
        'MiIntegracionApi\\Helpers\\TransientCompatibility' => 'includes/Helpers/TransientCompatibility.php',
        'MiIntegracionApi\\Helpers\\LogoHelper' => 'includes/Helpers/LogoHelper.php',
        'MiIntegracionApi\\Helpers\\FaviconHelper' => 'includes/Helpers/FaviconHelper.php',
        'MiIntegracionApi\\Helpers\\OptimizeImageDuplicatesSearch' => 'includes/Helpers/OptimizeImageDuplicatesSearch.php',
        'MiIntegracionApi\\ErrorHandler' => 'includes/ErrorHandler.php',

        // ===== CONFIG - Configuración externa =====
        'VerialApiConfig' => 'verialconfig.php',
        'MiIntegracionApi\\Config\\LogCleanupConfig' => 'includes/Config/LogCleanupConfig.php',

        // ===== ERROR HANDLING - Manejo de errores =====
        'MiIntegracionApi\\ErrorHandling\\Adapters\\WordPressAdapter' => 'includes/ErrorHandling/Adapters/WordPressAdapter.php',
        'MiIntegracionApi\\ErrorHandling\\Adapters\\AdapterBenchmark' => 'includes/ErrorHandling/Adapters/AdapterBenchmark.php',
        'MiIntegracionApi\\ErrorHandling\\Adapters\\BottleneckAnalyzer' => 'includes/ErrorHandling/Adapters/BottleneckAnalyzer.php',
        'MiIntegracionApi\\ErrorHandling\\Adapters\\PerformanceBaseline' => 'includes/ErrorHandling/Adapters/PerformanceBaseline.php',
        'MiIntegracionApi\\ErrorHandling\\Handlers\\ResponseFactory' => 'includes/ErrorHandling/Handlers/ResponseFactory.php',
        'MiIntegracionApi\\ErrorHandling\\Responses\\SyncResponse' => 'includes/ErrorHandling/Responses/SyncResponse.php',
        'MiIntegracionApi\\ErrorHandling\\Responses\\SyncResponseInterface' => 'includes/ErrorHandling/Responses/SyncResponseInterface.php',
        'MiIntegracionApi\\ErrorHandling\\Exceptions\\SyncError' => 'includes/ErrorHandling/Exceptions/SyncError.php',
        'MiIntegracionApi\\ErrorHandling\\Constants\\HttpStatusCodes' => 'includes/ErrorHandling/Constants/HttpStatusCodes.php',
        'MiIntegracionApi\\ErrorHandling\\Constants\\ErrorCodes' => 'includes/ErrorHandling/Constants/ErrorCodes.php',

        // ===== CONSTANTS - Constantes =====
        'MiIntegracionApi\\Constants\\VerialTypes' => 'includes/Constants/VerialTypes.php',
        'MiIntegracionApi\\Constants\\CurlConstants' => 'includes/Constants/CurlConstants.php',
    ];

    /**
     * Directorio base del plugin
     *
     * @var string
     */
    private static string $base_dir;

    /**
     * Contador de intentos de carga
     *
     * @var array
     */
    private static array $load_attempts = [];

    /**
     * Límite máximo de intentos de carga por clase
     *
     * @var int
     */
    private static int $max_load_attempts = 3;

    /**
     * Inicializa el EmergencyLoader
     * 
     * @return void
     */
    public static function init(): void {
        if (self::$initialized) {
            return;
        }

        self::$base_dir = self::getBaseDirectory();
        self::register();
        self::$initialized = true;
        
        self::logInitialization();
    }

    /**
     * Obtiene el directorio base del plugin
     * 
     * @return string Directorio base
     * @throws \RuntimeException Si no se puede determinar el directorio
     */
    private static function getBaseDirectory(): string {
        // Intentar usar la constante si está definida
        if (defined('MiIntegracionApi_PLUGIN_DIR')) {
            return MiIntegracionApi_PLUGIN_DIR;
        }
        
        // Fallback: usar el directorio del archivo actual
        $current_file = __FILE__;
        $base_dir = dirname($current_file, 2) . '/'; // Subir 2 niveles desde includes/Core/
        
        // Verificar que el directorio existe y contiene los archivos esperados
        if (is_dir($base_dir) && file_exists($base_dir . 'mi-integracion-api.php')) {
            return $base_dir;
        }
        
        // Último fallback: usar el directorio actual
        $fallback_dir = dirname(__FILE__, 3) . '/';
        if (is_dir($fallback_dir)) {
            return $fallback_dir;
        }
        
        throw new \RuntimeException('No se puede determinar el directorio base del plugin');
    }

    /**
     * Registra el autoloader de emergencia
     * 
     * @return void
     */
    private static function register(): void {
        // Registrar con alta prioridad para que se ejecute primero
        spl_autoload_register([self::class, 'loadCriticalClass'], true, true);
    }

    /**
     * Carga una clase crítica específica
     * 
     * @param string $class Nombre completo de la clase
     * @return void
     */
    public static function loadCriticalClass(string $class): void {
        // Verificar si la clase ya está cargada
        if (class_exists($class, false) || interface_exists($class, false) || trait_exists($class, false)) {
            return;
        }

        // Verificar si es una clase crítica
        if (!isset(self::$critical_classes[$class])) {
            return;
        }

        // Verificar límite de intentos
        if (self::hasExceededLoadAttempts($class)) {
            self::logMaxAttemptsReached($class);
            return;
        }

        // Incrementar contador de intentos
        self::incrementLoadAttempts($class);

        // Cargar el archivo
        $file_path = self::$base_dir . self::$critical_classes[$class];
        
        if (file_exists($file_path)) {
            try {
                require_once $file_path;
                self::logSuccessfulLoad($class, $file_path);
            } catch (\Throwable $e) {
                self::logLoadError($class, $file_path, $e);
            }
        } else {
            self::logMissingFile($class, $file_path);
        }
    }

    /**
     * Verifica si se ha excedido el límite de intentos de carga
     * 
     * @param string $class Nombre de la clase
     * @return bool True si se ha excedido el límite
     */
    private static function hasExceededLoadAttempts(string $class): bool {
        return isset(self::$load_attempts[$class]) && 
               self::$load_attempts[$class] >= self::$max_load_attempts;
    }

    /**
     * Incrementa el contador de intentos de carga
     * 
     * @param string $class Nombre de la clase
     * @return void
     */
    private static function incrementLoadAttempts(string $class): void {
        if (!isset(self::$load_attempts[$class])) {
            self::$load_attempts[$class] = 0;
        }
        
        self::$load_attempts[$class]++;
    }

    /**
     * Determina si se deben generar logs
     * 
     * @return bool True si se deben generar logs
     */
    private static function shouldLog(): bool {
        // No logear en producción real
        if (self::isProductionEnvironment()) {
            return false;
        }
        
        // Solo logear si WP_DEBUG está habilitado Y estamos en desarrollo
        return defined('WP_DEBUG') && constant('WP_DEBUG') && self::isDevelopmentEnvironment();
    }
    
    /**
     * Detecta si estamos en un entorno de producción real
     * 
     * @return bool True si es producción
     */
    private static function isProductionEnvironment(): bool {
        // Verificar si estamos en un servidor de producción
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        $http_host = $_SERVER['HTTP_HOST'] ?? '';
        
        // Detectar dominios de producción
        $production_indicators = [
            'verialshoperp.impulsadixital.com',
            'verial.org',
            'produccion',
            'production'
        ];
        
        foreach ($production_indicators as $indicator) {
            if (strpos($server_name, $indicator) !== false || strpos($http_host, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Detecta si estamos en un entorno de desarrollo
     * 
     * @return bool True si es desarrollo
     */
    private static function isDevelopmentEnvironment(): bool {
        // Verificar indicadores de desarrollo
        $server_name = $_SERVER['SERVER_NAME'] ?? '';
        $http_host = $_SERVER['HTTP_HOST'] ?? '';
        
        $development_indicators = [
            'localhost',
            '127.0.0.1',
            'dev.',
            'test.',
            'staging.',
            'local'
        ];
        
        foreach ($development_indicators as $indicator) {
            if (strpos($server_name, $indicator) !== false || strpos($http_host, $indicator) !== false) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Registra un log de inicialización
     * 
     * @return void
     */
    private static function logInitialization(): void {
        // Solo log en desarrollo real, no en producción
        if (self::shouldLog()) {
            $message = 'EmergencyLoader inicializado con ' . count(self::$critical_classes) . ' clases críticas';
            
            if (function_exists('error_log')) {
                error_log('[MiIntegracionApi] ' . $message);
            }
        }
    }

    /**
     * Registra un log de carga exitosa
     * 
     * @param string $class Nombre de la clase
     * @param string $file_path Ruta del archivo
     * @return void
     */
    private static function logSuccessfulLoad(string $class, string $file_path): void {
        // Solo log en desarrollo real, no en producción
        if (self::shouldLog()) {
            $message = "Clase crítica cargada exitosamente: {$class} desde {$file_path}";
            
            if (function_exists('error_log')) {
                error_log('[MiIntegracionApi] ' . $message);
            }
        }
    }

    /**
     * Registra un log de archivo faltante
     * 
     * @param string $class Nombre de la clase
     * @param string $file_path Ruta del archivo
     * @return void
     */
    private static function logMissingFile(string $class, string $file_path): void {
        $message = "Archivo crítico no encontrado: {$class} en {$file_path}";
        
        if (function_exists('error_log')) {
            error_log('[MiIntegracionApi] ERROR: ' . $message);
        }
    }

    /**
     * Registra un log de error de carga
     * 
     * @param string $class Nombre de la clase
     * @param string $file_path Ruta del archivo
     * @param \Throwable $e Excepción
     * @return void
     */
    private static function logLoadError(string $class, string $file_path, \Throwable $e): void {
        $message = "Error al cargar clase crítica {$class} desde {$file_path}: " . $e->getMessage();
        
        if (function_exists('error_log')) {
            error_log('[MiIntegracionApi] ERROR: ' . $message);
        }
    }

    /**
     * Registra un log de límite de intentos alcanzado
     * 
     * @param string $class Nombre de la clase
     * @return void
     */
    private static function logMaxAttemptsReached(string $class): void {
        $message = "Límite de intentos alcanzado para clase crítica: {$class}";
        
        if (function_exists('error_log')) {
            error_log('[MiIntegracionApi] ERROR: ' . $message);
        }
    }

    /**
     * Añade una clase crítica al mapeo
     * 
     * @param string $class Nombre completo de la clase
     * @param string $file_path Ruta relativa del archivo
     * @return void
     */
    public static function addCriticalClass(string $class, string $file_path): void {
        self::$critical_classes[$class] = $file_path;
    }

    /**
     * Remueve una clase crítica del mapeo
     * 
     * @param string $class Nombre completo de la clase
     * @return void
     */
    public static function removeCriticalClass(string $class): void {
        unset(self::$critical_classes[$class]);
    }

    /**
     * Obtiene la lista de clases críticas
     * 
     * @return array Lista de clases críticas
     */
    public static function getCriticalClasses(): array {
        return self::$critical_classes;
    }

    /**
     * Obtiene información de diagnóstico del autoloader
     * 
     * @return array Información de diagnóstico
     */
    public static function getDiagnosticInfo(): array {
        return [
            'initialized' => self::$initialized,
            'base_dir' => self::$base_dir ?? 'No definido',
            'critical_classes_count' => count(self::$critical_classes),
            'load_attempts' => self::$load_attempts,
            'max_load_attempts' => self::$max_load_attempts
        ];
    }

    /**
     * Verifica si el autoloader está inicializado
     * 
     * @return bool True si está inicializado
     */
    public static function isInitialized(): bool {
        return self::$initialized;
    }

    /**
     * Resetea el contador de intentos de carga
     * 
     * @return void
     */
    public static function resetLoadAttempts(): void {
        self::$load_attempts = [];
    }

    /**
     * Verifica si una clase es crítica
     * 
     * @param string $class Nombre de la clase
     * @return bool True si es crítica
     */
    public static function isCriticalClass(string $class): bool {
        return isset(self::$critical_classes[$class]);
    }

    /**
     * Obtiene la ruta de un archivo crítico
     * 
     * @param string $class Nombre de la clase
     * @return string|null Ruta del archivo o null si no existe
     */
    public static function getCriticalClassPath(string $class): ?string {
        if (!isset(self::$critical_classes[$class])) {
            return null;
        }

        return self::$base_dir . self::$critical_classes[$class];
    }
}
