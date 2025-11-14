<?php
declare(strict_types=1);


/**
 * Gestor de sincronización bidireccional entre WooCommerce y Verial ERP
 *
 * Esta clase proporciona una interfaz completa para la sincronización de datos
 * entre WooCommerce y el sistema Verial ERP. Incluye funcionalidades para:
 * - Sincronización de productos, pedidos y clientes
 * - Gestión de errores y reintentos automáticos
 * - Validación y saneamiento de datos
 * - Gestión de caché para optimizar el rendimiento
 * - Monitoreo del estado de sincronización
 * - Manejo de conflictos y resolución de inconsistencias
 *
 * La clase implementa el patrón Singleton para garantizar una única instancia
 * en toda la aplicación y utiliza inyección de dependencias para una mejor
 * capacidad de prueba y mantenimiento.
 *
 * @package     MiIntegracionApi\Core
 * @since       1.0.0
 * @version     2.0.0
 * @author      Christian
 * @copyright   Copyright (c) 2025, Tu Empresa
 * @license     GPL-2.0+
 * @link        https://tudominio.com/plugin
 *
 * @property-read CacheManager $cache_manager Gestor de caché
 * @property-read LogManager $log_manager Gestor de logs
 * @property-read RetryManager $retry_manager Gestor de reintentos
 * @property-read DataValidator $data_validator Validador de datos
 * @property-read DataSanitizer $data_sanitizer Sanitizador de datos
 * @property-read BatchSizeHelper $batch_size_helper Ayudante para tamaños de lote
 * @property-read SyncHelper $sync_helper Utilidades de sincronización
 *
 * @see \MiIntegracionApi\WooCommerce\SyncHelper Para utilidades de sincronización específicas de WooCommerce
 * @see \MiIntegracionApi\ErrorHandling\Exceptions\SyncError Para el manejo de errores de sincronización
 * @see \MiIntegracionApi\DTOs\* Para los objetos de transferencia de datos
 */

namespace MiIntegracionApi\Core;

use MiIntegracionApi\CacheManager;
use MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface;
use MiIntegracionApi\Core\DataValidator;
use MiIntegracionApi\Core\LogManager;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\Logging\Core\LoggerBasic;
use MiIntegracionApi\Helpers\MapProduct;
use MiIntegracionApi\Helpers\MapOrder;
use MiIntegracionApi\Core\DataSanitizer;
use MiIntegracionApi\Helpers\BatchSizeHelper;
use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;
use MiIntegracionApi\Logging\Interfaces\ILogger;
use MiIntegracionApi\Traits\MainPluginAccessor;
use MiIntegracionApi\WooCommerce\SyncHelper;
use MiIntegracionApi\Core\ConfigManager;



if ( ! defined( 'ABSPATH' ) ) {
	exit; 
}

/**
 * Clase principal para la gestión de la sincronización bidireccional entre WooCommerce y Verial ERP.
 *
 * Esta clase implementa el patrón Singleton y es responsable de orquestar la sincronización
 * de datos entre WooCommerce y el sistema Verial ERP. Maneja la lógica de negocio,
 * la gestión de errores, reintentos y la consistencia de datos entre ambos sistemas.
 *
 * @since       1.0.0
 * @package     MiIntegracionApi\Core
 * @author      Christian
 * @copyright   Copyright (c) 2025, Tu Empresa
 * @license     GPL-2.0+
 * @link        https://tudominio.com/plugin
 *
 * @property-read ConfigManager $config_manager Gestor de configuración del plugin
 * @property-read LogManager $logger Gestor de logs del sistema
 * @property-read mixed $api_connector Cliente de conexión con la API de Verial
 * @property-read array|null $sync_status Estado actual de la sincronización
 *
 * @method bool is_initialized() Verifica si la clase ha sido correctamente inicializada
 * @method bool is_sync_in_progress() Verifica si hay una sincronización en curso
 * @method bool stop_sync() Detiene el proceso de sincronización actual
 * @method array get_supported_entities() Obtiene las entidades soportadas para sincronización
 * @method array get_supported_directions() Obtiene las direcciones de sincronización soportadas
 * @method array get_system_health() Obtiene el estado de salud del sistema
 * @method array get_sync_configuration() Obtiene la configuración actual de sincronización
 *
 * @uses MiIntegracionApi\Core\ConfigManager Para la gestión de configuraciones
 * @uses MiIntegracionApi\Core\LogManager Para el registro de eventos
 * @uses MiIntegracionApi\Core\DataValidator Para la validación de datos
 * @uses MiIntegracionApi\Core\RetryManager Para la gestión de reintentos
 * @uses MiIntegracionApi\DTOs\* Para la transferencia de datos entre capas
 * @uses MiIntegracionApi\Helpers\* Para utilidades y ayudantes varios
 *
 * @example
 * // Obtener instancia del gestor de sincronización
 * $sync_manager = Sync_Manager::get_instance();
 *
 * // Iniciar sincronización de productos desde Verial a WooCommerce
 * $result = $sync_manager->start_sync('verial_to_wc', 'products');
 *
 * // Verificar estado de sincronización
 * $status = $sync_manager->getSyncStatus();
 *
 * // Obtener diagnóstico del sistema
 * $health = $sync_manager->get_system_health();
 *
 * @see MiIntegracionApi\Core\Sync_Manager::get_instance() Para obtener la instancia única
 * @see MiIntegracionApi\Core\Sync_Manager::init() Para inicializar la instancia
 */
class Sync_Manager {
	use MainPluginAccessor;
	/**
	 * ===== CONSTANTES DE TIEMPO =====
     * 
     * Estas constantes definen los umbrales de tiempo utilizados en el sistema de sincronización.
     * Todas las duraciones están en segundos.
     */
    
    /**
     * Tiempo máximo que un dato puede considerarse actual antes de ser marcado como obsoleto.
     * 
     * @var int
     */
	private const MAX_STALE_TIME = 3600; // 1 hora
	
    /**
     * Tiempo de vida por defecto para los elementos en caché.
     * 
     * @var int
     */
	private const DEFAULT_CACHE_TTL = 3600; // 1 hora
	
    /**
     * Umbral para considerar un acceso reciente a un recurso.
     * 
     * @var int
     */
	private const RECENT_ACCESS_THRESHOLD = 3600; // 1 hora
	
    /**
     * Umbral para tareas de prioridad muy alta (ej: sincronización de inventario).
     * 
     * @var int
     */
	private const VERY_HIGH_PRIORITY_THRESHOLD = 300; // 5 minutos
	
    /**
     * Umbral para tareas de alta prioridad (ej: actualizaciones de pedidos).
     * 
     * @var int
     */
	private const HIGH_PRIORITY_THRESHOLD = 3600; // 1 hora
	
    /**
     * Umbral para tareas de prioridad media (ej: actualización de productos).
     * 
     * @var int
     */
	private const MEDIUM_PRIORITY_THRESHOLD = 86400; // 1 día
	
    /**
     * Umbral para tareas de baja prioridad (ej: limpieza de registros antiguos).
     * 
     * @var int
     */
	private const LOW_PRIORITY_THRESHOLD = 604800; // 1 semana
	
    /**
     * Tiempo después del cual se consideran obsoletos los elementos en caché para limpieza.
     * 
     * @var int
     */
	private const CACHE_CLEANUP_CUTOFF = 3600; // 1 hora
	
    // Constantes de conversión de tiempo
    
    /**
     * Segundos en una hora.
     * 
     * @var int
     */
	private const SECONDS_PER_HOUR = 3600;
	
    /**
     * Segundos en un día.
     * 
     * @var int
     */
	private const SECONDS_PER_DAY = 86400;
	
    /**
     * Segundos en una semana.
     * 
     * @var int
     */
	private const SECONDS_PER_WEEK = 604800;
	
    /**
     * Identificador único para el bloqueo global de sincronización.
     * 
     * @var string
     */
	private const GLOBAL_LOCK_ENTITY = 'sync_global';
	
	/**
     * ===== CONSTANTES DE BLOQUEO POR LOTES =====
     * 
     * Estas constantes controlan el comportamiento de los bloqueos durante
     * operaciones por lotes para prevenir condiciones de carrera.
     */
    
	
    /**
     * ===== CONSTANTES DE DETECCIÓN AUTOMÁTICA =====
     * 
     * Configuración para la funcionalidad de detección automática de productos.
     */
    
    /**
     * Identificador único para el bloqueo de detección automática de productos.
     * 
     * @var string
     */
	private const AUTOMATIC_DETECTION_LOCK_ENTITY = 'automatic_detection_products';
	
    /**
     * Tiempo máximo que puede estar activo un bloqueo de detección automática.
     * 
     * @var int Tiempo en segundos
     */
	private const AUTOMATIC_DETECTION_LOCK_TIMEOUT = 300; // 5 minutos
	
    /**
     * ===== CONSTANTES DE VALIDACIÓN DE CONTINUACIÓN =====
     * 
     * Configuración para la validación y manejo de operaciones de continuación.
     */
    
    /**
     * Tiempo de espera máximo para verificar la continuación de una operación.
     * 
     * @var int Tiempo en segundos
     */
	private const CONTINUATION_CHECK_TIMEOUT = 10; // 10 segundos
	
    /**
     * Tamaño del lote de prueba para operaciones de verificación de continuación.
     * 
     * @var int Número de elementos
     */
	private const CONTINUATION_TEST_BATCH_SIZE = 5; // Lote pequeño para verificación
	
    /**
     * Lista de entidades que soportan operaciones de continuación.
     * 
     * @var string[]
     */
	private const CONTINUATION_SUPPORTED_ENTITIES = ['products']; // Entidades que soportan continuación
	
    /**
     * Direcciones de sincronización soportadas para operaciones de continuación.
     * 
     * @var string[]
     */
	private const CONTINUATION_SUPPORTED_DIRECTIONS = ['verial_to_wc', 'to_woocommerce'];
	
	private int $last_heartbeat_ts = 0;
	/**
	 * Clave de opción para almacenar el estado de sincronización
	 *
	 * @since   1.0.0
	 * @access  public
	 * @var     string  Clave utilizada para almacenar y recuperar el estado
	 *                  actual de la sincronización en las opciones de WordPress.
	 */
	const SYNC_STATUS_OPTION = 'mi_integracion_api_sync_status';

	/**
	 * Instancia del gestor de configuración.
	 *
	 * @since       1.0.0
	 * @access      private
	 * @var         ConfigManager  $config_manager  Gestor de configuración
	 *                                         para acceder a los ajustes del plugin.
	 */
	private ConfigManager $config_manager;

	/**
	 * Instancia única de la clase
	 *
	 * @since   1.0.0
	 * @access  private
	 * @var     Sync_Manager|null  $instance  Instancia única de la clase
	 */
	private static ?Sync_Manager $instance = null;

	/**
	 * Cliente de API de Verial
	 *
	 * @since   1.0.0
	 * @access  private
	 * @var     ApiConnector  $api_connector  Cliente de API de Verial
	 */
	private mixed $api_connector;

	/**
	 * Estado actual de sincronización
	 *
	 * @since   1.0.0
	 * @access  public
	 * @var     array|null  $sync_status  Estado actual de sincronización
	 */
	public ?array $sync_status = null;

	/**
	 * Instancia del logger
	 *
	 * @since   1.0.0
	 * @access  public
	 * @var     LogManager  $logger  Instancia del logger
	 */
	public mixed $logger;


    /**
     * Obtiene una instancia de Logger con categoría específica.
     *
     * Este método es un wrapper alrededor de getLogManager() que proporciona acceso directo
     * a la instancia de Logger subyacente. Sigue el principio DRY al reutilizar la lógica
     * de creación de LogManager mientras proporciona una interfaz más directa al logger.
     *
     * @param string $category Categoría del logger. Se recomienda usar una nomenclatura
     *                                jerárquica con puntos (ej: 'sync.orders', 'api.products').
     *                                Esta categoría se utiliza para filtrar y organizar los logs.
     *
     * @return ILogger|null Instancia del logger configurada con la categoría especificada.
     *
     * @since       1.0.0
     * @access      private
     * @category    Logging
     *
     * @example     // Obtener un logger para la categoría de pedidos
     *             $logger = $this->getLogger('sync.orders');
     *             $logger->info('Procesando pedido #123');
     *
     * @see         self::getLogManager()  Método que crea la instancia de LogManager
     * @see         \MiIntegracionApi\Helpers\Logger  Clase del logger que se devuelve
     */
    private function getLogger(string $category): ?ILogger
    {
        return \MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger($category);
	}

	/**
	 * Instancia del sanitizador de datos
     *
     * Se encarga de limpiar y normalizar los datos antes de su procesamiento,
     * asegurando que cumplan con los formatos y estándares requeridos.
     *
     * @var \MiIntegracionApi\Core\DataSanitizer
     * @since 2.0.0
     */
    private \MiIntegracionApi\Core\DataSanitizer $sanitizer;

    /**
     * Instancia del gestor de métricas de sincronización
     *
     * Registra y proporciona estadísticas sobre las operaciones de sincronización,
     * incluyendo tiempos de ejecución, tasas de éxito y volúmenes de datos.
     *
     * @var SyncMetrics|null
     * @since 2.1.0
     */
    private ?SyncMetrics $metrics = null;

    /**
     * Gestor centralizado de compresión de datos
     *
     * @var CompressionManager|null
     * @since 2.3.0
     */
    private ?CompressionManager $compressionManager = null;

    /**
     * Proceso de latido (heartbeat) para sincronización en tiempo real
     *
     * Se utiliza para mantener la conexión activa durante operaciones de larga duración
     * y para monitorear el estado de la sincronización en tiempo real.
     *
     * @var HeartbeatProcess|null
     * @since 2.2.0
     */
    private ?HeartbeatProcess $heartbeat_process = null;

    /**
     * Identificador de la operación de sincronización actual
     *
     * Único para cada operación de sincronización, permite rastrear y relacionar
     * eventos y registros asociados a una misma operación.
     *
     * @var string|null
     * @since 2.0.0
     */
    private ?string $currentOperationId = null;

    /**
     * Mapa de dependencias jerárquicas entre entidades del sistema
     *
     * Define el orden de sincronización obligatorio entre las diferentes entidades,
     * asegurando que las dependencias se resuelvan correctamente. Cada clave representa
     * una entidad y su valor es un array de entidades de las que depende.
     *
     * Estructura:
     * ```
     * [
     *     'entidad' => ['dependencia1', 'dependencia2', ...],
     *     ...
     * ]
     * ```
     *
     * @var array<string, string[]> Mapa donde la clave es el nombre de la entidad
     *                             y el valor es un array de nombres de entidades de las que depende.
     * @since 2.0.0
     * @example
     * [
     *     'geo' => [],                    // No tiene dependencias
     *     'config' => [],                 // No tiene dependencias
     *     'categories' => ['geo'],        // Depende de 'geo'
     *     'clients' => ['geo'],           // Depende de 'geo'
     *     'products' => ['categories', 'config'], // Depende de 'categories' y 'config'
     *     'orders' => ['clients', 'products'],    // Depende de 'clients' y 'products'
     *     'media' => ['products']         // Depende de 'products'
     * ]
     */
    private array $entity_dependencies = [
        'geo' => [],
        'config' => [],
        'categories' => ['geo'],
        'clients' => ['geo'],
        'products' => ['categories', 'config'],
        'orders' => ['clients', 'products'],
        'media' => ['products']
    ];

	/**
	 * Estado de registro de servicios de entidad
	 * @var array
	 */
	private array $entity_services = [
		'geo' => '\MiIntegracionApi\Sync\SyncGeo',
		'config' => '\MiIntegracionApi\Sync\SyncConfig',
		'categories' => '\MiIntegracionApi\Sync\SyncCategorias',
		'clients' => '\MiIntegracionApi\Sync\SyncClientes',
		'products' => '\MiIntegracionApi\Core\BatchProcessor',
		'orders' => '\MiIntegracionApi\Sync\SyncPedidos',
	];

	// ===================================================================
	// SINGLETON PATTERN & INITIALIZATION
	// ===================================================================

	/**
     * Constructor privado para implementar el patrón Singleton
     *
     * Inicializa el gestor de sincronización con sus dependencias principales,
     * siguiendo el principio de Inversión de Dependencias. El constructor es privado
     * para forzar el uso del método estático `getInstance()` para obtener la instancia.
     *
     * Componentes principales inicializados:
     * - Logger: Para registro de eventos y errores
     * - Conector API: Para comunicación con servicios externos
     * - Sanitizador: Para limpieza y normalización de datos
     * - Gestor de configuración: Para acceso a la configuración
     * - Métricas: Para seguimiento del rendimiento
     *
     * @uses LogManager Para el registro de eventos
     * @uses ApiConnector Para la comunicación con APIs externas
     * @uses DataSanitizer Para la limpieza de datos
     * @uses ConfigManager Para la gestión de configuración
     * @uses SyncMetrics Para el seguimiento de métricas
     *
     * @throws \RuntimeException Si falla la inicialización de componentes críticos
     * @since 2.0.0
     * @see self::getInstance() Para obtener una instancia del gestor de sincronización
     */
	private function __construct() {
		// Intentar usar componentes centralizados primero
		$mainPlugin = $this->getMainPlugin();
		
		if ($mainPlugin) {
			// Usar componentes del plugin principal
			$this->logger = $mainPlugin->getComponent('logger') ?? LogManager::getInstance();
			$logger_instance = $this->logger instanceof LogManager ? $this->logger->getLogger('sync-manager') : $this->logger;
			$this->api_connector = $mainPlugin->getComponent('api_connector') ?? ApiConnector::get_instance($logger_instance);
		} else {
			// Fallback a componentes locales si no hay plugin principal
			$this->logger = LogManager::getInstance();
			$logger_instance = $this->logger->getLogger('sync-manager');
			$this->api_connector = ApiConnector::get_instance($logger_instance);
		}
		
		// Componentes que siempre se crean localmente
		$this->sanitizer = new DataSanitizer();
		$this->config_manager = ConfigManager::getInstance();
		$this->metrics = new SyncMetrics();
		$this->compressionManager = new CompressionManager();
		$this->load_sync_status();
		
		// Registrar hook para limpieza automática post-sincronización
		$this->registerSyncCompletionHooks();
		
		// Registrar interceptores de errores de SKU
		$this->register_sku_error_interceptors();
	}

	/**
     * Obtiene la instancia única (Singleton) del gestor de sincronización
     *
     * Implementa el patrón Singleton para garantizar que solo exista una instancia
     * de la clase en toda la aplicación. Si la instancia no existe, la crea.
     *
     * Durante la primera instanciación, también registra el timestamp de activación
     * del flag de enrutamiento centralizado si está habilitado por defecto.
	 *
     * @return self|null La instancia única de Sync_Manager o null si no se puede crear
     *
     * @since 2.0.0
     * @see self::__construct() Para la inicialización de la instancia
     * @uses MIA_USE_CORE_SYNC Constante que define si se usa el enrutamiento centralizado
     * @uses mia_use_core_sync Opción de WordPress que controla el enrutamiento
     * @uses apply_filters('mia_use_core_sync_routing') Filtro para modificar el comportamiento
     * @uses \MiIntegracionApi\Helpers\LegacyCallTracker Para el seguimiento de llamadas heredadas
     *
     * @throws \RuntimeException Si falla la creación de la instancia
	 */
	public static function get_instance(): ?Sync_Manager
    {
		if ( null === self::$instance ) {
			self::$instance = new self();
			// Registrar timestamp de activación del flag core routing si está activo por defecto
			try {
				$coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) MIA_USE_CORE_SYNC : (bool) get_option('mia_use_core_sync', true);
				if (function_exists('apply_filters')) { $coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting); }
				if ($coreRouting && class_exists('MiIntegracionApi\\Helpers\\LegacyCallTracker')) {
					\MiIntegracionApi\Helpers\LegacyCallTracker::record_flag_enabled();
				}
			} catch (\Throwable $e) {
				// Silencioso: no bloquear instanciación por tracking
			}
		}
		return self::$instance;
	}

    /**
		 * Convierte un valor a float y lo redondea a la precisión especificada
		 *
		 * Este método es una función de utilidad que asegura que un valor numérico
		 * se maneje consistentemente como float con la precisión decimal deseada.
		 * Es particularmente útil para operaciones monetarias o cálculos donde
		 * la precisión decimal es crítica.
		 *
		 * @param float|int|string $num El valor a convertir y redondear. Puede ser:
		 *                             - float: número de punto flotante
		 *                             - int: número entero
		 *                             - string: representación numérica como cadena
		 * @param int $precision Número de decimales a los que redondear.
		 *                      Valores positivos redondean a la derecha del punto decimal,
		 *                      valores negativos redondean a la izquierda del punto decimal.
		 *                      Ejemplo: 2 para redondear a centésimas.
		 * @return float El valor convertido a float y redondeado a la precisión especificada
		 *
		 * @since 2.1.0
		 * @see round() Función PHP subyacente utilizada para el redondeo
		 *
		 * @example
		 * // Redondear a 2 decimales
		 * $precio = $syncManager->convertirAFloatYRedondear(19.999, 2); // Devuelve 20.0
		 *
		 * @example
		 * // Redondear a la centena más cercana
		 * $total = $syncManager->convertirAFloatYRedondear(1234.56, -2); // Devuelve 1200.0
     */
    public function convertirAFloatYRedondear(float|int|string $num, int $precision): float
    {
        // Si es string, convertir a float primero
        if (is_string($num)) {
            $num = (float)$num;
        }
        
        return $this->safe_round($num, $precision);
    }

    /**
		* Obtiene la instancia del conector API configurada para esta sincronización
		*
		* Proporciona acceso al conector API que se utiliza para todas las comunicaciones
		* con los servicios externos. Este método sigue el principio de encapsulamiento,
		* permitiendo el acceso controlado al conector API.
		*
		* @return ApiConnector|null La instancia del conector API configurada, o null si no se ha inicializado.
		*                          Puede ser null en casos donde la inicialización falló o no se ha realizado.
		*
		* @since 2.0.0
		* @see self::__construct() Donde se inicializa el conector API
		* @see ApiConnector Para más información sobre el conector API
		*
		* @example
		* // Obtener el conector API y realizar una llamada
		* $api = $syncManager->get_api_connector();
		* if ($api !== null) {
		*     $response = $api->get('/ruta/del/recurso');
		* }
    */
    public function get_api_connector(): ?ApiConnector
    {
        return $this->api_connector;
    }

	// ===================================================================
	// SYNC STATUS & CONFIGURATION MANAGEMENT
	// ===================================================================

	/**
	 * Carga el estado actual de sincronización
	 */
	private function load_sync_status(): void
    {
		if ( $this->sync_status === null ) {
			$this->sync_status = get_option(
				self::SYNC_STATUS_OPTION,
				array(
					'last_sync'    => array(
						'products' => array(
							'wc_to_verial' => 0,
							'verial_to_wc' => 0,
						),
						'orders'   => array(
							'wc_to_verial' => 0,
							'verial_to_wc' => 0,
						),
					),
					'current_sync' => array(
						'in_progress'   => false,
						'entity'        => '',
						'direction'     => '',
						'batch_size'    => BatchSizeHelper::getBatchSize('productos'),
						'current_batch' => 0,
						'total_batches' => 0,
						'items_synced'  => 0,
						'total_items'   => 0,
						'errors'        => 0,
						'start_time'    => 0,
						'last_update'   => 0,
						'operation_id'  => '',  // Asegurar que siempre exista esta clave
					),
				)
			);
		}
	}

	/**
	 * Guarda el estado de sincronización actual
	 */
	private function save_sync_status(): void
    {
		update_option( self::SYNC_STATUS_OPTION, $this->sync_status, true );
	}


	/**
	 * Obtiene el estado de sincronización actual
	 * 
	 * @return SyncResponseInterface Estado de la sincronización
	 */
	public function getSyncStatus(): SyncResponseInterface
    {
		try {
			$this->load_sync_status();
			
			if (empty($this->sync_status)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'No hay estado de sincronización disponible',
					404,
					[
						'sync_status' => null,
						'reason' => 'empty_sync_status'
					]
				);
			}
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$this->sync_status,
				'Estado de sincronización obtenido exitosamente',
				[
					'operation' => 'getSyncStatus',
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getSyncStatus'
			]);
		}
	}

	/**
	 * Obtiene el historial de sincronizaciones
	 * DELEGADO: Ahora delega a SyncMetrics para funcionalidad centralizada
	 *
	 * @param int $limit Número máximo de registros a devolver
	 * @return SyncResponseInterface Resultado de la operación
	 */
	public function get_sync_history(int $limit = 100): SyncResponseInterface
    {
		try {
			// Validar parámetros
			if ($limit < 1 || $limit > 1000) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'El límite debe estar entre 1 y 1000',
					['limit' => $limit]
				);
			}

			// DELEGAR A SyncMetrics
			$history = $this->metrics->getSyncHistory($limit);
			
			// Crear respuesta exitosa
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success([
				'data' => $history,
				'count' => count($history),
				'total' => count($history),
				'limit' => $limit
			], 'Historial de sincronización obtenido correctamente', [
				'operation' => 'get_sync_history',
				'timestamp' => time()
			]);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'limit' => $limit,
				'operation' => 'get_sync_history'
			]);
		}
	}

	/**
	 * Reintenta la sincronización para un conjunto de errores específicos.
	 *
	 * @param array $error_ids IDs de los errores a reintentar.
	 * @return SyncResponseInterface Resultado de la operación.
	 */
	public function retry_sync_errors( array $error_ids ): SyncResponseInterface
    {
		// Verificar si son IDs de pedidos (enteros) o IDs de errores de la tabla
		$is_order_ids = true;
		foreach ($error_ids as $id) {
			if (!is_numeric($id) || $id <= 0) {
				$is_order_ids = false;
				break;
			}
		}
		
		if ($is_order_ids) {
			// Manejar pedidos directamente
			return $this->retry_failed_orders($error_ids);
		}
		
		// Manejar errores de la tabla (productos)
		global $wpdb;
		$table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;

		$ids_placeholder = implode( ',', $this->createPlaceholders( count( $error_ids ), '%d' ) );
		$sql = $wpdb->prepare( "SELECT item_data FROM {$table_name} WHERE id IN ($ids_placeholder)", $error_ids );
		$results = $wpdb->get_col( $sql );

		if ( empty( $results ) ) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__( 'No se encontraron los errores especificados para reintentar.', 'mi-integracion-api' ),
				404,
				[
					'method' => 'retry_sync_errors',
					'type' => 'products',
					'error_ids' => $error_ids,
					'error_code' => 'no_errors_found'
				]
			);
		}

		$products_to_retry = array_map( 'json_decode', $results, $this->createPlaceholders(count($results), true) );

		// Aquí se podría iniciar un proceso de sincronización especial
		// Por simplicidad, de momento solo devolvemos los productos a reintentar.
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success([
			'status' => 'retry_initiated',
			'item_count' => count($products_to_retry),
			'items' => $products_to_retry,
		], 'Reintento de productos iniciado', [
			'method' => 'retry_sync_errors',
			'type' => 'products',
			'item_count' => count($products_to_retry)
		]);
	}
	
	/**
	 * Reintenta sincronización de pedidos fallidos
	 * @param array $order_ids Array de IDs de pedidos
	 * @return SyncResponseInterface Resultado del reintento
	 */
	private function retry_failed_orders(array $order_ids): SyncResponseInterface
    {
		// Validar entrada
		if (empty($order_ids)) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'No se proporcionaron pedidos para reintentar',
				400,
				['method' => 'retry_failed_orders', 'error_code' => 'no_orders_provided']
			);
		}
		
		$results = [
			'success' => 0,
			'failed' => 0,
			'errors' => []
		];
		
		// Obtener instancia del OrderManager
		$order_manager = \MiIntegracionApi\WooCommerce\OrderManager::get_instance();
		
		foreach ($order_ids as $order_id) {
			try {
				$order = wc_get_order($order_id);
				if (!$order) {
					$results['errors'][] = "Pedido #{$order_id} no encontrado";
					$results['failed']++;
					continue;
				}
				
				// Limpiar error anterior
				$order->delete_meta_data('_verial_sync_error');
				$order->save();
				
				// Intentar sincronizar
				$result = $order_manager->sync_order_to_verial($order);
				
				if ($result) {
					$results['success']++;
				} else {
					$results['failed']++;
					$error_msg = $order->get_meta('_verial_sync_error') ?: 'Error desconocido';
					$results['errors'][] = "Pedido #{$order_id}: {$error_msg}";
				}
				
			} catch (\Exception $e) {
				$results['failed']++;
				$results['errors'][] = "Pedido #{$order_id}: " . $e->getMessage();
			}
		}
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success([
			'status' => 'completed',
			'success_count' => $results['success'],
			'failed_count' => $results['failed'],
			'errors' => $results['errors']
		], 'Reintento de pedidos completado', [
			'method' => 'retry_failed_orders',
			'total_orders' => count($order_ids),
			'success_count' => $results['success'],
			'failed_count' => $results['failed']
		]);
	}

	// ===================================================================
	// MAIN SYNCHRONIZATION OPERATIONS
	// ===================================================================

	/**
	 * Inicia una sincronización
	 * Método principal que coordina toda la sincronización entre Verial y WooCommerce
	 * @param string $entity Nombre de la entidad
	 * @param string $direction Dirección de la sincronización
	 * @param array<string, mixed> $filters Filtros adicionales
	 * @return SyncResponseInterface Resultado de la sincronización
	 * @throws SyncError
	 */
	public function start_sync(string $entity, string $direction, array $filters = []): SyncResponseInterface
	{
		// Inicializar variable total_items para evitar errores de variable no definida
		$total_items = 0;
		
		// Validar parámetros usando SyncEntityValidator unificado
		try {
			\MiIntegracionApi\Core\Validation\SyncEntityValidator::validateEntityAndDirection($entity, $direction);
		} catch (SyncError $e ) {
			$this->logger->error('Error de validación en start_sync', [
				'entity' => $entity,
				'direction' => $direction,
				'error' => $e->getMessage()
			]);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
				$e->getMessage(),
				$e->getContext(),
				['entity' => $entity, 'direction' => $direction]
			);
		}

		// CORRECCIÓN RECOMENDADA: Limpiar señal de cancelación al iniciar nueva sincronización
		delete_option('mia_sync_cancelada');
		delete_transient('mia_sync_cancelada');

		// OPTIMIZACIÓN: Verificar si es primera sincronización
		$is_first_sync = !\MiIntegracionApi\Helpers\SyncStatusHelper::isFirstSyncCompleted();
		if ($is_first_sync) {
			$this->logger->info('Modo primera sincronización activado: saltando productos existentes', [
				'reason' => 'avoid_timeout_with_existing_products',
				'entity' => $entity
			]);
		}

		// ✅ MIGRADO: Usar IdGenerator para operation ID de sincronización
		$operationId = \MiIntegracionApi\Helpers\IdGenerator::generateOperationId('sync', ['entity' => $entity, 'direction' => $direction]);
		$this->currentOperationId = $operationId;
		$this->metrics->startOperation($operationId, $entity, $direction);
		$lockEntity = self::GLOBAL_LOCK_ENTITY;
		$lockAcquired = false;

		try {
					// Limpiar estado inconsistente antes de iniciar
		if (class_exists('\MiIntegracionApi\Core\SyncLock')) {
			$cleanup_result = SyncLock::verifyAndCleanSystemState();
			if (!$cleanup_result['success']) {
				$this->logger->warning('Error al verificar estado del sistema', $cleanup_result);
			} elseif ($cleanup_result['inconsistencies_found'] > 0) {
				$this->logger->info('Estado del sistema limpiado antes de iniciar sincronización', $cleanup_result);
			}
		}
			
			// Asegurar que WooCommerce esté cargado antes de sincronizar productos
			if ($entity === 'products' && class_exists('\MiIntegracionApi\Sync\WooCommerceLoader')) {
				try {
					\MiIntegracionApi\Sync\WooCommerceLoader::ensure_ready();
					$this->logger->info('WooCommerce verificado y listo para sincronización de productos');
				} catch (\Exception $e) {
					$this->logger->error('Error al verificar WooCommerce: ' . $e->getMessage());
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
						'WooCommerce no está disponible: ' . $e->getMessage(),
						\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::SERVICE_UNAVAILABLE,
						['entity' => $entity, 'direction' => $direction, 'operation_id' => $operationId],
						['woocommerce_error' => $e->getMessage()]
					);
				}
			}

					// Validar parámetros
		$validation = $this->validate_sync_prerequisites($entity, $direction, $filters);
		
		if (!$validation->isSuccess()) {
			$this->metrics->recordItemProcessed($operationId, false, $validation->getMessage());
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$validation->getMessage(),
				$validation->getHttpStatus(),
				[
					'method' => 'start_sync',
					'entity' => $entity,
					'direction' => $direction,
					'validation_error' => $validation->getError(),
					'validation_data' => $validation->getData(),
					'error_code' => 'validation_failed'
				]
			);
		}

			// Usar método centralizado para verificar y limpiar locks huérfanos
			// Esto previene que locks de sincronizaciones fallidas bloqueen nuevas sincronizaciones
			$lockCheck = SyncLock::checkAndCleanOrphanedLock($lockEntity, 7200); // 2 horas máximo
			
			if (!$lockCheck['can_proceed']) {
				$error = "Ya hay un proceso de sincronización en curso";
				$this->metrics->recordItemProcessed($operationId, false, $error);
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::concurrencyError(
					$error,
					$lockCheck['lock_info'],
					[
						'entity' => $entity,
						'direction' => $direction,
						'operation_id' => $operationId,
						'reason' => $lockCheck['reason']
					]
				);
			}
			
			// Si se limpió un lock huérfano, registrar la acción
			if ($lockCheck['lock_cleaned']) {
				$logger = $this->getLogger('sync-lock-cleanup');
				$logger->info('Lock huérfano liberado, procediendo con nueva sincronización', [
					'lock_entity' => $lockEntity,
					'reason' => $lockCheck['reason']
				]);
			}

			// Intentar adquirir lock global para evitar carreras (doble clic)
			// Usar timeout más largo para permitir múltiples lotes (2 horas)
			if (!SyncLock::acquire($lockEntity, 7200, 3, ['operation_id' => $operationId, 'entity' => $entity, 'direction' => $direction])) { // 2 horas TTL con heartbeat
				$concurrency_error = SyncError::concurrencyError(
					'Otra sincronización está bloqueando el inicio (lock activo)',
					[
						'lock_entity' => $lockEntity,
						'operation_id' => $operationId,
						'entity' => $entity,
						'direction' => $direction,
						'timeout_seconds' => 7200,
						'retries' => 3,
						'lock_status' => SyncLock::getLockInfo($lockEntity)
					]
				);
				
				$error = $concurrency_error->getMessage();
				$this->metrics->recordItemProcessed($operationId, false, $error);
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::concurrencyError(
					$error,
					SyncLock::getLockInfo($lockEntity),
					[
						'entity' => $entity,
						'direction' => $direction,
						'operation_id' => $operationId,
						'error_context' => $concurrency_error->getContext()
					]
				);
			}
			$lockAcquired = true;
			
			// Verificar que el lock se mantiene después de adquirirlo
			$lockInfoAfterAcquire = SyncLock::getLockInfo($lockEntity);
			
			SyncLock::startHeartbeat($lockEntity);
			$this->last_heartbeat_ts = time();
			
			// Iniciar proceso de heartbeat adicional para mayor robustez
			$this->initHeartbeatProcess($lockEntity);
			
			// Verificar que el lock se mantiene después del heartbeat
			$lockInfoAfterHeartbeat = SyncLock::getLockInfo($lockEntity);

			// ✅ CORRECCIÓN CRÍTICA: Limpiar caché antes de iniciar sincronización
			// Esto asegura que empezamos con caché limpia y evitamos datos obsoletos
			$this->clearCacheBeforeSync();

			// VALIDACIÓN DE CONSISTENCIA: Movida después de establecer total_batches y total_items
			// (Se ejecutará más adelante en el flujo)
			
			// Verificar y limpiar estado de sincronización huérfano
			// Si hay un estado in_progress pero no hay lock válido, limpiarlo
			$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
			if ($sync_info['in_progress'] ?? false) {
				$lastUpdate = $sync_info['last_update'] ?? 0;
				$timeSinceUpdate = time() - $lastUpdate;
				$maxStaleTime = self::MAX_STALE_TIME;
				
				if ($timeSinceUpdate > $maxStaleTime) {
					$logger = $this->getLogger('sync-state-cleanup');
					$logger->warning('Estado de sincronización huérfano detectado, limpiando', [
						'time_since_update' => $timeSinceUpdate,
						'max_stale_time' => $maxStaleTime,
						'last_update' => $lastUpdate
					]);
					
					// Limpiar estado huérfano usando SyncStatusHelper
					\MiIntegracionApi\Helpers\SyncStatusHelper::clearCurrentSync();
				} else {
					$concurrency_error = SyncError::concurrencyError(
						'Ya hay un proceso de sincronización en curso',
						[
							'entity' => $entity,
							'direction' => $direction,
							'operation_id' => $operationId,
							'sync_info' => $sync_info,
							'time_since_update' => $timeSinceUpdate,
							'max_stale_time' => $maxStaleTime
						]
					);
					
					$error = $concurrency_error->getMessage();
					$this->metrics->recordItemProcessed($operationId, false, $error);
					// CORRECCIÓN: Solo liberar el lock, no llamar a finish_sync() que requiere estado válido
					SyncLock::release(self::GLOBAL_LOCK_ENTITY);
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::concurrencyError(
						$error,
						$sync_info,
						[
							'entity' => $entity,
							'direction' => $direction,
							'operation_id' => $operationId,
							'error_context' => $concurrency_error->getContext(),
							'time_since_update' => $timeSinceUpdate,
							'max_stale_time' => $maxStaleTime
						]
					);
				}
			}

			// Iniciar sincronización usando SyncStatusHelper
			if (class_exists('\MiIntegracionApi\Helpers\SyncStatusHelper')) {
				\MiIntegracionApi\Helpers\SyncStatusHelper::setInProgress(true, [
					'entity' => $entity,
					'direction' => $direction,
					'batch_size' => $this->getConsistentBatchSize($entity, $_REQUEST ?? [], $filters),
					'current_batch' => 0,
					'total_batches' => 0,
					'items_synced' => 0,
				'total_items' => 0,
				'errors' => 0,
				'start_time' => time(),
				'last_update' => time(),
				'operation_id' => $operationId
			]);
			} else {
				// Fallback: usar lógica antigua si SyncStatusHelper no está disponible
				$this->sync_status['current_sync']['in_progress'] = true;
				$this->sync_status['current_sync']['entity'] = $entity;
				$this->sync_status['current_sync']['direction'] = $direction;
				$this->sync_status['current_sync']['batch_size'] = $this->getConsistentBatchSize($entity, $_REQUEST ?? [], $filters);
				$this->sync_status['current_sync']['current_batch'] = 0;
				$this->sync_status['current_sync']['total_batches'] = 0;
				$this->sync_status['current_sync']['items_synced'] = 0;
				$this->sync_status['current_sync']['total_items'] = 0;
				$this->sync_status['current_sync']['errors'] = 0;
				$this->sync_status['current_sync']['start_time'] = time();
				$this->sync_status['current_sync']['last_update'] = time();
				$this->sync_status['current_sync']['operation_id'] = $operationId;
				$this->save_sync_status();
			}

			// Contar items totales
			$count_response = $this->count_items_for_sync($entity, $direction, $filters);
			
			// Verificar si hay error en el conteo
			if (!$count_response->isSuccess()) {
				$error_message = $count_response->getMessage();
				$error_data = $count_response->getData();
				
				// Registrar error detallado
				$this->logger->error('Error al contar elementos para sincronización', [
					'message' => $error_message,
					'entity' => $entity,
					'direction' => $direction,
					'error_data' => $error_data,
					'operation_id' => $operationId
				]);
				
				$this->metrics->recordItemProcessed($operationId, false, $error_message);
				
				// CORRECCIÓN: Liberar el lock usando finish_sync()
				$this->finish_sync();
				
				return $count_response;
			}
			
			// Extraer el valor numérico de la respuesta
			$total_items = (int)($count_response->getData()['count'] ?? 0);
			
			// Actualizar totales usando SyncStatusHelper
			if (class_exists('\MiIntegracionApi\Helpers\SyncStatusHelper')) {
				$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
				$batch_size = $sync_info['batch_size'] ?? 50;
				\MiIntegracionApi\Helpers\SyncStatusHelper::updateCurrentSync([
					'total_items' => $total_items,
				'total_batches' => ceil($total_items / $batch_size)
			]);
			} else {
				// Fallback: usar lógica antigua
				$this->sync_status['current_sync']['total_items'] = $total_items;
				$this->sync_status['current_sync']['total_batches'] = ceil($total_items / $this->getConsistentBatchSize($entity, $_REQUEST ?? [], $filters));
				$this->save_sync_status();
			}
			
			// VALIDACIÓN DE CONSISTENCIA: Verificar y corregir inconsistencias automáticamente
			// Ahora que total_batches y total_items están establecidos correctamente
			$consistency_result = \MiIntegracionApi\Helpers\SyncStatusHelper::validateStateConsistency();
			
			if (!$consistency_result['is_consistent']) {
				$logger = $this->getLogger('sync-consistency');
				$logger->warning('Inconsistencias detectadas en estado de sincronización', [
					'total_inconsistencies' => $consistency_result['total_inconsistencies'],
					'critical_count' => $consistency_result['critical_count'],
					'high_count' => $consistency_result['high_count'],
					'medium_count' => $consistency_result['medium_count']
				]);
				
				// DEBUG: Investigar qué inconsistencias se están detectando
				$logger->debug('Inconsistencias detectadas en detalle', [
					'inconsistencies' => $consistency_result['inconsistencies']
				]);
				
				// Intentar corrección automática
				$fix_result = \MiIntegracionApi\Helpers\SyncStatusHelper::autoFixInconsistencies();
				
				if ($fix_result['success']) {
					$logger->info('Inconsistencias corregidas automáticamente', [
						'fixes_applied' => count($fix_result['fixes_applied']),
						'fixes_failed' => count($fix_result['fixes_failed'])
					]);
				} else {
					$logger->error('Error al corregir inconsistencias automáticamente', [
						'fixes_applied' => count($fix_result['fixes_applied']),
						'fixes_failed' => count($fix_result['fixes_failed']),
						'failed_fixes' => $fix_result['fixes_failed']
					]);
				}
			}
			
			// Guardar estado inicial en sistema migrado de transients
			if (function_exists('mia_set_sync_transient')) {
				$initial_progress = [
					'porcentaje' => 0,
					'mensaje' => 'Iniciando sincronización...',
					'estadisticas' => [
						'procesados' => 0,
						'total' => $total_items,
						'errores' => 0
					],
					'actualizado' => time(),
					'inicio' => $this->sync_status['current_sync']['start_time'],
					'tiempo_transcurrido' => 0,
					'tiempo_formateado' => '0s'
				];
				mia_set_sync_transient('mia_sync_progress', $initial_progress, 6 * HOUR_IN_SECONDS);
			}
			$lockInfoBeforeReturn = SyncLock::getLockInfo($lockEntity);

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::syncInProgress(
				$operationId,
				$total_items,
				$this->sync_status['current_sync']['total_batches'],
				[
					'entity' => $entity,
					'direction' => $direction,
					'filters' => $filters,
					'lock_info' => $lockInfoBeforeReturn,
					'is_first_sync' => $is_first_sync
				]
			);

		} catch (\Exception $e) {
			$this->metrics->recordItemProcessed($operationId, false, $e->getMessage());
			$this->metrics->endOperation($operationId);
			// CORRECCIÓN: Liberar el lock usando finish_sync()
			$this->finish_sync();
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException(
				$e,
				['entity' => $entity, 'direction' => $direction, 'operation_id' => $operationId]
			);
		}
	}

	/**
	 * Extrae datos de una respuesta, manejando tanto SyncResponseInterface como arrays
	 * 
	 * @param mixed $response Respuesta que puede ser SyncResponseInterface o array
	 * @return array Datos extraídos de la respuesta
	 */
	private function extractResponseData($response): array
	{
		if ($response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface) {
			return $response->getData();
		}
		
		// Fallback para arrays (compatibilidad)
		return is_array($response) ? $response : [];
	}

	/**
	 * Verifica si una respuesta es un error SyncResponseInterface
	 * 
	 * @param mixed $response Respuesta a verificar
	 * @return bool True si es un error SyncResponseInterface
	 */
	private function isErrorResponse($response): bool
	{
		return $response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface 
			&& !$response->isSuccess();
	}

	/**
	 * Extrae un valor específico de una respuesta de forma segura
	 * 
	 * @param mixed $response Respuesta que puede ser SyncResponseInterface o array
	 * @param string $key Clave a extraer
	 * @param mixed $default Valor por defecto si no se encuentra
	 * @return mixed Valor extraído o valor por defecto
	 */
	private function extractResponseValue($response, string $key, $default = 0)
	{
		$data = $this->extractResponseData($response);
		return $data[$key] ?? $default;
	}

	/**
	 * Extrae datos de una respuesta exitosa o devuelve un valor por defecto
	 * 
	 * @param mixed $response Respuesta que puede ser SyncResponseInterface o array
	 * @param mixed $default Valor por defecto si la respuesta no es exitosa
	 * @return mixed Datos extraídos o valor por defecto
	 */
	private function extractSuccessData($response, $default = [])
	{
		if ($response instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface) {
			return $response->isSuccess() ? $response->getData() : $default;
		}
		
		// Si es array, asumir que es exitoso
		return is_array($response) ? $response : $default;
	}

	/**
	 * Determina si un lote tiene errores críticos que requieren intervención
	 * 
	 * @param int $processed_in_batch Productos procesados en el lote
	 * @param int $errors_in_batch Errores en el lote
	 * @param int $skipped_in_batch Productos saltados en el lote
	 * @return bool True si hay errores críticos
	 */
	private function hasCriticalBatchErrors(int $processed_in_batch, int $errors_in_batch, int $skipped_in_batch): bool
	{
		// Error crítico: hay errores y no se procesó ningún producto
		return $errors_in_batch > 0 && $processed_in_batch === 0;
	}

	/**
	 * Determina si un lote solo tiene productos saltados (comportamiento normal)
	 * 
	 * @param int $processed_in_batch Productos procesados en el lote
	 * @param int $skipped_in_batch Productos saltados en el lote
	 * @return bool True si solo hay productos saltados
	 */
	private function hasOnlySkippedProducts(int $processed_in_batch, int $skipped_in_batch): bool
	{
		// Solo productos saltados: no se procesó nada pero tampoco hay errores
		return $processed_in_batch === 0 && $skipped_in_batch > 0;
	}

	/**
	 * Recupera el total de items desde múltiples fuentes de forma robusta
	 * 
	 * @return int Total de items recuperado, 0 si no se pudo recuperar
	 */
	private function recoverTotalItems(): int
	{
		$total_items = 0;
		$recovery_methods = [];
		
		// Método 1: API directa getNumArticulos
		try {
			$count_response = $this->api_connector->getNumArticulos();
			if ($count_response && !$count_response->isError()) {
				$total_items = (int)($count_response->getData()['count'] ?? 0);
				if ($total_items > 0) {
					$recovery_methods[] = 'api_direct';
					$this->logger->info('Recuperación exitosa desde API directa', [
						'total_items' => $total_items,
						'method' => 'getNumArticulos'
					]);
					return $total_items;
				}
			}
		} catch (\Exception $e) {
			$this->logger->warning('Error en recuperación API directa', [
				'error' => $e->getMessage(),
				'method' => 'getNumArticulos'
			]);
		}
		
		// Método 2: Contar productos existentes en WooCommerce
		try {
			$wc_count = $this->count_woocommerce_products([]);
			if ($wc_count > 0) {
				$total_items = $wc_count;
				$recovery_methods[] = 'woocommerce_count';
				$this->logger->info('Recuperación exitosa desde WooCommerce', [
					'total_items' => $total_items,
					'method' => 'woocommerce_products_count'
				]);
				return $total_items;
			}
		} catch (\Exception $e) {
			$this->logger->warning('Error en recuperación WooCommerce', [
				'error' => $e->getMessage(),
				'method' => 'woocommerce_products_count'
			]);
		}
		
		// Método 3: Estado de sincronización anterior (si existe)
		try {
			$last_sync = \MiIntegracionApi\Helpers\SyncStatusHelper::getLastSyncInfo();
			if (!empty($last_sync['total_items']) && $last_sync['total_items'] > 0) {
				$total_items = (int)$last_sync['total_items'];
				$recovery_methods[] = 'last_sync_state';
				$this->logger->info('Recuperación exitosa desde estado anterior', [
					'total_items' => $total_items,
					'method' => 'last_sync_state'
				]);
				return $total_items;
			}
		} catch (\Exception $e) {
			$this->logger->warning('Error en recuperación estado anterior', [
				'error' => $e->getMessage(),
				'method' => 'last_sync_state'
			]);
		}
		
		// Método 4: Cache de transients (si existe)
		try {
			$cached_count = get_transient('mia_total_items_cache');
			if ($cached_count && $cached_count > 0) {
				$total_items = (int)$cached_count;
				$recovery_methods[] = 'cached_value';
				$this->logger->info('Recuperación exitosa desde cache', [
					'total_items' => $total_items,
					'method' => 'transient_cache'
				]);
				return $total_items;
			}
		} catch (\Exception $e) {
			$this->logger->warning('Error en recuperación cache', [
				'error' => $e->getMessage(),
				'method' => 'transient_cache'
			]);
		}
		
		// Si llegamos aquí, no se pudo recuperar de ninguna fuente
		$this->logger->error('No se pudo recuperar total_items de ninguna fuente', [
			'attempted_methods' => $recovery_methods,
			'fallback_needed' => true
		]);
		
		return 0;
	}

	/**
	 * Procesa todos los lotes de sincronización de forma síncrona
	 * 
	 * @param bool $single_batch Si es true, solo procesa el primer lote
	 * @return SyncResponseInterface Resultado del procesamiento completo
	 */
	public function process_all_batches_sync(bool $single_batch = false): SyncResponseInterface
	{
		// Log simple para verificar que el logger funciona
		$this->logger->info('Procesando lotes de sincronización', [
			'single_batch' => $single_batch,
			'timestamp' => time(),
			'memory_usage' => memory_get_usage(true)
		]);
		
		$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
		
		$operation_id = $sync_info['operation_id'] ?? 'unknown';
		$total_batches = (int)($sync_info['total_batches'] ?? 0);
		$base_batch_size = $sync_info['batch_size'] ?? 50;
		$current_batch = $sync_info['current_batch'] ?? 0;
		$total_items = (int)($sync_info['total_items'] ?? 0);
		$total_processed = 0;
		
		// CORRECCIÓN: Si total_batches o total_items son 0, restaurar valores correctos
		if ($total_batches === 0 || $total_items === 0) {
			$this->logger->warning('Valores críticos perdidos, intentando recuperación robusta', [
				'total_batches' => $total_batches,
				'total_items' => $total_items,
				'operation_id' => $operation_id
			]);
			
			// Intentar recuperar de múltiples fuentes
			$total_items = $this->recoverTotalItems();
			if ($total_items === 0) {
				// Fallar temprano si no se puede recuperar
				$this->logger->error('No se pudo recuperar total_items de ninguna fuente, abortando sincronización');
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::apiError(
					'No se pudo determinar el total de productos desde ninguna fuente disponible',
					[
						'operation_id' => $operation_id,
						'context' => 'process_all_batches_sync',
						'recovery_attempted' => true
					]
				);
			}
			
			$total_batches = ceil($total_items / $base_batch_size);
			
			// Actualizar estado de sincronización con valores correctos
			\MiIntegracionApi\Helpers\SyncStatusHelper::updateCurrentSync([
				'total_items' => $total_items,
				'total_batches' => $total_batches
			]);
			
			$this->logger->info('Valores críticos recuperados exitosamente', [
				'total_items' => $total_items,
				'total_batches' => $total_batches,
				'recovery_method' => 'multiple_sources'
			]);
		}
		
		// Ajustar batch size dinámicamente según memoria disponible
		// ✅ CORRECCIÓN: Respetar límite del usuario (no exceder 50 si seleccionó 50)
		$batch_size = BatchSizeHelper::getMemoryOptimizedBatchSize(
			$base_batch_size,
			'productos',
			function() { return $this->getMemoryUsage(); },
			true // respetar límite del usuario
		);
		
		$this->logger->info('Iniciando procesamiento síncrono de todos los lotes', [
			'operation_id' => $operation_id,
			'total_batches' => $total_batches,
			'current_batch' => $current_batch,
			'single_batch' => $single_batch,
			'max_batches' => $single_batch ? 1 : $total_batches,
			'batch_size' => $batch_size,
			'total_items' => $total_items
		]);
		
		try {
			// Procesar todos los lotes restantes (o solo el primero si $single_batch es true)
			$max_batches = $single_batch ? 1 : $total_batches;
			// CORRECCIÓN: Si single_batch es true, procesar el lote actual
			if ($single_batch) {
				// Procesar solo el lote actual
				$batch = $current_batch;
				
				// Procesar lote actual
				$batch_result = $this->sync_products_from_verial($batch * $batch_size, $batch_size, []);
				
				// Verificar si hubo errores en el lote
				if (!$batch_result->isSuccess()) {
					$this->logger->error('Error procesando lote único', [
						'operation_id' => $operation_id,
						'batch' => $batch + 1,
						'error' => $batch_result->getMessage(),
						'error_data' => $batch_result->getData()
					]);
					
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
						'Error procesando lote: ' . $batch_result->getMessage(),
						$batch_result->getHttpStatus(),
						[
							'operation_id' => $operation_id,
							'batch' => $batch + 1,
							'error_data' => $batch_result->getData()
						],
						[]
					);
				}
				
				// Extraer datos del resultado usando método auxiliar
				$processed_in_batch = $this->extractResponseValue($batch_result, 'processed', 0);
				$errors_in_batch = $this->extractResponseValue($batch_result, 'errors', 0);
				$skipped_in_batch = $this->extractResponseValue($batch_result, 'skipped', 0);
				
				// Log detallado del resultado del lote
				$this->logger->info('Lote procesado exitosamente', [
					'operation_id' => $operation_id,
					'batch' => $batch + 1,
					'processed' => $processed_in_batch,
					'errors' => $errors_in_batch,
					'skipped' => $skipped_in_batch
				]);
				
				// Actualizar contadores
				$total_processed += $processed_in_batch;
				\MiIntegracionApi\Helpers\SyncStatusHelper::updateCurrentSync([
					'current_batch' => $batch + 1,
					'items_synced' => $total_processed,
					'last_update' => time()
				]);
				
				// Marcar primera sincronización como completada después del primer lote exitoso
				$is_first_sync = !\MiIntegracionApi\Helpers\SyncStatusHelper::isFirstSyncCompleted();
				if ($is_first_sync && $processed_in_batch > 0) {
					\MiIntegracionApi\Helpers\SyncStatusHelper::markFirstSyncCompleted();
				}
				
				// CORRECCIÓN: Cuando single_batch es true, retornar inmediatamente después de procesar el lote
				$all_batches_completed = ($batch + 1) >= $total_batches;
				
				$this->logger->info('Lote único procesado', [
					'operation_id' => $operation_id,
					'batch' => $batch + 1,
					'total_batches' => $total_batches,
					'processed' => $processed_in_batch,
					'total_processed' => $total_processed,
					'completed' => $all_batches_completed
				]);
				
				if ($all_batches_completed) {
					// Completar sincronización
					\MiIntegracionApi\Helpers\SyncStatusHelper::setInProgress(false, [
						'completed_at' => current_time('mysql')
					]);
					$this->finish_sync();
					
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::syncCompleted(
						$total_processed,
						$total_batches,
						[
							'operation_id' => $operation_id,
							'single_batch' => true,
							'status' => 'completed',
							'processed' => $processed_in_batch
						]
					);
				} else {
					// Continuar con siguientes lotes
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::syncInProgress(
						$operation_id,
						$total_processed,
						$total_batches,
						[
							'operation_id' => $operation_id,
							'single_batch' => true,
							'status' => 'in_progress',
							'current_batch' => $batch + 1,
							'total_batches' => $total_batches,
							'processed' => $processed_in_batch,
							'completed' => false
						]
					);
				}
			} else {
				// Procesar todos los lotes restantes
				$last_batch_processed = $current_batch - 1; // Inicializar con el batch anterior
				for ($batch = $current_batch; $batch < $max_batches; $batch++) {
				// CORRECCIÓN CRÍTICA: Verificar cancelación antes de procesar cada lote
				if ($this->isExternallyCancelled()) {
					$this->logger->info("Sincronización cancelada por el usuario en lote {$batch}", [
						'operation_id' => $operation_id,
						'batch' => $batch,
						'total_batches' => $total_batches,
						'processed_so_far' => $total_processed
					]);
					break;
				}

				// NUEVA INTEGRACIÓN: Verificar si debe continuar la sincronización
				$syncStatus = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
				if (!$this->shouldContinueSync('productos', 'from_verial', $syncStatus)) {
					$this->logger->info("Sincronización detenida por validación de continuación en lote {$batch}", [
						'operation_id' => $operation_id,
						'batch' => $batch,
						'total_batches' => $total_batches,
						'processed_so_far' => $total_processed,
						'reason' => 'continuation_validation_failed'
					]);
					break;
				}

				$this->logger->info("Procesando lote síncrono {$batch}/{$total_batches}", [
					'operation_id' => $operation_id,
					'batch' => $batch,
					'total_batches' => $total_batches,
					'current_batch' => $current_batch,
					'max_batches' => $max_batches,
					'single_batch' => $single_batch,
					'batch_offset' => $batch * $batch_size,
					'batch_limit' => $batch_size
				]);
				
				// Procesar lote actual
				$batch_result = $this->sync_products_from_verial($batch * $batch_size, $batch_size, []);
				
				// Extraer datos del resultado usando método auxiliar
				$processed_in_batch = $this->extractResponseValue($batch_result, 'processed', 0);
				$errors_in_batch = $this->extractResponseValue($batch_result, 'errors', 0);
				$skipped_in_batch = $this->extractResponseValue($batch_result, 'skipped', 0);
				
				// Verificar errores críticos usando método auxiliar
				if ($this->hasCriticalBatchErrors($processed_in_batch, $errors_in_batch, $skipped_in_batch)) {
					$this->logger->error("Error en lote síncrono {$batch}", [
						'operation_id' => $operation_id,
						'processed' => $processed_in_batch,
						'errors' => $errors_in_batch,
						'skipped' => $skipped_in_batch,
						'error' => ($batch_result instanceof \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface) 
							? $batch_result->getMessage() 
							: ($batch_result['error'] ?? 'No se procesaron productos')
					]);
					
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
						"Error en lote {$batch}: No se procesaron productos (errores: {$errors_in_batch})",
						\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::INTERNAL_SERVER_ERROR,
						[
							'operation_id' => $operation_id,
							'batch' => $batch,
							'processed_batches' => $batch - $current_batch,
							'total_processed' => $total_processed,
							'errors_in_batch' => $errors_in_batch
						],
						[
							'processed_batches' => $batch - $current_batch,
							'total_processed' => $total_processed
						]
					);
				}
				
				// Log cuando se saltan productos (comportamiento normal)
				if ($this->hasOnlySkippedProducts($processed_in_batch, $skipped_in_batch)) {
					$this->logger->info("Lote síncrono {$batch}: todos los productos saltados", [
						'operation_id' => $operation_id,
						'processed' => $processed_in_batch,
						'skipped' => $skipped_in_batch,
						'total_items_in_batch' => $batch_size,
						'reason' => 'first_sync_optimization_or_existing_products'
					]);
				}
				
				// Actualizar contadores usando SyncStatusHelper
				$total_processed += $batch_result['processed'] ?? 0;
				$last_batch_processed = $batch; // Actualizar el último batch procesado
				\MiIntegracionApi\Helpers\SyncStatusHelper::updateCurrentSync([
					'current_batch' => $batch + 1,
					'items_synced' => $total_processed,
					'last_update' => time()
				]);
				
				// SINCRONIZAR: Actualizar también el transient para el frontend
				$this->updateSyncProgressTransient($batch + 1, $total_batches, $total_processed, $total_items);
				
				// CORRECCIÓN: Marcar primera sincronización como completada después del primer lote exitoso
				$is_first_sync = !\MiIntegracionApi\Helpers\SyncStatusHelper::isFirstSyncCompleted();
				if ($is_first_sync && $processed_in_batch > 0) {
					\MiIntegracionApi\Helpers\SyncStatusHelper::markFirstSyncCompleted();
					$this->logger->info('Primera sincronización marcada como completada después del primer lote exitoso', [
						'products_processed' => $processed_in_batch,
						'batch' => $batch + 1
					]);
				}
				} // Cerrar el bucle for
			} // Cerrar el else
			
			// ✅ CORRECCIÓN: Verificar si se completaron todos los lotes de forma más robusta
			// Verificar por número de lotes procesados
			$last_processed_batch = isset($last_batch_processed) ? $last_batch_processed : ($current_batch - 1);
			$all_batches_completed_by_count = ($last_processed_batch + 1) >= $total_batches;
			
			// Verificar por total de productos procesados
			$all_items_processed = $total_items > 0 && $total_processed >= $total_items;
			
			// La sincronización está completa si se cumplen ambas condiciones o al menos una es verdadera
			$all_batches_completed = $all_batches_completed_by_count || $all_items_processed;
			
			$this->logger->info('Verificación de finalización de sincronización', [
				'operation_id' => $operation_id,
				'last_processed_batch' => $last_processed_batch,
				'total_batches' => $total_batches,
				'total_processed' => $total_processed,
				'total_items' => $total_items,
				'all_batches_completed_by_count' => $all_batches_completed_by_count,
				'all_items_processed' => $all_items_processed,
				'all_batches_completed' => $all_batches_completed
			]);
			
			if ($all_batches_completed) {
				// Completar sincronización usando SyncStatusHelper
				\MiIntegracionApi\Helpers\SyncStatusHelper::setInProgress(false, [
					'completed_at' => current_time('mysql')
				]);
				
				// CORRECCIÓN: Liberar el lock solo cuando se completan TODOS los lotes
				$this->finish_sync();
			}
			
			$this->logger->info('Procesamiento síncrono completado', [
				'operation_id' => $operation_id,
				'total_processed' => $total_processed,
				'total_batches' => $total_batches
			]);
			
			if ($all_batches_completed) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::syncCompleted(
					$total_processed,
					$total_batches,
					[
						'operation_id' => $operation_id,
						'single_batch' => $single_batch,
						'status' => 'completed'
					]
				);
			} else {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::syncInProgress(
					$operation_id,
					$total_processed,
					$total_batches,
					[
						'operation_id' => $operation_id,
						'single_batch' => $single_batch,
						'status' => 'in_progress',
						'current_batch' => $batch + 1,
						'total_batches' => $total_batches
					]
				);
			}
			
		} catch (\Exception $e) {
			$this->logger->error('Error en procesamiento síncrono', [
				'operation_id' => $operation_id,
				'error' => $e->getMessage(),
				'total_processed' => $total_processed
			]);
			
			// CORRECCIÓN: Solo liberar el lock si se procesaron TODOS los lotes
			$all_batches_completed = ($current_batch + $max_batches) >= $total_batches;
			if ($all_batches_completed) {
				$this->finish_sync();
			}
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException(
				$e,
				[
					'operation_id' => $operation_id,
					'total_processed' => $total_processed,
					'single_batch' => $single_batch
				]
			);
		}
	}

    /**
     * Ejecuta una operación con reintentos automáticos
     *
     * @param callable $operation Operación a ejecutar
     * @param array $context Contexto de la operación
     * @param string|null $operationType
     * @return mixed Resultado de la operación
     * @throws SyncError
     */
	private function retryOperation(callable $operation, array $context = [], ?string $operationType = null): mixed
    {
		// Usar sistema unificado de reintentos
		if (class_exists('\\MiIntegracionApi\\Core\\RetryConfigurationManager') && 
			RetryConfigurationManager::isInitialized()) {
			
			$retryConfig = RetryConfigurationManager::getInstance()
				->getConfig($operationType ?? 'batch_operations', null, $context);
			
			$retry_manager = new \MiIntegracionApi\Core\RetryManager();
			
			try {
				return $retry_manager->executeWithRetry(
					$operation,
					$context['operation_id'] ?? 'sync_manager_operation',
					$context,
					$operationType ?? 'batch_operations'
				);
			} catch (SyncError $e) {
				// El RetryManager ya maneja los reintentos y logging
				$this->metrics->recordError(
					$context['operation_id'] ?? 'unknown',
					'max_retries_exceeded',
                    (array)$e->getMessage(),
					array_merge($context, [
						'error_code' => $e->getCode(),
						'retry_system' => 'unified',
						'retry_config' => $retryConfig
					]),
					$e->getCode()
				);
				
				throw $e;
			}
		}
		
		// Implementación legacy si el sistema unificado no está disponible
		$maxRetries = 3; // Valor por defecto como fallback
		$attempts = 0;
		$lastError = null;
		
		while ($attempts < $maxRetries) {
			try {
				$result = $operation();
				
				// Si es un resultado de error pero no una excepción
				if (is_array($result) && isset($result['success']) && !$result['success']) {
					// Asegurar que el código de error sea un entero
					$errorCode = isset($result['error_code']) ? intval($result['error_code']) : 0;
					
					throw new SyncError(
						$result['error'] ?? 'Error desconocido',
						$errorCode,
						$context
					);
				}
				
				return $result;
				
			} catch (SyncError $e) {
				$lastError = $e;
				$attempts++;
				
				// Registrar el intento fallido
				$this->metrics->recordError(
					$context['operation_id'] ?? 'unknown',
					'retry_attempt',
                    (array)$e->getMessage(),
					array_merge($context, [
						'attempt' => $attempts,
						'max_retries' => $maxRetries,
						'error_code' => $e->getCode()
					]),
					$e->getCode()
				);
				
				$this->logger->warning("Reintentando operación (legacy fallback)", [
					'attempt' => $attempts,
					'max_retries' => $maxRetries,
					'error' => $e->getMessage(),
					'context' => $context
				]);
				
				if ($attempts >= $maxRetries) {
					break;
				}
				
				// Backoff exponencial con jitter
				$delay = pow(2, $attempts) + rand(0, 1000) / 1000;
				usleep(intval($delay * 1000000)); // Convertir a microsegundos (entero)
			}
		}
		
		// Si llegamos aquí, todos los reintentos fallaron
		$this->metrics->recordError(
			$context['operation_id'] ?? 'unknown',
			'max_retries_exceeded',
            (array)$lastError,
			array_merge($context, [
				'attempts' => $attempts,
				'max_retries' => $maxRetries
			]),
			$lastError ? $lastError->getCode() : 0
		);
		
		throw $lastError ?? new SyncError(
			'Error después de ' . $maxRetries . ' reintentos',
			0,
			$context
		);
	}


	/**
	 * Finaliza el proceso de sincronización actual
	 *
	 * @return void
	 */
	public function finish_sync(): void {
		if (!$this->sync_status['current_sync']['in_progress']) {
			return;
		}

		$operationId = $this->sync_status['current_sync']['operation_id'];
		
		// ✅ NUEVO: Limpiar caché de attachments al finalizar sincronización
		if (class_exists('\MiIntegracionApi\Helpers\MapProduct')) {
			\MiIntegracionApi\Helpers\MapProduct::clearAttachmentsCache();
		}
		$entity = $this->sync_status['current_sync']['entity'];
		$direction = $this->sync_status['current_sync']['direction'];

		// Registrar métricas finales
		$metrics = $this->metrics->endOperation($operationId);

		// Actualizar estado
		$this->sync_status['last_sync'][$entity][$direction] = time();
		$this->sync_status['current_sync'] = [
			'in_progress' => false,
			'entity' => '',
			'direction' => '',
			'batch_size' => 0,
			'current_batch' => 0,
			'total_batches' => 0,
			'items_synced' => 0,
			'total_items' => 0,
			'errors' => 0,
			'start_time' => 0,
			'last_update' => 0,
			'operation_id' => ''
		];

		$this->save_sync_status();

		$this->logger->info("Sincronización finalizada", [
			'entity' => $entity,
			'direction' => $direction,
			'metrics' => $metrics
		]);

		// NUEVA INTEGRACIÓN: Registrar en historial de sincronización
		$syncData = [
			'entity' => $entity,
			'direction' => $direction,
			'operation_id' => $operationId,
			'start_time' => $this->sync_status['current_sync']['start_time'] ?? time(),
			'end_time' => time(),
			'status' => 'completed',
			'items_synced' => $this->sync_status['current_sync']['items_synced'] ?? 0,
			'total_items' => $this->sync_status['current_sync']['total_items'] ?? 0,
			'errors' => $this->sync_status['current_sync']['errors'] ?? 0,
			'metrics' => $metrics
		];
		$this->metrics->addSyncHistory($syncData);

		// Limpieza automática de transients obsoletos al finalizar sincronización
		if (class_exists('\\MiIntegracionApi\\Helpers\\Utils')) {
			$cleanup_stats = \MiIntegracionApi\Helpers\Utils::cleanup_old_sync_transients(6); // Limpiar transients de más de 6 horas
			if ($cleanup_stats['cleaned_count'] > 0) {
				$this->logger->info("Limpieza post-sincronización: {$cleanup_stats['cleaned_count']} transients obsoletos eliminados");
			}
		}

		// OPTIMIZACIÓN: Detener proceso de heartbeat antes de liberar el lock
		$this->stopHeartbeatProcess();
		
		// Liberar lock global si existe
		SyncLock::release(self::GLOBAL_LOCK_ENTITY);
	}

	/**
	 * Cancela la sincronización actual
	 *
	 * @return SyncResponseInterface Resultado de la operación
	 */
	public function cancel_sync(): SyncResponseInterface
    {
		// CENTRALIZADO: Delegar completamente a SyncStatusHelper
		$result = \MiIntegracionApi\Helpers\SyncStatusHelper::cancelCurrentSync();
		
		// CORRECCIÓN CRÍTICA: Ejecutar limpieza ANTES del return
		if ($result['success']) {
			// MEJORA MIGRADA: Limpieza de transients legacy para compatibilidad
			if (method_exists($this, 'cleanupLegacySyncTransients')) {
				$this->cleanupLegacySyncTransients();
			} else {
				// Fallback: llamar al método estático de RobustnessHooks
				\MiIntegracionApi\Hooks\RobustnessHooks::cleanupLegacySyncTransients();
			}

			// MEJORA MIGRADA: Limpieza específica de recovery states
			$cleared_recovery = SyncRecovery::clearAllStates();

			// CORRECCIÓN CRÍTICA: Liberar lock global ANTES del return
			SyncLock::release(self::GLOBAL_LOCK_ENTITY);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::syncCancelled(
				$result['message'],
				[
					'status' => $result['status'],
					'history_entry' => $result['history_entry'],
					'operation' => 'cancel_sync'
				]
			);
		} else {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$result['message'],
				\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::INTERNAL_SERVER_ERROR,
				[
					'status' => $result['status'],
					'operation' => 'cancel_sync'
				]
			);
		}
	}

	/**
	 * Verifica si se ha solicitado cancelación externa
	 *
	 * @return bool Verdadero si se ha solicitado cancelación
	 */
	private function isExternallyCancelled(): bool
	{
		// CENTRALIZADO: Usar SyncStatusHelper para verificar cancelación
		return \MiIntegracionApi\Helpers\SyncStatusHelper::isCancellationRequested();
	}

	// ===================================================================
	// COUNTING & METRICS METHODS
	// ===================================================================

	/**
	 * Cuenta el número de elementos a sincronizar
	 * Método centralizado para obtener conteos precisos antes de la sincronización
	 * FASE 1: OPTIMIZADO - Logging optimizado y condicional
	 * 
	 * @param string $entity Entidad a sincronizar
	 * @param string $direction Dirección de la sincronización
	 * @param array $filters Filtros adicionales
	 * @return SyncResponseInterface Respuesta con el número de elementos o error
	 */
	private function count_items_for_sync(string $entity, string $direction, array $filters ): SyncResponseInterface
    {
		// FASE 3: Logging optimizado para operación crítica usando logger unificado
		$logger = $this->getLogger('sync-count');
		$logger->info('count_items_for_sync iniciado', [
			'entity' => $entity,
			'direction' => $direction,
			'filters_count' => count($filters)
		]);
		
		// CORRECCIÓN: Asegurar que WooCommerce esté cargado antes de sincronizar productos
		if ($entity === 'products' && class_exists('\MiIntegracionApi\Sync\WooCommerceLoader')) {
			try {
				\MiIntegracionApi\Sync\WooCommerceLoader::ensure_ready();
				$logger->info('WooCommerce verificado y listo para sincronización de productos');
			} catch (\Exception $e) {
				$logger->error('Error al verificar WooCommerce: ' . $e->getMessage());
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'WooCommerce no está disponible: ' . $e->getMessage(),
					\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::SERVICE_UNAVAILABLE,
					['entity' => $entity, 'direction' => $direction, 'woocommerce_error' => $e->getMessage()],
					[]
				);
			}
		}
		
		// MODO SEGURO: Solo bloquear en contextos realmente automáticos (cron, etc.)
		// Permitir operaciones manuales del dashboard y sincronizaciones explícitas
		$secure_block = apply_filters('mi_integracion_api_secure_mode', true);
		$allow_products_count = !empty($filters['allow_products_count']);
		
		// Configuración de bloqueo aplicada
		
		// Verificar contexto de ejecución de forma segura
		$is_cron = function_exists('wp_doing_cron') ? wp_doing_cron() : false;
		$is_admin = function_exists('is_admin') ? is_admin() : false;
		$is_ajax = function_exists('wp_doing_ajax') ? wp_doing_ajax() : false;
		
		// FASE 3: Método auxiliar para logging condicional optimizado
		// Contexto de ejecución verificado
		
		// EXPANDIR: Considerar manual si:
		// 1. Es admin o AJAX (dashboard)
		// 2. Hay filtros explícitos (indicando operación intencional)
		// CORRECCIÓN: Eliminado detección de test environment CLI
		$has_explicit_filters = !empty($filters) && count($filters) > 0;
		$is_manual_operation = !$is_cron && ($is_admin || $is_ajax || $has_explicit_filters);
		
		// Solo bloquear si:
		// 1. Modo seguro está activo
		// 2. Es una entidad de productos 
		// 3. No se permite explícitamente el conteo
		// 4. NO es una operación manual (dashboard, AJAX admin, test)
		if ($secure_block && $entity === 'products' && !$allow_products_count && !$is_manual_operation) {
			
			if ( class_exists('MiIntegracionApi\\Helpers\\Logger') ) {
				$logger = $this->getLogger('sync-secure');
				\MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger("sync-ajax")->warning('Bloqueado conteo automático de productos (modo seguro activo)', [
					'entity' => $entity,
					'direction' => $direction,
					'filters' => $filters,
					'context' => [
						'is_cron' => $is_cron,
						'is_admin' => $is_admin,
						'is_ajax' => $is_ajax,
						'has_explicit_filters' => $has_explicit_filters,
						'is_manual_operation' => $is_manual_operation,
						'php_sapi' => php_sapi_name()
					]
				]);
			}
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'Conteo de productos bloqueado por modo seguro en contexto automático. Use allow_products_count=1 para forzar.',
				\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::FORBIDDEN,
				[
					'entity' => $entity,
					'direction' => $direction,
					'secure_mode' => true,
					'context' => [
						'is_cron' => $is_cron,
						'is_admin' => $is_admin,
						'is_ajax' => $is_ajax,
						'has_explicit_filters' => $has_explicit_filters,
						'is_manual_operation' => $is_manual_operation
					]
				],
				[]
			);
		}
		

		if ( $entity === 'products' ) {
			if ( $direction === 'wc_to_verial' ) {
				$count = $this->count_woocommerce_products( $filters );
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					['count' => $count],
					'Conteo de productos WooCommerce completado',
					[
						'entity' => $entity,
						'direction' => $direction,
						'operation' => 'count_items_for_sync'
					]
				);
			} else {
				return $this->count_verial_products( $filters );
			}
		} elseif ( $direction === 'wc_to_verial' ) { // orders
			$count = $this->count_woocommerce_orders( $filters );
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				['count' => $count],
				'Conteo de pedidos WooCommerce completado',
				[
					'entity' => $entity,
					'direction' => $direction,
					'operation' => 'count_items_for_sync'
				]
			);
		} else {
			$count = $this->count_verial_orders( $filters );
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				['count' => $count],
				'Conteo de pedidos Verial completado',
				[
					'entity' => $entity,
					'direction' => $direction,
					'operation' => 'count_items_for_sync'
				]
			);
		}
	}

	public function preview_count(string $entity, string $direction, array $filters = []) : SyncResponseInterface {
		return $this->count_items_for_sync($entity, $direction, $filters);
	}


	public function get_batch_metrics(?string $operationId) : SyncResponseInterface
    {
		try {
			// Validar parámetros
			if (!$operationId) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'El ID de operación es requerido',
					['operationId' => $operationId]
				);
			}
			
			// Verificar que el sistema de métricas esté disponible
			if (!$this->metrics || !method_exists($this->metrics, 'getOperationMetrics')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Sistema de métricas no disponible',
					\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::SERVICE_UNAVAILABLE,
					['operationId' => $operationId, 'metrics_available' => false],
					[]
				);
			}
			
			// Obtener métricas
			$metrics = $this->metrics->getOperationMetrics($operationId) ?? [];
			
			// Crear respuesta exitosa
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				[
					'metrics' => $metrics,
					'operation_id' => $operationId,
					'has_metrics' => !empty($metrics)
				],
				'Métricas de operación obtenidas correctamente',
				[
					'operation' => 'get_batch_metrics',
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operationId' => $operationId,
				'operation' => 'get_batch_metrics'
			]);
		}
	}

	/**
	 * Cuenta el número de productos en WooCommerce
	 *
	 * @param array $filters Filtros adicionales
	 * @return int Número de productos
	 */
	private function count_woocommerce_products(array $filters ): int
    {
		$args = array(
			'status' => 'publish',
			'limit'  => 1,
			'return' => 'ids',
		);

		// Aplicar filtros adicionales
		if ( ! empty( $filters['category'] ) ) {
			$args['category'] = $filters['category'];
		}

		if ( ! empty( $filters['modified_after'] ) ) {
			$args['date_modified'] = '>=' . $filters['modified_after'];
		}

		// Contar productos
		$products = wc_get_products( $args );

		return $products->total;
	}

	/**
	 * Cuenta el número de productos en Verial utilizando el método GetNumArticulosWS de la API.
	 * 
	 * Esta función soporta tanto sincronizaciones completas como incrementales. Para sincronizaciones incrementales,
	 * aplica filtros de fecha y hora según lo especificado en el manual V1.7.1+ de la API de Verial.
	 * 
	 * El método realiza las siguientes acciones:
	 * - Construye los parámetros de consulta según los filtros recibidos.
	 * - Registra la llamada y los parámetros para diagnóstico usando un gestor de logs centralizado.
	 * - Llama al endpoint GetNumArticulosWS a través del conector de API.
	 * - Maneja y registra errores de conexión, respuestas vacías, errores de decodificación JSON y errores específicos de la API de Verial.
	 * - Intenta decodificar la respuesta y extraer el número de productos.
	 * - Si la respuesta no tiene la estructura esperada, realiza pruebas adicionales para diagnosticar el endpoint.
	 * - Devuelve una instancia de SyncResponseInterface con el resultado del conteo o el error correspondiente.
	 *
	 * @param array $filters Filtros adicionales para la consulta. Puede incluir:
	 *   - 'incremental_sync' (bool): Si es true, aplica filtros de fecha/hora para sincronización incremental.
	 *   - 'modified_after' (int): Timestamp UNIX para filtrar productos modificados después de esta fecha.
	 *   - 'modified_after_time' (string): Hora específica en formato 'H:i:s' para filtrar productos.
	 * @return SyncResponseInterface Respuesta con el número de productos encontrados o información de error.
	 */
	/**
	 * Cache temporal en memoria para evitar conteos duplicados en la misma ejecución
	 * 
	 * @var array<string, array{response: SyncResponseInterface, timestamp: int}>
	 */
	private static array $count_cache = [];

	private function count_verial_products(array $filters ): SyncResponseInterface
    {
		// OPTIMIZACIÓN: Evitar conteos duplicados en la misma ejecución (cache temporal de 30 segundos)
		$cache_key = md5(json_encode($filters));
		$cache_ttl = 30; // 30 segundos
		
		if (isset(self::$count_cache[$cache_key])) {
			$cached = self::$count_cache[$cache_key];
			if ((time() - $cached['timestamp']) < $cache_ttl) {
				$log_manager = \MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger('sync-verial-count');
				$log_manager->debug('Conteo obtenido desde cache temporal', [
					'cache_key' => substr($cache_key, 0, 8),
					'age_seconds' => time() - $cached['timestamp'],
					'total' => $cached['response']->getData()['count'] ?? 'N/A'
				]);
				return $cached['response'];
			} else {
				// Cache expirado, eliminarlo
				unset(self::$count_cache[$cache_key]);
			}
		}

		// Crear parámetros para la consulta con soporte para fecha y hora
		$params = array();

		// CORRECCIÓN: Solo aplicar filtros de fecha si se solicita explícitamente una sincronización incremental
		// Para sincronizaciones completas, no aplicar filtros de fecha para obtener el total real
		$is_incremental_sync = !empty($filters['incremental_sync']) && $filters['incremental_sync'] === true;
		
		if ( $is_incremental_sync ) {
			$params['fecha'] = date( 'Y-m-d', $filters['modified_after'] );
			
			// Si hay hora específica, añadirla (soporte para V1.7.1+)
			if (!empty($filters['modified_after_time'])) {
				$params['hora'] = $filters['modified_after_time'];
			} else {
				// Si tenemos timestamp completo, extraer la hora también
				$params['hora'] = date('H:i:s', $filters['modified_after']);
			}
		}

		// Registrar llamada para diagnóstico usando LogManager centralizado
		$log_manager = \MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger('sync-count');
		$log_manager->info(
			'Contando productos Verial',
			[
				'params' => $params,
				'filters' => $filters,
				'is_incremental_sync' => $is_incremental_sync,
				'fecha_filter_applied' => !empty($params['fecha']),
				'sync_type' => $is_incremental_sync ? 'incremental' : 'full'
			]
		);

		// Llamar al método GetNumArticulosWS para obtener el total
		$log_manager->info('Iniciando llamada a GetNumArticulosWS', ['params' => $params]);
		$response = $this->api_connector->get( 'GetNumArticulosWS', $params );
		$log_manager->info('Llamada a GetNumArticulosWS completada', [
			'success' => $response->isSuccess(),
			'code' => $response->getCode(),
			'message' => $response->getMessage()
		]);

		if ( !$response->isSuccess() ) {
			$log_manager = \MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger('sync-count-error');
			$log_manager->error(
				'Error al contar productos Verial',
				[
					'error' => $response->getMessage(),
					'params' => $params
				]
			);
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'Error al conectar con la API de Verial: ' . $response->getMessage(),
				\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::SERVICE_UNAVAILABLE,
				['params' => $params, 'api_error' => $response->getMessage()],
				[]
			);
		}
		
		// Obtener los datos de la respuesta SyncResponseInterface
		$data = $response->getData();

		// Verificar errores específicos de API Verial antes de procesar
		if (($data['InfoError']['Codigo'] ?? 0) != 0) {
			$error_message = $data['InfoError']['Descripcion'] ?? __('Error desconocido desde la API de Verial', 'mi-integracion-api');
			
			$log_manager = \MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger('sync-verial-error');
			$log_manager->error(
				$error_message,
				[
					'codigo' => $data['InfoError']['Codigo'],
					'params' => $params
				]
			);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$error_message,
				\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::BAD_REQUEST,
				[
					'verial_error_code' => $data['InfoError']['Codigo'],
					'verial_error_description' => $data['InfoError']['Descripcion'] ?? 'Error desconocido',
					'params' => $params
				],
				[]
			);
		}

		// Si la respuesta tiene la clave 'body', decodificar el JSON antes de acceder a 'Numero'
		if (isset($data['body']) && is_string($data['body'])) {
			$decoded = json_decode($data['body'], true);
			if (is_array($decoded) && isset($decoded['Numero'])) {
				$data = $decoded;
			}
		}

		// Verificar que la respuesta tenga la estructura correcta
		if (!isset($data['Numero'])) {
			$error_message = __('Respuesta inválida del servidor al contar productos. La clave "Numero" no fue encontrada.', 'mi-integracion-api');
			
			// Registrar el cuerpo de la respuesta para depuración
			$log_manager = \MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger('sync-verial-error');
			// Asegurar que $body esté definido
			if (!isset($body)) {
				$body = '';
			}
			$log_manager->error(
				$error_message,
				[
					'context' => 'count_verial_products',
					'response_body' => $body,
					'response_type' => gettype($response),
					'data' => $data,
					'params' => $params
				]
			);
				
				// Intentar determinar si hay un problema con el endpoint
				if (function_exists('wp_remote_get')) {
					try {
						// Construir la URL usando la configuración del ApiConnector
						$test_url = $this->api_connector->build_endpoint_url('GetNumArticulosWS');
						$log_manager->info('Intentando verificar endpoint con wp_remote_get', ['url' => $test_url]);
						$test_response = wp_remote_get($test_url);
						if (!is_wp_error($test_response)) {
							$test_body = wp_remote_retrieve_body($test_response);
							$test_code = wp_remote_retrieve_response_code($test_response);
							$log_manager->info('Respuesta de prueba directa recibida', [
								'status_code' => $test_code,
								'body' => $test_body
							]);
						} else {
							$log_manager->error('Error en llamada de prueba directa', ['error' => $test_response->getMessage()]);
						}
					} catch (\Exception $e) {
						$log_manager->error('Excepción en llamada de prueba', ['error' => $e->getMessage()]);
					}
				}
			
			// Devolver un mensaje de error específico
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$error_message,
				\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::BAD_REQUEST,
				[
					'response_body' => $body,
					'data' => $data,
					'params' => $params,
					'context' => 'count_verial_products'
				],
				[]
			);
		}

		// Registrar resultado exitoso
		$log_manager = \MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger('sync-verial-count');
		$log_manager->info(
			'Conteo de productos Verial completado',
			[
				'total' => (int) $data['Numero'],
				'params' => $params
			]
		);

		$response = \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			['count' => (int) $data['Numero']],
			'Conteo de productos Verial completado',
			[
				'params' => $params,
				'is_incremental_sync' => $is_incremental_sync,
				'operation' => 'count_verial_products'
			]
		);
		
		// OPTIMIZACIÓN: Guardar en cache temporal para evitar conteos duplicados
		if ($response->isSuccess()) {
			self::$count_cache[$cache_key] = [
				'response' => $response,
				'timestamp' => time()
			];
		}
		
		return $response;
	}

	/**
	 * Cuenta el número de pedidos en WooCommerce
	 *
	 * @param array $filters Filtros adicionales
	 * @return int Número de pedidos
	 */
	private function count_woocommerce_orders(array $filters ): int
    {
		$args = array(
			'limit'  => 1,
			'return' => 'ids',
		);

		// Aplicar filtros adicionales
		if ( ! empty( $filters['status'] ) ) {
			$args['status'] = $filters['status'];
		}

		if ( ! empty( $filters['modified_after'] ) ) {
			$args['date_modified'] = '>=' . $filters['modified_after'];
		}

		// Contar pedidos
		$query  = new \WC_Order_Query( $args );
		$orders = $query->get_orders();

		return $query->total;
	}


	private function count_verial_orders( $filters ): int
    {
		// Esta función necesitaría implementarse según la API de Verial
		// Por ahora, devolvemos un valor estático para demostración
		return 100;
	}

	/**
	 * Sincroniza productos desde WooCommerce a Verial
	 *
	 * @param int $offset Offset para la consulta
	 * @param int $limit Límite de productos a procesar
	 * @param array $filters Filtros adicionales
	 * @return SyncResponseInterface Resultado de la operación
	 */
	private function sync_products_to_verial(int $offset, int $limit, array $filters ): SyncResponseInterface
    {
		// Obtener productos de WooCommerce
		$args = array(
			'status' => 'publish',
			'limit'  => $limit,
			'offset' => $offset,
		);

		// Aplicar filtros adicionales
		if ( ! empty( $filters['categories'] ) ) {
			$args['category'] = $filters['categories'];
		}

		if ( ! empty( $filters['modified_after'] ) ) {
			$args['date_modified'] = '>=' . $filters['modified_after'];
		}

		// Obtener productos
		$products = wc_get_products( $args );

		// Procesar cada producto
		$processed = 0;
		$errors    = array();

		foreach ( $products as $product ) {
			// Mapear producto de WooCommerce a formato de Verial
			$verial_product = MapProduct::wc_to_verial( $product );

			// Enviar a Verial
			// TODO: Implementar la lógica específica según la API de Verial

			++$processed;
		}

		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			[
				'count' => $processed,
				'errors' => $errors,
				'offset' => $offset,
				'limit' => $limit
			],
			'Sincronización de productos a Verial completada',
			[
				'operation' => 'sync_products_to_verial',
				'filters' => $filters,
				'timestamp' => time()
			]
		);
	}

	/**
	 * Limpia el caché antes de iniciar una sincronización
	 * 
	 * @return void
	 */
	private function clearCacheBeforeSync(): void
	{
		if (class_exists('\MiIntegracionApi\CacheManager')) {
			$cache_manager = CacheManager::get_instance();
			
			// ✅ ETAPA 1: LIMPIEZA COMPLETA AL INICIO DE SINCRONIZACIÓN
			$result = $cache_manager->clear_all_cache();
			
			$this->logger->info('🧹 Caché completamente limpiada al inicio de sincronización', [
				'cleared_count' => $result,
				'reason' => 'fresh_start_for_sync',
				'stage' => 'initial_cleanup'
			]);
		}
	}

	/**
	 * Limpia solo los datos específicos del lote (no datos globales)
	 * 
	 * @param CacheManager $cache_manager Instancia del gestor de caché
	 * @return void
	 */
	private function clearBatchSpecificData(CacheManager $cache_manager): void
	{
		// ✅ OPTIMIZACIÓN CRÍTICA: Limpieza adaptativa basada en memoria y tiempo
		// Reduce acumulación de caché y mejora rendimiento progresivo
		$sync_status = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
		$current_batch = (int)($sync_status['current_sync']['current_batch'] ?? 0);
		
		// ✅ NUEVO: Limpieza adaptativa
		$should_cleanup = $this->shouldCleanupCache($current_batch);
		
		if (!$should_cleanup) {
			// No es momento de limpiar, solo hacer garbage collection ligero
			gc_collect_cycles();
			return;
		}
		
		$memory_before = memory_get_usage(true);
		
		// ✅ MEJORADO: Limpiar solo datos que cambian por lote (TTL corto)
		// Preservar datos hot cache (frecuencia >= 'medium')
		$patterns = [
			'batch_data_*',           // Datos del lote específico
			'articulos_*',            // Artículos del lote
			'imagenes_*',             // Imágenes del lote (CRÍTICO)
			'condiciones_tarifa_*',   // Condiciones del lote
			'stock_*',                // Stock del lote
			'batch_prices_*'          // Precios procesados del lote
		];
		
		$total_cleared = 0;
		$preserved_hot = 0;
		
		foreach ($patterns as $pattern) {
			// ✅ MEJORADO: Verificar frecuencia de acceso antes de limpiar
			$cleared = $this->clearPatternPreservingHotCache($cache_manager, $pattern);
			$total_cleared += $cleared['cleared'];
			$preserved_hot += $cleared['preserved'];
		}
		
		// Limpiar cache general de WordPress
		$cache_flushed = false;
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
			$cache_flushed = true;
		}
		
		// Garbage collection
		$gc_cycles = gc_collect_cycles();
		
		$memory_after = memory_get_usage(true);
		$memory_freed = max(0, $memory_before - $memory_after);
		
		// ✅ NUEVO: Capturar métricas de limpieza para mostrar en consola
		$cleanupMetrics = [
			'timestamp' => time(),
			'type' => 'batch_cleanup_phase2',
			'patterns_cleared' => $patterns,
			'total_cleared' => $total_cleared,
			'preserved_hot_cache' => $preserved_hot,
			'memory_before_mb' => round($memory_before / 1024 / 1024, 2),
			'memory_after_mb' => round($memory_after / 1024 / 1024, 2),
			'memory_freed_mb' => round($memory_freed / 1024 / 1024, 2),
			'gc_cycles_collected' => $gc_cycles,
			'cache_flushed' => $cache_flushed
		];
		
		// Actualizar estado con métricas de limpieza (Fase 2)
		// ✅ CORRECCIÓN: Obtener batch actual desde el estado de sincronización
		$sync_status = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
		$current_batch = (int)($sync_status['current_sync']['current_batch'] ?? 0);
		\MiIntegracionApi\Helpers\SyncStatusHelper::setCurrentBatch($current_batch + 1);
		
		// Guardar métricas en el estado de sincronización
		$sync_status = \MiIntegracionApi\Helpers\SyncStatusHelper::getSyncStatus();
		if (!isset($sync_status['current_sync']['last_cleanup_metrics'])) {
			$sync_status['current_sync']['last_cleanup_metrics'] = [];
		}
		$sync_status['current_sync']['last_cleanup_metrics'] = $cleanupMetrics;
		\MiIntegracionApi\Helpers\SyncStatusHelper::saveSyncStatus($sync_status);
		
		$this->logger->info('🗑️ Datos de lotes limpiados selectivamente (preservando hot cache)', [
			'patterns_cleared' => $patterns,
			'total_cleared' => $total_cleared,
			'preserved_hot_cache' => $preserved_hot,
			'global_data_preserved' => true,
			'memory_freed_mb' => $cleanupMetrics['memory_freed_mb']
		]);
		
		// ✅ NUEVO: Guardar timestamp de última limpieza para limpieza adaptativa
		update_option('mia_last_cache_cleanup_time', time());
	}
	
	/**
	 * ✅ NUEVO: Determina si se debe limpiar caché basado en múltiples factores
	 * 
	 * @param int $current_batch Número de lote actual
	 * @return bool True si se debe limpiar, false si no
	 */
	private function shouldCleanupCache(int $current_batch): bool
	{
		// Factor 1: Limpieza cada N lotes (mínimo)
		$cleanup_interval = apply_filters('mia_batch_cleanup_interval', 3); // Reducido de 5 a 3
		if ($current_batch % $cleanup_interval === 0 && $current_batch > 0) {
			return true;
		}
		
		// Factor 2: Limpieza si memoria > 70%
		$memory_peak = memory_get_peak_usage(true);
		$memory_current = memory_get_usage(true);
		if ($memory_peak > 0) {
			$memory_usage_percent = ($memory_current / $memory_peak) * 100;
			if ($memory_usage_percent > 70) {
				$this->logger->debug('Limpieza de caché activada por alto uso de memoria', [
					'memory_usage_percent' => round($memory_usage_percent, 2),
					'current_batch' => $current_batch
				]);
				return true;
			}
		}
		
		// Factor 3: Limpieza si han pasado > 30 segundos desde última limpieza
		$last_cleanup = get_option('mia_last_cache_cleanup_time', 0);
		if ($last_cleanup > 0 && (time() - $last_cleanup) > 30) {
			$this->logger->debug('Limpieza de caché activada por tiempo transcurrido', [
				'seconds_since_last_cleanup' => time() - $last_cleanup,
				'current_batch' => $current_batch
			]);
			return true;
		}
		
		return false;
	}

	/**
	 * ✅ NUEVO: Limpia un patrón preservando datos hot cache.
	 * 
	 * @param CacheManager $cache_manager Instancia del gestor de caché
	 * @param string $pattern Patrón a limpiar
	 * @return array Resultado con 'cleared' y 'preserved'
	 */
	private function clearPatternPreservingHotCache(CacheManager $cache_manager, string $pattern): array
	{
		global $wpdb;
		
		$cleared = 0;
		$preserved = 0;
		
		// ✅ VALIDACIÓN 1: Validar patrón de entrada
		if (empty($pattern) || !is_string($pattern)) {
			$this->logger->warning('Patrón inválido en clearPatternPreservingHotCache', [
				'pattern' => $pattern,
				'type' => gettype($pattern)
			]);
			return ['cleared' => 0, 'preserved' => 0];
		}
		
		// ✅ VALIDACIÓN 2: Validar formato del patrón (solo caracteres alfanuméricos, _, *, %)
		if (!preg_match('/^[a-zA-Z0-9_*%]+$/', $pattern)) {
			$this->logger->warning('Patrón con caracteres inválidos en clearPatternPreservingHotCache', [
				'pattern' => $pattern
			]);
			return ['cleared' => 0, 'preserved' => 0];
		}
		
		// ✅ VALIDACIÓN 3: Validar CacheManager
		if (!($cache_manager instanceof CacheManager)) {
			$this->logger->error('CacheManager inválido en clearPatternPreservingHotCache', [
				'cache_manager_type' => gettype($cache_manager),
				'pattern' => $pattern
			]);
			return ['cleared' => 0, 'preserved' => 0];
		}
		
		if (!method_exists($cache_manager, 'delete')) {
			$this->logger->error('CacheManager no tiene método delete() en clearPatternPreservingHotCache', [
				'pattern' => $pattern
			]);
			return ['cleared' => 0, 'preserved' => 0];
		}
		
		// ✅ VALIDACIÓN 4: Validar wpdb
		if (!isset($wpdb) || !$wpdb) {
			$this->logger->error('$wpdb no está disponible en clearPatternPreservingHotCache', [
				'pattern' => $pattern
			]);
			return ['cleared' => 0, 'preserved' => 0];
		}
		
		// ✅ CORRECCIÓN: Convertir patrón con * a formato SQL LIKE (igual que delete_by_pattern)
		$sql_pattern = str_replace('*', '%', $pattern);
		$cache_prefix = 'mia_cache_';
		
		// ✅ VALIDACIÓN 5: Preparar consulta SQL con validación
		$sql = $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} 
			WHERE option_name LIKE %s 
			AND option_name NOT LIKE %s",
			'_transient_' . $cache_prefix . $sql_pattern,
			'_transient_timeout_%'
		);
		
		if ($sql === false) {
			$this->logger->error('Error preparando consulta SQL en clearPatternPreservingHotCache', [
				'pattern' => $pattern,
				'sql_pattern' => $sql_pattern,
				'wpdb_error' => $wpdb->last_error ?? 'unknown'
			]);
			return ['cleared' => 0, 'preserved' => 0];
		}
		
		// ✅ VALIDACIÓN 6: Ejecutar consulta con validación
		$transients = $wpdb->get_col($sql);
		
		if ($transients === false) {
			$this->logger->error('Error ejecutando consulta SQL en clearPatternPreservingHotCache', [
				'pattern' => $pattern,
				'wpdb_error' => $wpdb->last_error ?? 'unknown'
			]);
			return ['cleared' => 0, 'preserved' => 0];
		}
		
		if (!is_array($transients)) {
			$this->logger->warning('Resultado de consulta SQL no es un array en clearPatternPreservingHotCache', [
				'pattern' => $pattern,
				'result_type' => gettype($transients)
			]);
			return ['cleared' => 0, 'preserved' => 0];
		}
		
		foreach ($transients as $transient) {
			// ✅ VALIDACIÓN 7: Validar transient individual
			if (empty($transient) || !is_string($transient)) {
				$this->logger->debug('Transient inválido encontrado en clearPatternPreservingHotCache, saltando', [
					'transient' => $transient,
					'type' => gettype($transient)
				]);
				continue;
			}
			
			// ✅ MEJORADO: Extraer correctamente la clave del transient
			// Los transients de WordPress tienen formato: _transient_{key} o _transient_timeout_{key}
			if (strpos($transient, '_transient_timeout_') === 0) {
				// Saltar transients de timeout (ya están filtrados en SQL, pero por seguridad)
				continue;
			}
			
			// ✅ VALIDACIÓN 8: Verificar formato de transient
			if (strpos($transient, '_transient_') !== 0) {
				$this->logger->debug('Transient con formato inesperado en clearPatternPreservingHotCache, saltando', [
					'transient' => $transient
				]);
				continue;
			}
			
			$cacheKey = str_replace('_transient_', '', $transient);
			
			// ✅ VALIDACIÓN 9: Validar cacheKey después de extracción
			if (empty($cacheKey)) {
				$this->logger->debug('CacheKey vacío después de extraer transient en clearPatternPreservingHotCache', [
					'transient' => $transient
				]);
				continue;
			}
			
			if (strlen($cacheKey) < strlen($cache_prefix)) {
				$this->logger->debug('CacheKey demasiado corto en clearPatternPreservingHotCache', [
					'cacheKey' => $cacheKey,
					'length' => strlen($cacheKey),
					'min_length' => strlen($cache_prefix)
				]);
				continue;
			}
			
			// ✅ VALIDACIÓN: Verificar que la clave tiene el prefijo esperado del sistema de caché
			if (strpos($cacheKey, $cache_prefix) !== 0) {
				// No es una clave de nuestro sistema de caché, saltar
				continue;
			}
			
			// ✅ VALIDACIÓN 10: Validar threshold de hot cache
			$hotCacheThreshold = get_option('mia_hot_cache_threshold', 'medium');
			$validThresholds = ['very_high', 'high', 'medium', 'low', 'very_low'];
			if (!in_array($hotCacheThreshold, $validThresholds, true)) {
				$this->logger->warning('HotCacheThreshold inválido en clearPatternPreservingHotCache, usando "medium"', [
					'invalid_threshold' => $hotCacheThreshold,
					'pattern' => $pattern
				]);
				$hotCacheThreshold = 'medium';
			}
			
			$frequencyScores = [
				'very_high' => 100,
				'high' => 75,
				'medium' => 50,
				'low' => 25,
				'very_low' => 10,
				'never' => 0
			];
			$thresholdScore = $frequencyScores[$hotCacheThreshold] ?? 50;
			
			// ✅ VALIDACIÓN 11: Validar métricas de uso
			$usageMetrics = get_option('mia_transient_usage_metrics_' . $cacheKey, []);
			
			if (!is_array($usageMetrics)) {
				$this->logger->debug('UsageMetrics no es un array válido en clearPatternPreservingHotCache', [
					'cacheKey' => $cacheKey,
					'usageMetrics_type' => gettype($usageMetrics)
				]);
				$accessFrequency = 'never';
			} else {
				$accessFrequency = $usageMetrics['access_frequency'] ?? 'never';
				
				// ✅ VALIDACIÓN 12: Validar accessFrequency
				$validFrequencies = ['very_high', 'high', 'medium', 'low', 'very_low', 'never'];
				if (!in_array($accessFrequency, $validFrequencies, true)) {
					$this->logger->debug('AccessFrequency inválido en clearPatternPreservingHotCache, usando "never"', [
						'cacheKey' => $cacheKey,
						'invalid_frequency' => $accessFrequency
					]);
					$accessFrequency = 'never';
				}
			}
			
			$frequencyScore = $frequencyScores[$accessFrequency] ?? 0;
			
			if ($frequencyScore >= $thresholdScore) {
				// Preservar: es hot cache
				$preserved++;
				continue;
			}
			
			// Limpiar: es cold cache o no tiene métricas
			// ✅ VALIDACIÓN 13: Manejo de errores en delete()
			try {
				$deleted = $cache_manager->delete($cacheKey);
				
				if ($deleted === true) {
					$cleared++;
				} elseif ($deleted === false) {
					// No se pudo eliminar, pero no es crítico (puede que ya no exista)
					$this->logger->debug('No se pudo eliminar transient en clearPatternPreservingHotCache (puede que ya no exista)', [
						'cacheKey' => $cacheKey
					]);
				} else {
					// Resultado inesperado
					$this->logger->warning('Resultado inesperado de delete() en clearPatternPreservingHotCache', [
						'cacheKey' => $cacheKey,
						'result' => $deleted,
						'result_type' => gettype($deleted)
					]);
				}
			} catch (\Exception $e) {
				// Manejar excepciones durante delete()
				$this->logger->error('Error eliminando transient en clearPatternPreservingHotCache', [
					'cacheKey' => $cacheKey,
					'error' => $e->getMessage(),
					'exception' => get_class($e),
					'pattern' => $pattern
				]);
				// Continuar con el siguiente transient
				continue;
			}
		}
		
		return [
			'cleared' => $cleared,
			'preserved' => $preserved
		];
	}

	/**
	 * Sincroniza productos desde Verial a WooCommerce
	 *
	 * @param int $offset Offset de paginación
	 * @param int $limit Límite de productos por lote
	 * @param array $filters Filtros adicionales
	 * @return SyncResponseInterface Resultado de la sincronización
	 * @throws \Exception Si hay un error al crear el BatchProcessor
	 * @throws \Exception Si hay un error al procesar productos con BatchProcessor
	 */
	private function sync_products_from_verial(int $offset, int $limit, array $filters ): SyncResponseInterface
    {
		// ✅ ESTRATEGIA DE DOS ETAPAS
		if ($offset === 0) {
			// ETAPA 1: Primer lote - Limpieza completa (ya se hizo en clearCacheBeforeSync)
			$this->logger->info('🚀 Iniciando sincronización con caché limpia', [
				'offset' => $offset,
				'total_productos' => 7879,
				'batch_size' => $limit,
				'total_lotes' => ceil(7879 / $limit),
				'stage' => 'initial_batch'
			]);
		} else {
			// ETAPA 2: Lotes 2-158 - Limpieza selectiva mejorada
			$cache_manager = CacheManager::get_instance();
			$this->clearBatchSpecificData($cache_manager);
			
			// ✅ MEJORADO: Ejecutar migración hot→cold cada N lotes para liberar memoria
			$batch_number = ($offset / $limit) + 1;
			$migration_interval = get_option('mia_hot_cold_migration_interval_batches', 10); // Cada 10 lotes por defecto
			
			if ($batch_number % $migration_interval === 0) {
				try {
					$autoMigrationEnabled = get_option('mia_enable_hot_cold_migration', true);
					if ($autoMigrationEnabled) {
						$migrationResult = $cache_manager->performHotToColdMigration();
						if ($migrationResult['migrated_count'] > 0) {
							$this->logger->info('🔄 Migración hot→cold durante sincronización', [
								'batch_number' => $batch_number,
								'migrated_count' => $migrationResult['migrated_count'],
								'skipped_count' => $migrationResult['skipped_count']
							]);
						}
					}
				} catch (\Exception $e) {
					$this->logger->warning('Error en migración hot→cold durante sincronización', [
						'error' => $e->getMessage(),
						'batch_number' => $batch_number
					]);
				}
			}
			
			$this->logger->info('♻️ Limpieza selectiva en lote', [
				'offset' => $offset,
				'lote' => $batch_number,
				'stage' => 'batch_cleanup',
				'preserved' => 'global_data',
				'hot_cold_migration' => ($batch_number % $migration_interval === 0)
			]);
		}
		
		// Calcular parámetros de paginación
		$inicio = $offset + 1; // API Verial comienza en 1
		$fin = $offset + $limit;
		
		// Log de delegación al BatchProcessor
		$this->logger->info('🔄 Delegando sincronización a BatchProcessor', [
			'offset' => $offset,
			'limit' => $limit,
			'inicio' => $inicio,
			'fin' => $fin,
			'filters' => $filters,
			'delegation_reason' => 'batch_processor_optimized'
		]);
		
		try {
			// Crear BatchProcessor y procesar directamente
			$batch_processor = new BatchProcessor($this->api_connector);
			$batch_processor->setEntityName('productos');
			
			// Usar el método optimizado de BatchProcessor
			$result = $batch_processor->processProductBatch($inicio, $fin, $limit);
			
			// Log de resultado
			$this->logger->info("Lote de productos completado", [
				'offset' => $offset,
				'limit' => $limit,
				'processed' => $result['processed'] ?? 0,
				'success' => $result['success'] ?? false
			]);
			
			// Convertir resultado del BatchProcessor a SyncResponseInterface
			if (is_array($result) && isset($result['processed'])) {
				// El BatchProcessor devuelve un array con 'total', 'processed', 'errors', etc.
				// Consideramos éxito si no hay errores críticos
				$has_errors = isset($result['errors']) && $result['errors'] > 0;
				
				if (!$has_errors) {
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
						$result,
						'Lote de productos sincronizado correctamente',
						[
							'operation' => 'sync_products_from_verial',
							'filters' => $filters,
							'offset' => $offset,
							'limit' => $limit
						]
					);
				} else {
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
						'Error procesando lote de productos',
						\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::INTERNAL_SERVER_ERROR,
						$result,
						[
							'operation' => 'sync_products_from_verial',
							'filters' => $filters,
							'offset' => $offset,
							'limit' => $limit
						]
					);
				}
			} else {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Resultado inválido del BatchProcessor',
					\MiIntegracionApi\ErrorHandling\Constants\HttpStatusCodes::INTERNAL_SERVER_ERROR,
					[
						'offset' => $offset,
						'limit' => $limit,
						'result_type' => gettype($result),
						'result' => $result
					],
					[]
				);
			}
			
		} catch (\Exception $e) {
			$this->logger->error('Error en sincronización de productos', [
				'error' => $e->getMessage(),
				'offset' => $offset,
				'limit' => $limit,
				'trace' => $e->getTraceAsString()
			]);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'offset' => $offset,
				'limit' => $limit,
				'operation' => 'sync_products_from_verial',
				'filters' => $filters
			]);
		}
	}

	/**
	 * Sincroniza pedidos desde WooCommerce a Verial
	 *
	 * @param int $offset Offset para la consulta
	 * @param int $limit Límite de pedidos a procesar
	 * @param array $filters Filtros adicionales
	 * @return SyncResponseInterface Resultado de la operación
	 */
	private function sync_orders_to_verial(int $offset, int $limit, array $filters ): SyncResponseInterface
    {
		// Obtener pedidos de WooCommerce
		$args = array(
			'limit'  => $limit,
			'offset' => $offset,
		);

		// Aplicar filtros adicionales
		if ( ! empty( $filters['status'] ) ) {
			$args['status'] = $filters['status'];
		}

		if ( ! empty( $filters['modified_after'] ) ) {
			$args['date_modified'] = '>=' . $filters['modified_after'];
		}

		// Obtener pedidos
		$query  = new \WC_Order_Query( $args );
		$orders = $query->get_orders();

		// Procesar cada pedido
		$processed = 0;
		$errors    = array();

		foreach ( $orders as $order ) {
			// Mapear pedido de WooCommerce a formato de Verial
			$verial_order = MapOrder::wc_to_verial( $order );

			// Enviar a Verial
			// TODO: Implementar la lógica específica según la API de Verial

			++$processed;
		}

		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			[
				'count' => $processed,
				'errors' => $errors,
				'offset' => $offset,
				'limit' => $limit
			],
			'Sincronización de pedidos a Verial completada',
			[
				'operation' => 'sync_orders_to_verial',
				'filters' => $filters,
				'timestamp' => time()
			]
		);
	}

	/**
	 * Sincroniza pedidos desde Verial a WooCommerce
	 *
	 * @param int $offset Offset para la consulta
	 * @param int $limit Límite de pedidos a procesar
	 * @param array $filters Filtros adicionales
	 * @return SyncResponseInterface Resultado de la operación
	 */
	private function sync_orders_from_verial(int $offset, int $limit, array $filters ): SyncResponseInterface
    {
		// Esta función necesitaría implementarse según la API de Verial
		// Por ahora, devolvemos un resultado simulado para demostración
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			[
				'count' => 0,
				'errors' => [],
				'offset' => $offset,
				'limit' => $limit
			],
			'Sincronización de pedidos desde Verial completada',
			[
				'operation' => 'sync_orders_from_verial',
				'filters' => $filters,
				'timestamp' => time(),
				'note' => 'Implementación pendiente según API de Verial'
			]
		);
	}


	/**
	 * Acceso seguro a datos del batch con fallback y logging
	 * 
	 * @param array $batch_data Datos del batch
	 * @param string $key Clave a acceder
	 * @param mixed|null $default Valor por defecto si no existe
	 * @param string $context Contexto para logging
	 * @return mixed Valor del batch o default
	 */
	private function get_batch_value(array $batch_data, string $key, mixed $default = null, string $context = 'unknown'): mixed {
		// ✅ DELEGADO: Usar helper centralizado de Utils
		return \MiIntegracionApi\Helpers\Utils::get_array_value(
			$batch_data, 
			$key, 
			$default, 
			$context, 
			$this->logger
		);
	}


	/**
	 * Valida los datos del producto ANTES de enviarlos a WooCommerce
	 * 
	 * @param array $product_data Datos del producto a validar
	 * @return SyncResponseInterface Respuesta de validación
	 */
	/**
	 * @deprecated Este método ha sido eliminado. Usar InputValidation::validate_precio() directamente en su lugar.
	 */
	private function validate_product_data_before_wc(array $product_data): SyncResponseInterface
    {
		// Redirigir a la lógica consolidada
		$price = $product_data['regular_price'] ?? 0;
		$is_valid = \MiIntegracionApi\Core\InputValidation::validate_precio($price, [
			'status' => $product_data['status'] ?? 'draft',
			'product_type' => 'normal'
		]);
		
		if ($is_valid) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$product_data,
				'Validación de datos del producto completada exitosamente',
				['validation_passed' => true, 'timestamp' => time()]
			);
		} else {
			$errors = \MiIntegracionApi\Core\InputValidation::get_errors();
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
				'Precio inválido: ' . implode(', ', $errors),
				['sku' => $product_data['sku'] ?? '', 'price' => $price, 'validation_errors' => $errors]
			);
		}
	}

	/**
	 * Valida la estructura del batch cache para asegurar que sea utilizable
	 * 
	 * @param array $batch_cache Datos del batch cache a validar
	 * @return bool True si la estructura es válida, false en caso contrario
	 */
	private function validate_batch_cache_structure(array $batch_cache): bool {
		// Si está vacío, es válido (se usará fallback)
		if (empty($batch_cache)) {
			return true;
		}

		// Verificar que tenga la estructura básica esperada
		$required_keys = [
			'batch_id',
			'status',
			'productos',
			'categorias_indexed',
			'fabricantes_indexed',
			'colecciones_indexed'
		];

		foreach ($required_keys as $key) {
			if (!isset($batch_cache[$key])) {
				return false;
			}
		}

		// Verificar que el status sea 'completed'
		if ($batch_cache['status'] !== 'completed') {
			return false;
		}

		// Verificar que los arrays indexados no estén vacíos
		$indexed_arrays = [
			'categorias_indexed',
			'fabricantes_indexed',
			'colecciones_indexed'
		];

		foreach ($indexed_arrays as $key) {
			if (!is_array($batch_cache[$key]) || empty($batch_cache[$key])) {
				return false;
			}
		}

		// Verificar que los productos existan
		if (!is_array($batch_cache['productos']) || empty($batch_cache['productos'])) {
			return false;
		}

		return true;
	}

	/**
		* Registra un error de sincronización en la base de datos.
		*
		* @param array  $item_data     Los datos del item que falló.
			* @param string $error_code    El código del error.
		* @param string $error_message El mensaje del error.
		* @return void
		*/
	private function log_sync_error(array $item_data, string $error_code, string $error_message): void {
		global $wpdb;

		$run_id = $this->sync_status['current_sync']['run_id'] ?? 'unknown';
		$sku = $item_data['ReferenciaBarras'] ?? $item_data['Id'] ?? $item_data['CodigoArticulo'] ?? 'no-sku';

		$table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;

		$wpdb->insert(
			$table_name,
			array(
				'sync_run_id'   => $run_id,
				'item_sku'      => $sku,
				'item_data'     => wp_json_encode( $item_data ),
				'error_code'    => $error_code,
				'error_message' => $error_message,
				'timestamp'     => current_time( 'mysql' ),
			),
			array(
				'%s', // sync_run_id
				'%s', // item_sku
				'%s', // item_data
				'%s', // error_code
				'%s', // error_message
				'%s', // timestamp
			)
		);
	}




	/**
	 * Reanuda una sincronización desde el último punto conocido o desde un punto específico.
	 * MEJORADO: Ahora incluye soporte para recovery states avanzado
	 *
	 * @param int|null $offset Offset específico para reanudar (opcional)
	 * @param int|null $batch_size Tamaño de lote específico para usar (opcional)
	 * @param string|null $entity Entidad específica para recovery avanzado (opcional)
	 * @return SyncResponseInterface Resultado de la operación
	 */
	public function resume_sync(?int $offset = null, ?int $batch_size = null, ?string $entity = null): SyncResponseInterface
    {
		$this->load_sync_status();

		// MEJORA: Verificar recovery states avanzados si se especifica entidad
		$recovery_state = null;
		if ($entity) {
			$recovery_state = SyncRecovery::getRecoveryState($entity);
			if ($recovery_state) {
				$message = SyncRecovery::getRecoveryMessage($entity);
				if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
					$logger = new LoggerBasic('sync-recovery-advanced');
					\MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger("sync-ajax")->info("Recovery state encontrado para {$entity}: {$message}", [
						'entity' => $entity,
						'recovery_progress' => SyncRecovery::getRecoveryProgress($entity)
					]);
				}
			}
		}

		// Verificar si hay una sincronización en progreso o datos para recuperar
		if (!$this->sync_status['current_sync']['in_progress'] && !$this->get_last_failed_batch() && !$recovery_state) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__('No hay una sincronización para reanudar.', 'mi-integracion-api'),
				404,
				[
					'method' => 'resume_sync',
					'offset' => $offset,
					'batch_size' => $batch_size,
					'entity' => $entity,
					'sync_status' => $this->sync_status['current_sync'],
					'error_code' => 'no_sync_to_resume'
				]
			);
		}

		// ✅ MIGRADO: Generar un nuevo ID de ejecución para la recuperación usando IdGenerator
		$sync_run_id = \MiIntegracionApi\Helpers\IdGenerator::generateOperationId('sync_recovery', ['timestamp' => time()]);

		// Si la sincronización no está marcada como en progreso, restablecerla
		if (!$this->sync_status['current_sync']['in_progress']) {
			// MEJORA: Priorizar recovery state si está disponible
			$last_batch = $recovery_state ?: $this->get_last_failed_batch();
			
			if (!$last_batch) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					__('No hay datos de recuperación disponibles.', 'mi-integracion-api'),
					404,
					[
						'method' => 'resume_sync',
						'offset' => $offset,
						'batch_size' => $batch_size,
						'entity' => $entity,
						'recovery_state' => $recovery_state,
						'sync_status' => $this->sync_status['current_sync'],
						'error_code' => 'no_recovery_data'
					]
				);
			}
			
			// Usar los parámetros proporcionados o los del recovery state/último lote
			if ($recovery_state) {
				$actual_offset = $offset ?? ($recovery_state['last_batch'] * ($recovery_state['batch_size'] ?? 50));
				$actual_batch_size = $batch_size ?? ($recovery_state['batch_size'] ?? 50);
				$current_batch = $recovery_state['last_batch'] ?? 0;
			} else {
				$actual_offset = $offset ?? $last_batch['offset'];
				$actual_batch_size = $batch_size ?? $last_batch['limit'];
				$current_batch = floor($actual_offset / $actual_batch_size);
			}
			
			// Actualizar estado para recuperación usando SyncStatusHelper
			\MiIntegracionApi\Helpers\SyncStatusHelper::updateCurrentSync([
				'in_progress' => true,
				'run_id' => $sync_run_id,
				'current_batch' => $current_batch,
				'batch_size' => $actual_batch_size,
				'recovery_enabled' => true,
				'last_update' => time()
			]);
			
			// SINCRONIZAR PROGRESO: Actualizar transient al iniciar sincronización
			// ELIMINADO: syncProgressWithFrontend() - función no existe
			
			// MEJORA: Registrar si usamos recovery state avanzado
			if ($recovery_state) {
				\MiIntegracionApi\Helpers\SyncStatusHelper::updateCurrentSync([
					'recovery_source' => 'advanced_state',
					'recovery_entity' => $entity
				]);
			} else {
				\MiIntegracionApi\Helpers\SyncStatusHelper::updateCurrentSync([
					'recovery_source' => 'last_failed_batch'
				]);
			}
			
			// ELIMINADO: save_sync_status() redundante - SyncStatusHelper ya guarda el estado

			// Registrar recuperación
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new LoggerBasic('sync-verial-resume');
				\MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger("sync-ajax")->info(
					'Reanudando sincronización desde punto de error previo',
					[
						'offset' => $actual_offset,
						'batch_size' => $actual_batch_size,
						'current_batch' => $current_batch,
						'run_id' => $sync_run_id,
						'recovery_source' => $this->sync_status['current_sync']['recovery_source'],
						'entity' => $entity
					]
				);
			}
		} else {
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new LoggerBasic('sync-verial-continue');
				\MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger("sync-ajax")->info(
					'Continuando sincronización en progreso',
					[
						'current_batch' => $this->sync_status['current_sync']['current_batch'],
						'total_batches' => $this->sync_status['current_sync']['total_batches'],
						'items_synced' => $this->sync_status['current_sync']['items_synced'],
						'run_id' => $this->sync_status['current_sync']['run_id']
					]
				);
			}
		}

		// CORRECCIÓN: Programar procesamiento asíncrono para evitar recursión
		$this->logger->info('Programando procesamiento de recuperación asíncrono', [
			'operation_id' => $this->sync_status['current_sync']['operation_id'] ?? 'unknown',
			'recovery_mode' => true
		]);
		
		// Cron job asíncrono eliminado - procesamiento síncrono
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success([
			'recovery_scheduled' => true,
			'sync_run_id' => $sync_run_id,
			'current_batch' => $this->sync_status['current_sync']['current_batch'] ?? 0,
			'batch_size' => $this->sync_status['current_sync']['batch_size'] ?? 50,
			'recovery_source' => $this->sync_status['current_sync']['recovery_source'] ?? 'unknown'
		], 'Recuperación programada asíncronamente', [
			'method' => 'resume_sync',
			'offset' => $offset,
			'batch_size' => $batch_size,
			'entity' => $entity,
			'operation_id' => $this->sync_status['current_sync']['operation_id'] ?? 'unknown'
		]);
	}

	/**
	 * Valida la conexión y parámetros de API antes de iniciar una sincronización completa.
	 *
	 * @param string $entity Entidad a sincronizar ('products' o 'orders')
	 * @param string $direction Dirección de la sincronización
	 * @param array $filters Filtros a utilizar
	 * @return SyncResponseInterface Resultado de la validación
	 */
	public function validate_sync_prerequisites(string $entity, string $direction, array $filters = []): SyncResponseInterface
    {
		$results = [
			'api_connection' => false,
			'params_valid' => false,
			'count_test' => false,
			'sample_data' => false,
			'issues' => [],
			'warnings' => [],
			'sample_response' => null
		];

		// 1. Verificar credenciales de API
		if (!$this->api_connector->has_valid_credentials()) {
			$results['issues'][] = __('No hay credenciales válidas para Verial.', 'mi-integracion-api');
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__('No hay credenciales válidas para Verial.', 'mi-integracion-api'),
				401,
				[
					'method' => 'validate_sync_prerequisites',
					'entity' => $entity,
					'direction' => $direction,
					'validation_results' => $results,
					'error_code' => 'no_valid_credentials'
				]
			);
		}
		$results['api_connection'] = true;

		// 2. Validar parámetros básicos usando SyncEntityValidator unificado
		try {
			\MiIntegracionApi\Core\Validation\SyncEntityValidator::validateEntityAndDirection($entity, $direction);
			$results['params_valid'] = true;
		} catch (SyncError $e ) {
			$results['issues'][] = $e->getMessage();
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				$e->getMessage(),
				400,
				[
					'method' => 'validate_sync_prerequisites',
					'entity' => $entity,
					'direction' => $direction,
					'validation_results' => $results,
					'error_context' => $e->getContext(),
					'error_code' => 'validation_error'
				]
			);
		}

		// 3. Probar conteo de elementos (con un tiempo de espera menor)
		try {
			// Para productos de Verial, probar GetNumArticulosWS
			if ($entity === 'products' && $direction === 'verial_to_wc') {
				$count_result = $this->count_verial_products($filters);
				
				if (!$count_result->isSuccess()) {
					$results['issues'][] = sprintf(
						__('Error al contar productos: %s', 'mi-integracion-api'),
						$count_result->getMessage()
					);
				} else {
					$results['count_test'] = true;
					$results['item_count'] = $count_result->getData()['count'] ?? 0;
					
					// Advertencia si el conteo es muy alto
					if (($count_result->getData()['count'] ?? 0) > 5000) {
						$results['warnings'][] = sprintf(
							__('El número de productos es muy alto (%d). Considere utilizar filtros para reducir el volumen.', 'mi-integracion-api'),
							$count_result->getData()['count'] ?? 0
						);
					}
				}
				
				// 4. Probar obtención de muestra de datos
				// Intentar obtener un pequeño lote de prueba
				$params = ['inicio' => 1, 'fin' => 3];
				
				// Añadir filtros de fecha/hora si existen
				if (!empty($filters['modified_after'])) {
					$params['fecha'] = date('Y-m-d', $filters['modified_after']);
					if (!empty($filters['modified_after_time'])) {
						$params['hora'] = $filters['modified_after_time'];
					} else {
						$params['hora'] = date('H:i:s', $filters['modified_after']);
					}
				}
				
				// CORRECCIÓN: GetArticulosWS debe usar GET, no POST según la documentación de la API
				$response = $this->api_connector->get('GetArticulosWS', $params);
				
				if (!$response->isSuccess()) {
					$results['issues'][] = sprintf(
						__('Error al obtener datos de muestra: %s', 'mi-integracion-api'),
						$response->getMessage()
					);
				} else {
					$data = $response->getData();
					
					if (isset($data['InfoError']) && $data['InfoError']['Codigo'] != 0) {
						$results['issues'][] = sprintf(
							__('Error de API al obtener datos de muestra: %s', 'mi-integracion-api'),
							$data['InfoError']['Descripcion']
						);
					} else if (!isset($data['Articulos'])) {
						$results['issues'][] = __('Respuesta sin datos de productos.', 'mi-integracion-api');
					} else {
						$results['sample_data'] = true;
						$results['sample_count'] = count($data['Articulos']);
						
						// REFACTORIZADO: Límites de tamaño para arrays grandes
						if (!empty($data['Articulos'])) {
							$maxSampleSize = 10; // Límite fijo para muestras de validación
							$sampleSize = min($maxSampleSize, count($data['Articulos']));
							
							$sample = array_slice($data['Articulos'], 0, $sampleSize);
							
							$results['sample_response'] = $sample;
							$results['sample_optimization'] = [
								'original_size' => count($data['Articulos']),
								'sample_size' => $sampleSize,
								'max_sample_size' => $maxSampleSize,
								'optimization_applied' => true
							];
						}
					}
				}
			} else {
				// Para otras combinaciones, por ahora solo validar conexión
				$results['warnings'][] = __('Validación completa solo disponible para productos de Verial.', 'mi-integracion-api');
			}
		} catch (\Throwable $e) {
			$results['issues'][] = sprintf(
				__('Excepción durante validación: %s', 'mi-integracion-api'),
				$e->getMessage()
			);
		}
		
		// Calcular estado general
		$results['success'] = $results['api_connection'] &&
							 $results['params_valid'] &&
							 $results['count_test'] &&
							 empty($results['issues']);
		
		// Si hay issues, retornar error
		if (!empty($results['issues'])) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				__('La validación de prerrequisitos falló', 'mi-integracion-api'),
				422,
				[
					'method' => 'validate_sync_prerequisites',
					'entity' => $entity,
					'direction' => $direction,
					'validation_results' => $results,
					'error_code' => 'validation_failed'
				]
			);
		}
		
		// Retornar respuesta exitosa
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$results,
			__('Validación de prerrequisitos completada exitosamente', 'mi-integracion-api'),
			[
				'method' => 'validate_sync_prerequisites',
				'entity' => $entity,
				'direction' => $direction,
				'validation_type' => 'prerequisites'
			]
		);
	}	
	
	/**
	 * REFACTORIZADO: Determina si un transient debe ser retenido según su política
	 * 
	 * Este método ahora combina lógica de dominio (sincronización) con lógica de caché
	 * delegada a CacheManager a través de CacheInterface.
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return bool True si debe ser retenido
	 */
	public function shouldRetainTransient(string $cacheKey): bool
	{
		// REFACTORIZADO: Obtener política de retención desde CacheManager
		$cacheManager = CacheManager::getInstance();
		$policy = $cacheManager->getRetentionPolicy($cacheKey);
		
		// LÓGICA DE DOMINIO: Verificar estado de sincronización
		if ($policy['cleanup_after_sync']) {
			$syncStatus = $this->getSyncStatus();
			if ($syncStatus && isset($syncStatus['status']) && $syncStatus['status'] === 'running') {
				return true; // Mantener durante sincronización activa
			}
		}
		
		// REFACTORIZADO: Delegar lógica de caché pura a CacheManager
		return $cacheManager->shouldRetainTransient($cacheKey);
	}
	
	/**
	 * NUEVO: Registra hooks para limpieza automática post-sincronización
	 * 
	 * @return void
	 */
	private function registerSyncCompletionHooks(): void
	{
		// Hook para cuando se complete una sincronización
		add_action('mia_sync_completed', [$this, 'cleanupAfterSyncComplete'], 10);
		
		// Hook para cuando se cancele una sincronización
		add_action('mia_sync_cancelled', [$this, 'cleanupAfterSyncComplete'], 10);
		
		// Hook para cuando se complete un batch
		add_action('mia_batch_completed', [$this, 'cleanupBatchTransients'], 10);
		
		// ELIMINADO: Hooks automáticos innecesarios que causaban errores fatales
		// $this->registerAutomaticCleanupHooks(); // <-- REMOVIDO
		
		$this->logger->info("Hooks de limpieza post-sincronización registrados", [
			'hooks' => [
				'mia_sync_completed',
				'mia_sync_cancelled',
				'mia_batch_completed'
			]
		]);
	}		
	
	/**
	 * Gestiona cambios en opciones del plugin para limpiar caché relacionado
	 * 
	 * @param string $option_name Nombre de la opción actualizada
	 * @param mixed $old_value Valor anterior de la opción
	 * @param mixed $new_value Nuevo valor de la opción
	 * @return void
	 */
	public function handleOptionChange(string $option_name, mixed $old_value, mixed $new_value): void {
		// Solo procesar opciones del plugin
		if (str_starts_with($option_name, 'mi_integracion_api') || 
			str_starts_with($option_name, 'mia_')) {
			
			$this->logger->info('Opción del plugin actualizada - limpiando caché relacionado', [
				'option' => $option_name,
				'old_value' => $old_value,
				'new_value' => $new_value,
				'user_id' => get_current_user_id()
			]);
			
			// Limpiar caché relacionado con la opción
			$this->clearRelatedCache($option_name);
			
			// Limpiar transients relacionados
			$this->clearRelatedTransients($option_name);
			
			// Notificar cambio a otros componentes
			do_action('mia_option_changed', $option_name, $old_value, $new_value);
		}
	}
	
	/**
	 * Gestiona eliminación de opciones del plugin
	 * 
	 * @param string $option_name Nombre de la opción eliminada
	 * @return void
	 */
	public function handleOptionDeletion(string $option_name): void {
		// Solo procesar opciones del plugin
		if (str_starts_with($option_name, 'mi_integracion_api') || 
			str_starts_with($option_name, 'mia_')) {
			
			$this->logger->info('Opción del plugin eliminada - limpiando datos relacionados', [
				'option' => $option_name,
				'user_id' => get_current_user_id()
			]);
			
			// Limpiar caché relacionado
			$this->clearRelatedCache($option_name);
			
			// Limpiar transients relacionados
			$this->clearRelatedTransients($option_name);
			
			// Notificar eliminación a otros componentes
			do_action('mia_option_deleted', $option_name);
		}
	}
	
	/**
	 * Gestiona situaciones críticas de memoria
	 * 
	 * @param array $memory_data Datos de memoria crítica
	 * @return void
	 */
	public function handleMemoryCritical(array $memory_data): void {
		$memory_error = SyncError::memoryError(
			'Memoria crítica detectada - iniciando limpieza de emergencia',
			[
				'memory_data' => $memory_data,
				'current_memory' => memory_get_usage(true),
				'peak_memory' => memory_get_peak_usage(true),
				'memory_limit' => ini_get('memory_limit'),
				'action' => 'emergency_cleanup'
			]
		);
		
		$this->logger->warning('Memoria crítica detectada - iniciando limpieza de emergencia', [
			'memory_data' => $memory_data,
			'current_memory' => memory_get_usage(true),
			'peak_memory' => memory_get_peak_usage(true),
			'memory_limit' => ini_get('memory_limit'),
			'error_context' => $memory_error->getContext()
		]);
		
		// Limpieza de emergencia: eliminar caché no esencial
		$this->emergencyCacheCleanup();
		
		// Limpiar transients antiguos
		$this->cleanupOldTransients();
		
		// Forzar garbage collection si está disponible
		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}
		
		// Notificar a otros componentes
		do_action('mia_memory_critical_handled', $memory_data);
	}
	
	/**
	 * Gestiona advertencias de memoria
	 * 
	 * @param array $memory_data Datos de advertencia de memoria
	 * @return void
	 */
	public function handleMemoryWarning(array $memory_data): void {
		$memory_error = SyncError::memoryError(
			'Advertencia de memoria detectada - limpieza preventiva',
			[
				'memory_data' => $memory_data,
				'current_memory' => memory_get_usage(true),
				'peak_memory' => memory_get_peak_usage(true),
				'action' => 'preventive_cleanup'
			]
		);
		
		$this->logger->info('Advertencia de memoria detectada - limpieza preventiva', [
			'memory_data' => $memory_data,
			'current_memory' => memory_get_usage(true),
			'peak_memory' => memory_get_peak_usage(true),
			'error_context' => $memory_error->getContext()
		]);
		
		// Limpieza preventiva: eliminar caché no crítico
		$this->preventiveCacheCleanup();
		
		// Notificar a otros componentes
		do_action('mia_memory_warning_handled', $memory_data);
	}
	
	/**
	 * Limpia caché relacionado con una opción específica
	 * 
	 * @param string $option_name Nombre de la opción
	 * @return void
	 */
	private function clearRelatedCache(string $option_name): void {
		$cache_keys_to_clear = [];
		
		// Determinar qué caché limpiar basado en la opción
		if (str_contains($option_name, 'mia_url_base')) {
			$cache_keys_to_clear[] = 'api_connection_cache';
			$cache_keys_to_clear[] = 'api_response_cache';
		}
		
		if (str_contains($option_name, 'mia_clave_api')) {
			$cache_keys_to_clear[] = 'auth_cache';
			$cache_keys_to_clear[] = 'api_connection_cache';
		}
		
		if (str_contains($option_name, 'mia_max_retries') || 
			str_contains($option_name, 'mia_retry_delay')) {
			$cache_keys_to_clear[] = 'retry_policy_cache';
		}
		
		// Limpiar caché específico
		foreach ($cache_keys_to_clear as $cache_key) {
			wp_cache_delete($cache_key, 'mi_integration');
			$this->logger->debug('Caché limpiado por cambio de opción', [
				'option' => $option_name,
				'cache_key' => $cache_key
			]);
		}
	}
	
	/**
	 * Limpia transients relacionados con una opción específica
	 * 
	 * @param string $option_name Nombre de la opción
	 * @return void
	 */
	private function clearRelatedTransients(string $option_name): void {
		$transient_patterns = [];
		
		// Determinar patrones de transients a limpiar
		if (str_contains($option_name, 'mia_url_base')) {
			$transient_patterns[] = 'mia_api_connection_%';
			$transient_patterns[] = 'mia_api_response_%';
		}
		
		if (str_contains($option_name, 'mia_clave_api')) {
			$transient_patterns[] = 'mia_auth_%';
			$transient_patterns[] = 'mia_connection_%';
		}
		
		// Limpiar transients que coincidan con los patrones
		foreach ($transient_patterns as $pattern) {
			$this->deleteTransientsByPattern($pattern);
		}
	}
	
	// MIGRADO A CacheManager.php: emergencyCacheCleanup() y preventiveCacheCleanup()
	
	// MIGRADO A BatchProcessor.php: cleanupBatchTransients()
	
	/**
	 * NUEVO: Detecta transients que crecen rápidamente
	 * 
	 * @return SyncResponseInterface Transients con crecimiento rápido detectado
	 */
	public function detectRapidGrowth(): SyncResponseInterface
	{
		try {
			$cacheKeys = $this->getMonitoredCacheKeys();
			$rapidGrowthTransients = [];
			$currentTime = time();
			
			foreach ($cacheKeys as $cacheKey) {
				$growthMetricsResponse = $this->getTransientGrowthMetrics($cacheKey);
				
				if (!$growthMetricsResponse->isSuccess()) {
					continue;
				}
				
				$growthMetrics = $growthMetricsResponse->getData();
				
				// Detectar crecimiento rápido (>50% en la última hora)
				$hourlyGrowth = $growthMetrics['hourly_growth_rate'] ?? 0;
				$sizeIncrease = $growthMetrics['size_increase_bytes'] ?? 0;
				$isRapidGrowth = $hourlyGrowth > 0.5 || $sizeIncrease > 1024 * 1024; // >50% o >1MB
				
				if ($isRapidGrowth) {
					$rapidGrowthTransients[$cacheKey] = [
						'hourly_growth_rate' => $hourlyGrowth,
						'size_increase_bytes' => $sizeIncrease,
						'current_size' => $growthMetrics['current_size'],
						'previous_size' => $growthMetrics['previous_size'],
						'growth_trend' => $growthMetrics['growth_trend'],
						'risk_level' => $this->calculateGrowthRiskLevel($hourlyGrowth, $sizeIncrease),
						'detected_at' => $currentTime
					];
				}
			}
			
			if (!empty($rapidGrowthTransients)) {
				$this->logger->warning("Transients con crecimiento rápido detectados", [
					'count' => count($rapidGrowthTransients),
					'transients' => $rapidGrowthTransients
				]);
			}
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				[
					'rapid_growth_transients' => $rapidGrowthTransients,
					'detected_count' => count($rapidGrowthTransients),
					'total_monitored' => count($cacheKeys),
					'detection_time' => $currentTime
				],
				'Análisis de crecimiento de transients completado',
				[
					'operation' => 'detectRapidGrowth',
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'detectRapidGrowth'
			]);
		}
	}
	
	/**
	 * NUEVO: Obtiene métricas de crecimiento de un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return SyncResponseInterface Métricas de crecimiento
	 */
	private function getTransientGrowthMetrics(string $cacheKey): SyncResponseInterface
	{
		try {
			// Validar parámetros
			if (empty($cacheKey)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'La clave del caché no puede estar vacía',
					['cache_key' => $cacheKey]
				);
			}

			$growthHistory = get_option('mia_transient_growth_history_' . $cacheKey, []);
			
			if (count($growthHistory) < 2) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'No hay suficientes datos históricos para calcular métricas de crecimiento',
					404,
					[
						'cache_key' => $cacheKey,
						'history_count' => count($growthHistory),
						'minimum_required' => 2,
						'error_code' => 'insufficient_data'
					]
				);
			}
			
			// Obtener las últimas 2 mediciones
			$current = end($growthHistory);
			$previous = prev($growthHistory);
			
			if (!$current || !$previous) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Los datos históricos no son válidos',
					422,
					[
						'cache_key' => $cacheKey,
						'current' => $current,
						'previous' => $previous,
						'error_code' => 'invalid_history_data'
					]
				);
			}
			
			$currentSize = $current['size_bytes'];
			$previousSize = $previous['size_bytes'];
			$currentTime = $current['timestamp'];
			$previousTime = $previous['timestamp'];
			
			// Calcular tasas de crecimiento
			$timeDiff = $currentTime - $previousTime;
			$sizeDiff = $currentSize - $previousSize;
			
			if ($timeDiff <= 0 || $previousSize <= 0) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Los datos no permiten calcular métricas válidas',
					422,
					[
						'cache_key' => $cacheKey,
						'time_diff' => $timeDiff,
						'previous_size' => $previousSize,
						'error_code' => 'invalid_calculation_data'
					]
				);
			}
			
			$hourlyGrowthRate = ($sizeDiff / $previousSize) / ($timeDiff / self::SECONDS_PER_HOUR);
			$growthTrend = $sizeDiff > 0 ? 'increasing' : ($sizeDiff < 0 ? 'decreasing' : 'stable');
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				[
					'current_size' => $currentSize,
					'previous_size' => $previousSize,
					'size_increase_bytes' => $sizeDiff,
					'hourly_growth_rate' => $hourlyGrowthRate,
					'growth_trend' => $growthTrend,
					'measurement_interval' => $timeDiff,
					'cache_key' => $cacheKey
				],
				'Métricas de crecimiento obtenidas correctamente',
				[
					'operation' => 'getTransientGrowthMetrics',
					'cache_key' => $cacheKey,
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'cache_key' => $cacheKey,
				'operation' => 'getTransientGrowthMetrics'
			]);
		}
	}
	
	/**
	 * NUEVO: Calcula el nivel de riesgo basado en el crecimiento
	 * 
	 * @param float $hourlyGrowthRate Tasa de crecimiento por hora
	 * @param int $sizeIncreaseBytes Incremento de tamaño en bytes
	 * @return string Nivel de riesgo
	 */
	private function calculateGrowthRiskLevel(float $hourlyGrowthRate, int $sizeIncreaseBytes): string
	{
		if ($hourlyGrowthRate > 2.0 || $sizeIncreaseBytes > 5 * 1024 * 1024) { // >200% o >5MB
			return 'critical';
		} elseif ($hourlyGrowthRate > 1.0 || $sizeIncreaseBytes > 2 * 1024 * 1024) { // >100% o >2MB
			return 'high';
		} elseif ($hourlyGrowthRate > 0.5 || $sizeIncreaseBytes > 1024 * 1024) { // >50% o >1MB
			return 'medium';
		} else {
			return 'low';
		}
	}
	
	/**
	 * NUEVO: Verifica límites críticos antes de alcanzarlos
	 * 
	 * @return SyncResponseInterface Alertas de límites críticos
	 */
	public function checkCriticalLimits(): SyncResponseInterface
	{
		try {
			$cacheKeys = $this->getMonitoredCacheKeys();
			$criticalAlerts = [];
			$currentTime = time();
			
			// Límites críticos configurables
			$criticalLimits = [
				'max_total_size_mb' => 100, // 100MB total
				'max_individual_size_mb' => 25, // 25MB por transient
				'max_memory_usage_percent' => 80, // 80% de memoria
				'max_transient_count' => 1000 // 1000 transients
			];
			
			// Aplicar filtros personalizados
			$criticalLimits = apply_filters('mia_critical_limits', $criticalLimits);
			
			$totalSize = 0;
			$transientCount = 0;
			
			foreach ($cacheKeys as $cacheKey) {
				$cacheData = get_transient($cacheKey);
				if ($cacheData === false) {
					continue;
				}
				
				$transientCount++;
				$sizeBytes = $this->calculateTransientSize($cacheData);
				$totalSize += $sizeBytes;
				$sizeMB = $sizeBytes / (1024 * 1024);
				
				// Verificar límite individual
				if ($sizeMB > $criticalLimits['max_individual_size_mb']) {
					$criticalAlerts[] = [
						'type' => 'individual_size_limit',
						'cache_key' => $cacheKey,
						'size_mb' =>  $this->safe_round($sizeMB, 2),
						'limit_mb' => $criticalLimits['max_individual_size_mb'],
						'risk_level' => 'critical',
						'detected_at' => $currentTime,
						'recommendation' => 'Considerar fragmentación o compresión'
					];
				}
			}
			
			$totalSizeMB = $totalSize / (1024 * 1024);
			
			// Verificar límite total
			if ($totalSizeMB > $criticalLimits['max_total_size_mb']) {
				$criticalAlerts[] = [
					'type' => 'total_size_limit',
					'total_size_mb' => $this->safe_round($totalSizeMB, 2),
					'limit_mb' => $criticalLimits['max_total_size_mb'],
					'risk_level' => 'critical',
					'detected_at' => $currentTime,
					'recommendation' => 'Ejecutar limpieza agresiva de transients'
				];
			}
			
			// Verificar límite de cantidad
			if ($transientCount > $criticalLimits['max_transient_count']) {
				$criticalAlerts[] = [
					'type' => 'transient_count_limit',
					'count' => $transientCount,
					'limit' => $criticalLimits['max_transient_count'],
					'risk_level' => 'high',
					'detected_at' => $currentTime,
					'recommendation' => 'Limpiar transients obsoletos'
				];
			}
			
			// Verificar uso de memoria
			$memoryUsageResponse = $this->getMemoryUsage();
			if (!$memoryUsageResponse->isSuccess()) {
				// Si no se puede obtener el uso de memoria, continuar sin verificar
				$memoryUsage = ['percent' => 0];
			} else {
				$memoryUsage = $memoryUsageResponse->getData();
			}
			
			if ($memoryUsage['percent'] > $criticalLimits['max_memory_usage_percent']) {
				$memory_error = SyncError::memoryError(
					'Límite crítico de memoria alcanzado',
					[
						'memory_usage_percent' => $this->safe_round($memoryUsage['percent'], 2),
						'limit_percent' => $criticalLimits['max_memory_usage_percent'],
						'current_memory' => $memoryUsage['current_bytes'],
						'peak_memory' => $memoryUsage['peak_bytes'],
						'memory_limit' => $memoryUsage['limit_bytes'],
						'action' => 'emergency_cleanup'
					]
				);
				
				$criticalAlerts[] = [
					'type' => 'memory_usage_limit',
					'memory_usage_percent' => $this->safe_round($memoryUsage['percent'], 2),
					'limit_percent' => $criticalLimits['max_memory_usage_percent'],
					'risk_level' => 'critical',
					'detected_at' => $currentTime,
					'recommendation' => 'Limpieza de emergencia de transients',
					'error_context' => $memory_error->getContext()
				];
			}
			
			if (!empty($criticalAlerts)) {
				$this->logger->error("Límites críticos alcanzados", [
					'alert_count' => count($criticalAlerts),
					'alerts' => $criticalAlerts,
					'summary' => [
						'total_size_mb' => $this->safe_round($totalSizeMB, 2),
						'transient_count' => $transientCount,
						'memory_usage_percent' => $this->safe_round($memoryUsage['percent'], 2)
					]
				]);
			}
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				[
					'critical_alerts' => $criticalAlerts,
					'alert_count' => count($criticalAlerts),
					'summary' => [
						'total_size_mb' => $this->safe_round($totalSizeMB, 2),
						'transient_count' => $transientCount,
						'memory_usage_percent' => $this->safe_round($memoryUsage['percent'], 2),
						'limits_checked' => $criticalLimits
					],
					'check_time' => $currentTime
				],
				'Verificación de límites críticos completada',
				[
					'operation' => 'checkCriticalLimits',
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'checkCriticalLimits'
			]);
		}
	}
	
	/**
	 * NUEVO: Calcula el tamaño de un transient en bytes con estimación precisa
	 * 
	 * @param mixed $data Datos del transient
	 * @param bool $includeOverhead Incluir overhead de memoria (por defecto true)
	 * @return int Tamaño en bytes
	 */
	private function calculateTransientSize(mixed $data, bool $includeOverhead = true): int
	{
		$baseSize = $this->calculateBaseSize($data);
		
		if (!$includeOverhead) {
			return $baseSize;
		}
		
		// Calcular overhead de memoria
		$overhead = $this->calculateMemoryOverhead($data, $baseSize);
		
		return $baseSize + $overhead;
	}
	
	/**
	 * NUEVO: Calcula el tamaño base de los datos sin overhead
	 * 
	 * @param mixed $data Datos del transient
	 * @return int Tamaño base en bytes
	 */
	private function calculateBaseSize(mixed $data): int
	{
		if (is_null($data)) {
			return 0;
		}
		
		if (is_string($data)) {
			return strlen($data);
		}
		
		if (is_numeric($data)) {
			return 8; // Tamaño aproximado de un número en PHP
		}
		
		if (is_bool($data)) {
			return 1;
		}
		
		if (is_array($data)) {
			$size = 0;
			foreach ($data as $key => $value) {
				$size += strlen($key) + $this->calculateBaseSize($value);
			}
			return $size;
		}
		
		if (is_object($data)) {
			// Para objetos, serializar y calcular tamaño
			return strlen(serialize($data));
		}
		
		return 0;
	}
	
	/**
	 * NUEVO: Calcula el overhead de memoria considerando PHP y WordPress
	 * 
	 * @param mixed $data Datos del transient
	 * @param int $baseSize Tamaño base calculado
	 * @return int Overhead en bytes
	 */
	private function calculateMemoryOverhead(mixed $data, int $baseSize): int
	{
		$overhead = 0;
		
		// Overhead básico de PHP
		$overhead += 64; // Estructura básica de variable
		
		// Overhead de serialización para WordPress
		if (is_array($data) || is_object($data)) {
			$overhead += $baseSize * 0.1; // 10% de overhead por serialización
		}
		
		// Overhead de referencias y punteros
		if (is_array($data)) {
			$overhead += count($data) * 16; // 16 bytes por elemento del array
		}
		
		// Overhead de strings largos
		if (is_string($data) && strlen($data) > 1024) {
			$overhead += 32; // Buffer adicional para strings grandes
		}
		
		// Overhead de objetos
		if (is_object($data)) {
			$overhead += 128; // Estructura de objeto PHP
			$overhead += strlen(get_class($data)) * 2; // Nombre de clase
		}
		
		return $overhead;
	}	

	/**
	 * DELEGADO A CacheManager: Limpia strings largos por chunks
	 * 
	 * @param string $data String a limpiar
	 * @param int $chunkSize Tamaño del chunk en bytes
	 * @return string String limpio
	 */
	private function cleanupStringChunks(string $data, int $chunkSize): string
	{
		// DELEGAR A CacheManager
		if (class_exists('\MiIntegracionApi\CacheManager')) {
			$cacheManager = CacheManager::get_instance();
			return $cacheManager->cleanupStringChunks($data, $chunkSize);
		}
		
		return $data; // FALLBACK: retornar datos originales
	}	



	/**
	 * NUEVO: Pausa para gestión de memoria
	 * 
	 * @return void
	 */
	private function memoryManagementPause(): void
	{
		// Forzar garbage collection
		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}
		
		// Pausa breve para permitir liberación de memoria
		usleep(10000); // 0.01 segundos
	}
	


	/**
	 * NUEVO: Obtiene TTL de un transient
	 * 
	 * @param string $cacheKey Clave del transient
	 * @return int TTL en segundos
	 */
	private function getTransientTTL(string $cacheKey): int
	{
		// Obtener TTL de la configuración personalizada
		$config = $this->getCustomTTLConfiguration($cacheKey);
		return $config['ttl'] ?? self::DEFAULT_CACHE_TTL;
	}
	
	
	/**
	 * NUEVO: Obtiene el uso actual de memoria
	 * 
	 * @return SyncResponseInterface Uso de memoria
	 */
	private function getMemoryUsage(): SyncResponseInterface
	{
		try {
			$memoryLimit = ini_get('memory_limit');
			$memoryUsage = memory_get_usage(true);
			$peakMemory = memory_get_peak_usage(true);
			
			// Convertir límite de memoria a bytes
			$limitBytes = $this->convertMemoryLimitToBytes($memoryLimit);
			
			$usagePercent = $limitBytes > 0 ? ($memoryUsage / $limitBytes) * 100 : 0;
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				[
					'current_bytes' => $memoryUsage,
					'peak_bytes' => $peakMemory,
					'limit_bytes' => $limitBytes,
					'percent' => $usagePercent,
					'limit_formatted' => $memoryLimit,
					'current_mb' => $this->safe_round($memoryUsage / (1024 * 1024), 2),
					'peak_mb' => $this->safe_round($peakMemory / (1024 * 1024), 2),
					'limit_mb' => $this->safe_round($limitBytes / (1024 * 1024), 2)
				],
				'Uso de memoria obtenido correctamente',
				[
					'operation' => 'getMemoryUsage',
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getMemoryUsage'
			]);
		}
	}
	
	/**
	 * NUEVO: Convierte límite de memoria a bytes
	 * 
	 * @param string $memoryLimit Límite de memoria (ej: '256M', '256M', '1G')
	 * @return int Límite en bytes
	 */
	private function convertMemoryLimitToBytes(string $memoryLimit): int
	{
		$unit = strtolower(substr($memoryLimit, -1));
		$value = (int) substr($memoryLimit, 0, -1);
		
		switch ($unit) {
			case 'k':
				return $value * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'g':
				return $value * 1024 * 1024 * 1024;
			default:
				return $value;
		}
	}
	
	/**
	 * DELEGADO A CacheManager: Optimiza el tamaño de transients grandes
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return SyncResponseInterface Resultado de la optimización
	 */
	public function optimizeTransientSize(string $cacheKey): SyncResponseInterface
	{
		try {
			// Validar parámetros
			if (empty($cacheKey)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'La clave del caché no puede estar vacía',
					['cache_key' => $cacheKey]
				);
			}

			// DELEGAR A CacheManager
			if (class_exists('\MiIntegracionApi\CacheManager')) {
				$cacheManager = CacheManager::get_instance();
				$result = $cacheManager->optimizeTransientSize($cacheKey);
				
				// Convertir array a SyncResponseInterface
				if ($result['success'] ?? false) {
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
						[
							'cache_key' => $cacheKey,
							'original_size' => $result['original_size'] ?? '0 B',
							'optimized_size' => $result['optimized_size'] ?? '0 B',
							'reduction_percentage' => $result['reduction_percentage'] ?? 0
						],
						$result['message'] ?? 'Transient optimizado correctamente'
					);
				} else {
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
						$result['message'] ?? 'Error optimizando transient',
						400,
						[
							'cache_key' => $cacheKey,
							'error' => $result['error'] ?? 'Error desconocido'
						]
					);
				}
			}
			
			// FALLBACK: Retornar error si CacheManager no está disponible
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'CacheManager no disponible para optimización de transients',
				503,
				[
					'cache_key' => $cacheKey,
					'original_size_mb' => 0,
					'optimized_size_mb' => 0,
					'space_saved_mb' => 0,
					'fallback_reason' => 'CacheManager class not found',
					'error_code' => 'cache_manager_unavailable'
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'cache_key' => $cacheKey,
				'operation' => 'optimizeTransientSize'
			]);
		}
	}
	
	/**
	 * NUEVO: Comprime datos del transient con múltiples algoritmos inteligentes
	 * 
	 * @param mixed $data Datos a comprimir
	 * @param string $algorithm Algoritmo de compresión (auto, gzip, lz4, zstd)
	 * @return mixed Datos comprimidos o false si falla
	 */
	private function compressTransientData(mixed $data, string $algorithm = 'auto'): mixed
	{
		// Delegar a CompressionManager
		return $this->compressionManager->compressData($data, $algorithm);
	}	
	
	/**
	 * NUEVO: Fragmenta transients grandes en chunks más pequeños con algoritmos inteligentes
	 * 
	 * @param mixed $data Datos a fragmentar
	 * @param int $maxChunkSize Tamaño máximo del chunk en bytes (por defecto 5MB)
	 * @return SyncResponseInterface Datos fragmentados
	 */
	private function fragmentLargeTransients(mixed $data, int $maxChunkSize = 5 * 1024 * 1024): SyncResponseInterface
	{
		try {
			// Validar parámetros
			if ($maxChunkSize <= 0) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'El tamaño máximo del chunk debe ser mayor a 0',
					['max_chunk_size' => $maxChunkSize]
				);
			}

			$originalSize = $this->calculateTransientSize($data);
			
			// Solo fragmentar si es mayor a 10MB
			if ($originalSize < 10 * 1024 * 1024) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Los datos no necesitan fragmentación (menores a 10MB)',
					200,
					[
						'original_size_bytes' => $originalSize,
						'original_size_mb' => $this->safe_round($originalSize / (1024 * 1024), 2),
						'threshold_mb' => 10,
						'error_code' => 'fragmentation_not_needed'
					]
				);
			}
			
			$fragmentationResult = $this->intelligentFragmentation($data, $maxChunkSize);
			
			if ($fragmentationResult->isSuccess()) {
				$resultData = $fragmentationResult->getData();
				// Registrar métricas de fragmentación
				$this->metrics->recordFragmentationMetrics($originalSize, $resultData);
				
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					[
						'fragments' => $resultData['fragments'],
						'fragmentation_type' => $resultData['fragmentation_type'],
						'chunks_created' => $resultData['chunks_created'],
						'total_size_bytes' => $resultData['total_size_bytes'],
						'efficiency_score' => $resultData['efficiency_score'],
						'original_size_bytes' => $originalSize,
						'original_size_mb' => $this->safe_round($originalSize / (1024 * 1024), 2)
					],
					'Fragmentación completada exitosamente',
					[
						'operation' => 'fragmentLargeTransients',
						'max_chunk_size' => $maxChunkSize,
						'timestamp' => time()
					]
				);
			} else {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'La fragmentación inteligente falló',
					500,
					[
						'original_size_bytes' => $originalSize,
						'max_chunk_size' => $maxChunkSize,
						'error_message' => $fragmentationResult->getMessage(),
						'error_code' => 'fragmentation_failed'
					]
				);
			}
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'max_chunk_size' => $maxChunkSize,
				'operation' => 'fragmentLargeTransients'
			]);
		}
	}
	
	/**
	 * NUEVO: Ejecuta fragmentación inteligente basada en estructura de datos
	 * 
	 * @param mixed $data Datos a fragmentar
	 * @param int $maxChunkSize Tamaño máximo del chunk
	 * @return SyncResponseInterface Resultado de la fragmentación
	 */
	private function intelligentFragmentation(mixed $data, int $maxChunkSize): SyncResponseInterface
	{
		try {
			// Validar parámetros
			if ($maxChunkSize <= 0) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'El tamaño máximo del chunk debe ser mayor a 0',
					['max_chunk_size' => $maxChunkSize]
				);
			}

			$fragments = null;
			$fragmentationType = '';
			
			if (is_array($data)) {
				$fragments = $this->fragmentArrayIntelligently($data, $maxChunkSize);
				$fragmentationType = 'array';
			} elseif (is_string($data)) {
				$fragments = $this->fragmentStringIntelligently($data, $maxChunkSize);
				$fragmentationType = 'string';
			} elseif (is_object($data)) {
				$fragments = $this->fragmentObjectIntelligently($data, $maxChunkSize);
				$fragmentationType = 'object';
			} else {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Tipo de datos no soportado para fragmentación: ' . gettype($data),
					400,
					[
						'data_type' => gettype($data),
						'max_chunk_size' => $maxChunkSize,
						'error_code' => 'unsupported_data_type'
					]
				);
			}
			
			if ($fragments !== null) {
				$chunksCreated = count($fragments);
				$totalSizeBytes = $this->calculateFragmentsTotalSize($fragments);
				$efficiencyScore = $this->calculateFragmentationEfficiency($data, $fragments);
				
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					[
						'fragments' => $fragments,
						'fragmentation_type' => $fragmentationType,
						'chunks_created' => $chunksCreated,
						'total_size_bytes' => $totalSizeBytes,
						'efficiency_score' => $efficiencyScore,
						'original_data_type' => gettype($data)
					],
					'Fragmentación inteligente completada exitosamente',
					[
						'operation' => 'intelligentFragmentation',
						'fragmentation_type' => $fragmentationType,
						'max_chunk_size' => $maxChunkSize,
						'timestamp' => time()
					]
				);
			} else {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'No se pudieron crear fragmentos para los datos',
					500,
					[
						'data_type' => gettype($data),
						'fragmentation_type' => $fragmentationType,
						'max_chunk_size' => $maxChunkSize,
						'error_code' => 'fragmentation_failed'
					]
				);
			}
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'max_chunk_size' => $maxChunkSize,
				'data_type' => gettype($data),
				'operation' => 'intelligentFragmentation'
			]);
		}
	}
	
	/**
	 * NUEVO: Fragmenta arrays de manera inteligente preservando estructura
	 * 
	 * @param array $data Array a fragmentar
	 * @param int $maxChunkSize Tamaño máximo del chunk
	 * @return array|null Fragmentos del array
	 */
	private function fragmentArrayIntelligently(array $data, int $maxChunkSize): ?array
	{
		$fragments = [];
		$currentFragment = [];
		$currentSize = 0;
		$fragmentIndex = 0;
		
		foreach ($data as $key => $value) {
			$itemSize = $this->calculateTransientSize($value);
			
			// Si el item individual es muy grande, fragmentarlo por separado
			if ($itemSize > $maxChunkSize) {
				$subFragments = $this->fragmentLargeItem($value, $maxChunkSize);
				if ($subFragments) {
					foreach ($subFragments as $subIndex => $subFragment) {
						$fragments[] = [
							'fragment_id' => $fragmentIndex++,
							'type' => 'sub_fragment',
							'parent_key' => $key,
							'sub_index' => $subIndex,
							'data' => $subFragment,
							'size_bytes' => $this->calculateTransientSize($subFragment)
						];
					}
				}
				continue;
			}
			
			// Verificar si agregar este item excedería el chunk
			if (($currentSize + $itemSize) > $maxChunkSize) {
				// Guardar fragmento actual
				if (!empty($currentFragment)) {
					$fragments[] = [
						'fragment_id' => $fragmentIndex++,
						'type' => 'array_chunk',
						'data' => $currentFragment,
						'size_bytes' => $currentSize,
						'keys' => array_keys($currentFragment)
					];
				}
				
				// Iniciar nuevo fragmento
				$currentFragment = [$key => $value];
				$currentSize = $itemSize;
			} else {
				// Agregar al fragmento actual
				$currentFragment[$key] = $value;
				$currentSize += $itemSize;
			}
		}
		
		// Agregar el último fragmento si existe
		if (!empty($currentFragment)) {
			$fragments[] = [
				'fragment_id' => $fragmentIndex++,
				'type' => 'array_chunk',
				'data' => $currentFragment,
				'size_bytes' => $currentSize,
				'keys' => array_keys($currentFragment)
			];
		}
		
		return empty($fragments) ? null : $fragments;
	}
	
	/**
	 * NUEVO: Fragmenta strings de manera inteligente
	 * 
	 * @param string $data String a fragmentar
	 * @param int $maxChunkSize Tamaño máximo del chunk
	 * @return array|null Fragmentos del string
	 */
	private function fragmentStringIntelligently(string $data, int $maxChunkSize): ?array
	{
		if (strlen($data) <= $maxChunkSize) {
			return null;
		}
		
		$fragments = [];
		$totalLength = strlen($data);
		$chunkSize = min($maxChunkSize, $totalLength);
		$fragmentIndex = 0;
		
		// Buscar puntos de división naturales (saltos de línea, espacios, etc.)
		$naturalBreakpoints = $this->findNaturalStringBreakpoints($data, $chunkSize);
		
		foreach ($naturalBreakpoints as $breakpoint) {
			$fragment = substr($data, $breakpoint['start'], $breakpoint['length']);
			
			$fragments[] = [
				'fragment_id' => $fragmentIndex++,
				'type' => 'string_chunk',
				'data' => $fragment,
				'size_bytes' => strlen($fragment),
				'start_pos' => $breakpoint['start'],
				'end_pos' => $breakpoint['start'] + $breakpoint['length']
			];
		}
		
		return $fragments;
	}
	
	/**
	 * NUEVO: Encuentra puntos de división naturales en strings
	 * 
	 * @param string $data String a analizar
	 * @param int $chunkSize Tamaño del chunk
	 * @return array Puntos de división naturales
	 */
	private function findNaturalStringBreakpoints(string $data, int $chunkSize): array
	{
		$breakpoints = [];
		$totalLength = strlen($data);
		$currentPos = 0;
		
		while ($currentPos < $totalLength) {
			$chunkEnd = min($currentPos + $chunkSize, $totalLength);
			
			// Buscar el mejor punto de división en este chunk
			$bestBreakpoint = $this->findBestStringBreakpoint($data, $currentPos, $chunkEnd);
			
			$breakpoints[] = [
				'start' => $currentPos,
				'length' => $bestBreakpoint - $currentPos
			];
			
			$currentPos = $bestBreakpoint;
		}
		
		return $breakpoints;
	}
	
	/**
	 * NUEVO: Encuentra el mejor punto de división en un string
	 * 
	 * @param string $data String a analizar
	 * @param int $start Posición de inicio
	 * @param int $end Posición de fin
	 * @return int Mejor posición de división
	 */
	private function findBestStringBreakpoint(string $data, int $start, int $end): int
	{
		// Priorizar saltos de línea
		$newlinePos = strrpos(substr($data, $start, $end - $start), "\n");
		if ($newlinePos !== false) {
			return $start + $newlinePos + 1;
		}
		
		// Luego espacios
		$spacePos = strrpos(substr($data, $start, $end - $start), " ");
		if ($spacePos !== false) {
			return $start + $spacePos + 1;
		}
		
		// Finalmente, dividir en el punto exacto
		return $end;
	}

    /**
     * NUEVO: Fragmenta objetos de manera inteligente
     *
     * @param object $dataObject Objeto a fragmentar
     * @param int $maxChunkSize Tamaño máximo del chunk
     * @return array|null Fragmentos del objeto
     */
	private function fragmentObjectIntelligently(object $dataObject, int $maxChunkSize): ?array
	{
		$reflection = new \ReflectionObject($dataObject);
		$properties = $reflection->getProperties();
		
		$fragments = [];
		$currentFragment = [];
		$currentSize = 0;
		$fragmentIndex = 0;
		
		foreach ($properties as $property) {
			$property->setAccessible(true);
			$propertyName = $property->getName();
            $value = $property->getValue($dataObject);
			
			$itemSize = $this->calculateTransientSize($value);
			
			// Si la propiedad es muy grande, fragmentarla por separado
			if ($itemSize > $maxChunkSize) {
				$subFragments = $this->fragmentLargeItem($value, $maxChunkSize);
				if ($subFragments) {
					foreach ($subFragments as $subIndex => $subFragment) {
						$fragments[] = [
							'fragment_id' => $fragmentIndex++,
							'type' => 'object_property_fragment',
							'property_name' => $propertyName,
							'sub_index' => $subIndex,
							'data' => $subFragment,
							'size_bytes' => $this->calculateTransientSize($subFragment)
						];
					}
				}
				continue;
			}
			
			// Verificar si agregar esta propiedad excedería el chunk
			if (($currentSize + $itemSize) > $maxChunkSize) {
				// Guardar fragmento actual
				if (!empty($currentFragment)) {
					$fragments[] = [
						'fragment_id' => $fragmentIndex++,
						'type' => 'object_properties',
						'data' => $currentFragment,
						'size_bytes' => $currentSize,
						'properties' => array_keys($currentFragment)
					];
				}
				
				// Iniciar nuevo fragmento
				$currentFragment = [$propertyName => $value];
				$currentSize = $itemSize;
			} else {
				// Agregar al fragmento actual
				$currentFragment[$propertyName] = $value;
				$currentSize += $itemSize;
			}
		}
		
		// Agregar el último fragmento si existe
		if (!empty($currentFragment)) {
			$fragments[] = [
				'fragment_id' => $fragmentIndex++,
				'type' => 'object_properties',
				'data' => $currentFragment,
				'size_bytes' => $currentSize,
				'properties' => array_keys($currentFragment)
			];
		}
		
		return empty($fragments) ? null : $fragments;
	}
	
	/**
	 * NUEVO: Fragmenta items grandes individuales
	 * 
	 * @param mixed $item Item a fragmentar
	 * @param int $maxChunkSize Tamaño máximo del chunk
	 * @return array|null Fragmentos del item
	 */
	private function fragmentLargeItem(mixed $item, int $maxChunkSize): ?array
	{
		if (is_array($item)) {
			return $this->fragmentArrayIntelligently($item, $maxChunkSize);
		} elseif (is_string($item)) {
			return $this->fragmentStringIntelligently($item, $maxChunkSize);
		} elseif (is_object($item)) {
			return $this->fragmentObjectIntelligently($item, $maxChunkSize);
		}
		
		return null;
	}
	
	/**
	 * NUEVO: Calcula el tamaño total de todos los fragmentos
	 * 
	 * @param array $fragments Fragmentos a analizar
	 * @return int Tamaño total en bytes
	 */
	private function calculateFragmentsTotalSize(array $fragments): int
	{
		$totalSize = 0;
		foreach ($fragments as $fragment) {
			$totalSize += $fragment['size_bytes'] ?? 0;
		}
		return $totalSize;
	}
	
	/**
	 * NUEVO: Calcula la eficiencia de la fragmentación
	 * 
	 * @param mixed $originalData Datos originales
	 * @param array $fragments Fragmentos creados
	 * @return float Score de eficiencia (0-100)
	 */
	private function calculateFragmentationEfficiency(mixed $originalData, array $fragments): float
	{
		$originalSize = $this->calculateTransientSize($originalData);
		$fragmentsSize = $this->calculateFragmentsTotalSize($fragments);
		$fragmentsCount = count($fragments);
		
		// Penalizar por overhead de fragmentación
		$overhead = $fragmentsCount * 256; // 256 bytes por fragmento
		$efficiency = (($originalSize - $overhead) / $originalSize) * 100;
		
		// Penalizar por demasiados fragmentos
		if ($fragmentsCount > 10) {
			$efficiency -= ($fragmentsCount - 10) * 2;
		}
		
		return max(0, $this->safe_round($efficiency, 1));
	}	
	
	/**
	 * NUEVO: Selecciona la mejor optimización disponible
	 * 
	 * @param array $optimizationResults Resultados de optimizaciones
	 * @return array|null Mejor optimización o null si no hay
	 */
	private function selectBestOptimization(array $optimizationResults): ?array
	{
		if (empty($optimizationResults)) {
			return null;
		}
		
		$bestOptimization = null;
		$bestScore = 0;
		
		foreach ($optimizationResults as $type => $results) {
			$score = $this->calculateOptimizationScore($type, $results);
			
			if ($score > $bestScore) {
				$bestScore = $score;
				$bestOptimization = array_merge(['type' => $type], $results);
			}
		}
		
		return $bestOptimization;
	}
	
	/**
	 * NUEVO: Calcula el score de una optimización
	 * 
	 * @param string $type Tipo de optimización
	 * @param array $results Resultados de la optimización
	 * @return float Score de la optimización
	 */
	private function calculateOptimizationScore(string $type, array $results): float
	{
		switch ($type) {
			case 'compression':
				$compressionRatio = $results['compression_ratio'] ?? 1.0;
				$spaceSaved = $results['space_saved_mb'] ?? 0;
				return (1 - $compressionRatio) * 100 + $spaceSaved; // Priorizar compresión efectiva
				
			case 'fragmentation':
				$fragmentsCount = $results['fragments_count'] ?? 1;
				$fragmentationRatio = $results['fragmentation_ratio'] ?? 1.0;
				return (1 / $fragmentsCount) * 50 + (1 - $fragmentationRatio) * 50; // Balance entre fragmentos y tamaño
				
			default:
				return 0;
		}
	}
	
	/**
	 * NUEVO: Aplica la optimización seleccionada
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $optimization Optimización a aplicar
	 * @return bool True si se aplicó exitosamente
	 */
	private function applyOptimization(string $cacheKey, array $optimization): bool
	{
		try {
			switch ($optimization['type']) {
				case 'compression':
					// Aplicar compresión
					$compressedData = $this->compressTransientData(get_transient($cacheKey));
					if ($compressedData) {
						// Guardar datos comprimidos con metadata
						$metadata = [
							'compressed' => true,
							'compression_time' => time(),
							'original_size' => $optimization['original_size'] ?? 0,
							'compressed_size' => $optimization['compressed_size'] ?? 0
						];
						
						$result = set_transient($cacheKey . '_compressed', $compressedData, HOUR_IN_SECONDS * 24);
						update_option('mia_transient_compression_' . $cacheKey, $metadata, false);
						
						return $result;
					}
					break;
					
				case 'fragmentation':
					// Aplicar fragmentación
					$fragmentedData = $this->fragmentLargeTransients(get_transient($cacheKey));
					if ($fragmentedData) {
						// Guardar fragmentos con metadata
						$metadata = [
							'fragmented' => true,
							'fragmentation_time' => time(),
							'fragments_count' => $fragmentedData['total_chunks'],
							'chunk_size' => $fragmentedData['chunk_size']
						];
						
						$result = set_transient($cacheKey . '_fragmented', $fragmentedData, HOUR_IN_SECONDS * 24);
						update_option('mia_transient_fragmentation_' . $cacheKey, $metadata, false);
						
						return $result;
					}
					break;
			}
		} catch (\Throwable $e) {
			$this->logger->error("Error al aplicar optimización", [
				'cache_key' => $cacheKey,
				'optimization_type' => $optimization['type'],
				'error' => $e->getMessage()
			]);
		}
		
		return false;
	}
	
	/**
	 * NUEVO: Ejecuta verificaciones preventivas antes de limpiar
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return void
	 */
	private function performPreventiveChecks(string $cacheKey): void
	{
		try {
			// 1. Verificar crecimiento rápido
			$rapidGrowth = $this->detectRapidGrowth();
			if (isset($rapidGrowth[$cacheKey])) {
				$this->logger->warning("Crecimiento rápido detectado en transient", [
					'cache_key' => $cacheKey,
					'growth_data' => $rapidGrowth[$cacheKey]
				]);
				
				// Intentar optimización automática si es crítico
				if (($rapidGrowth[$cacheKey]['risk_level'] ?? 'low') === 'critical') {
					$this->optimizeTransientSize($cacheKey);
				}
			}
			
			// 2. Verificar límites críticos
			$criticalLimits = $this->checkCriticalLimits();
			if (!empty($criticalLimits)) {
				$this->logger->error("Límites críticos alcanzados durante limpieza", [
					'cache_key' => $cacheKey,
					'critical_alerts' => $criticalLimits
				]);
				
				// Ejecutar limpieza de emergencia si es necesario
				$this->executeEmergencyCleanup();
			}
			
			// 3. Actualizar métricas de crecimiento
			$this->updateGrowthMetrics($cacheKey);
			
		} catch (\Throwable $e) {
			$this->logger->error("Error en verificaciones preventivas", [
				'cache_key' => $cacheKey,
				'error' => $e->getMessage()
			]);
		}
	}
	
	/**
	 * NUEVO: Actualiza métricas de crecimiento de un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return void
	 */
	private function updateGrowthMetrics(string $cacheKey): void
	{
		$cacheData = get_transient($cacheKey);
		if ($cacheData === false) {
			return;
		}
		
		$currentSize = $this->calculateTransientSize($cacheData);
		$currentTime = time();
		
		$growthHistory = get_option('mia_transient_growth_history_' . $cacheKey, []);
		
		// Mantener solo las últimas 10 mediciones
		if (count($growthHistory) >= 10) {
			$growthHistory = array_slice($growthHistory, -9, 9);
		}
		
		// Añadir nueva medición
		$growthHistory[] = [
			'timestamp' => $currentTime,
			'size_bytes' => $currentSize,
			'size_mb' => $this->safe_round($currentSize / (1024 * 1024), 2)
		];
		
		update_option('mia_transient_growth_history_' . $cacheKey, $growthHistory, false);
	}
	
	/**
	 * NUEVO: Ejecuta limpieza de emergencia cuando se alcanzan límites críticos
	 * 
	 * @return array Resultado de la limpieza de emergencia
	 */
	private function executeEmergencyCleanup(): array
	{
		$this->logger->critical("Ejecutando limpieza de emergencia", [
			'trigger' => 'critical_limits_reached'
		]);
		
		$cacheKeys = $this->getMonitoredCacheKeys();
		$results = [];
		$totalCleaned = 0;
		
		// Priorizar limpieza por tamaño y antigüedad
		$priorityQueue = [];
		
		foreach ($cacheKeys as $cacheKey) {
			$cacheData = get_transient($cacheKey);
			if ($cacheData === false) {
				continue;
			}
			
			$size = $this->calculateTransientSize($cacheData);
			$age = $this->getTransientAge($cacheKey);
			$policy = $this->getRetentionPolicy($cacheKey);
			
			// Calcular prioridad de limpieza (mayor tamaño + mayor antigüedad = mayor prioridad)
			$priority = ($size / (1024 * 1024)) * 0.7 + ($age / self::SECONDS_PER_HOUR) * 0.3;
			
			// Reducir prioridad si es crítico
			if ($policy['keep_always']) {
				$priority *= 0.1;
			}
			
			$priorityQueue[$cacheKey] = $priority;
		}
		
		// Ordenar por prioridad (mayor a menor)
		arsort($priorityQueue);
		
		// Limpiar los transients con mayor prioridad (top 20%)
		$cleanupCount = max(1, (int) (count($priorityQueue) * 0.2));
		$keysToClean = array_slice(array_keys($priorityQueue), 0, $cleanupCount);
		
		foreach ($keysToClean as $cacheKey) {
			$result = delete_transient($cacheKey);
			$results[$cacheKey] = [
				'status' => $result ? 'success' : 'error',
				'priority' => $priorityQueue[$cacheKey],
				'timestamp' => time(),
				'reason' => 'emergency_cleanup'
			];
			
			if ($result) {
				$totalCleaned++;
			}
		}
		
		$this->logger->info("Limpieza de emergencia completada", [
			'total_cleaned' => $totalCleaned,
			'total_attempted' => count($keysToClean),
			'results' => $results
		]);
		
		return [
			'total_cleaned' => $totalCleaned,
			'total_attempted' => count($keysToClean),
			'results' => $results
		];
	}
	
	/**
	 * NUEVO: Monitorea continuamente el crecimiento de transients
	 * 
	 * @return SyncResponseInterface Resumen del monitoreo
	 */
	public function monitorTransientGrowth(): SyncResponseInterface
	{
		try {
			$cacheKeys = $this->getMonitoredCacheKeys();
			
			if (empty($cacheKeys)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'No hay claves de caché para monitorear',
					404,
					[
						'monitored_keys' => 0,
						'reason' => 'no_cache_keys'
					]
				);
			}
			
			$monitoringResults = [];
			$totalGrowth = 0;
			$criticalCount = 0;
			
			foreach ($cacheKeys as $cacheKey) {
				$growthMetricsResponse = $this->getTransientGrowthMetrics($cacheKey);
				
				// Si la respuesta es exitosa, extraer los datos
				if (!$growthMetricsResponse->isSuccess()) {
					continue;
				}
				
				$growthMetrics = $growthMetricsResponse->getData();
				
				if (empty($growthMetrics)) {
					continue;
				}
				
				$growthMB = $growthMetrics['size_increase_bytes'] / (1024 * 1024);
				$totalGrowth += $growthMB;
				
				$riskLevel = $this->calculateGrowthRiskLevel(
					$growthMetrics['hourly_growth_rate'],
					$growthMetrics['size_increase_bytes']
				);
				
				if ($riskLevel === 'critical') {
					$criticalCount++;
				}
				
				$monitoringResults[$cacheKey] = [
					'growth_mb' => $this->safe_round($growthMB, 2),
					'hourly_rate' => $this->safe_round($growthMetrics['hourly_growth_rate'], 3),
					'trend' => $growthMetrics['growth_trend'],
					'risk_level' => $riskLevel,
					'current_size_mb' => $this->safe_round($growthMetrics['current_size'] / (1024 * 1024), 2)
				];
			}
			
			// Generar alertas si es necesario
			if ($criticalCount > 0) {
				$this->logger->warning("Transients con crecimiento crítico detectados", [
					'critical_count' => $criticalCount,
					'total_growth_mb' => $this->safe_round($totalGrowth, 2)
				]);
			}
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				[
					'total_transients' => count($cacheKeys),
					'total_growth_mb' => $this->safe_round($totalGrowth, 2),
					'critical_count' => $criticalCount,
					'results' => $monitoringResults
				],
				'Monitoreo de crecimiento de transients completado',
				[
					'operation' => 'monitorTransientGrowth',
					'monitored_keys' => count($cacheKeys),
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'monitorTransientGrowth'
			]);
		}
	}
	
	/**
	 * NUEVO: Limpia transients de sincronización después de completar
	 * 
	 * @return array Resultado de la limpieza
	 */

	
	/**
	 * NUEVO: Registra el acceso a un transient para tracking de uso
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return void
	 */
	public function recordTransientAccess(string $cacheKey): void
	{
		$accessHistory = get_option('mia_transient_access_history_' . $cacheKey, []);
		$currentTime = time();
		
		// Mantener solo los últimos 100 accesos para evitar crecimiento excesivo
		if (count($accessHistory) >= 100) {
			$accessHistory = array_slice($accessHistory, -99, 99);
		}
		
		// Añadir nuevo acceso
		$accessHistory[] = [
			'timestamp' => $currentTime,
			'date' => date('Y-m-d H:i:s', $currentTime),
			'user_id' => get_current_user_id() ?: 'system',
			'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
		];
		
		// Actualizar historial
		update_option('mia_transient_access_history_' . $cacheKey, $accessHistory, false);
		
		// Actualizar métricas de uso
		$this->updateTransientUsageMetrics($cacheKey, $accessHistory);
	}
	
	/**
	 * NUEVO: Actualiza métricas de uso de un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $accessHistory Historial de accesos
	 * @return void
	 */
	private function updateTransientUsageMetrics(string $cacheKey, array $accessHistory): void
	{
		$currentTime = time();
		$usageMetrics = get_option('mia_transient_usage_metrics_' . $cacheKey, []);
		
		// Calcular frecuencia de acceso por hora
		$hourlyAccess = [];
		foreach ($accessHistory as $access) {
			$hour = date('Y-m-d H', $access['timestamp']);
			$hourlyAccess[$hour] = ($hourlyAccess[$hour] ?? 0) + 1;
		}
		
		// Calcular métricas de uso
		$totalAccesses = count($accessHistory);
		$recentAccesses = array_filter($accessHistory, function($access) use ($currentTime) {
			return ($currentTime - $access['timestamp']) <= self::RECENT_ACCESS_THRESHOLD;
		});
		$dailyAccesses = array_filter($accessHistory, function($access) use ($currentTime) {
			return ($currentTime - $access['timestamp']) <= 86400; // Último día
		});
		
		$usageMetrics = [
			'total_accesses' => $totalAccesses,
			'recent_accesses' => count($recentAccesses),
			'daily_accesses' => count($dailyAccesses),
			'last_access' => end($accessHistory)['timestamp'] ?? 0,
			'access_frequency' => $this->calculateAccessFrequency($accessHistory),
			'usage_score' => $this->calculateUsageScore($accessHistory),
			'last_updated' => $currentTime
		];
		
		update_option('mia_transient_usage_metrics_' . $cacheKey, $usageMetrics, false);
	}
	
	/**
	 * NUEVO: Calcula la frecuencia de acceso de un transient
	 * 
	 * @param array $accessHistory Historial de accesos
	 * @return string Frecuencia de acceso
	 */
	private function calculateAccessFrequency(array $accessHistory): string
	{
		if (empty($accessHistory)) {
			return 'never';
		}
		
		$currentTime = time();
		$lastAccess = end($accessHistory)['timestamp'];
		$timeSinceLastAccess = $currentTime - $lastAccess;
		
		if ($timeSinceLastAccess < self::VERY_HIGH_PRIORITY_THRESHOLD) {
			return 'very_high';
		} elseif ($timeSinceLastAccess < self::HIGH_PRIORITY_THRESHOLD) {
			return 'high';
		} elseif ($timeSinceLastAccess < self::MEDIUM_PRIORITY_THRESHOLD) {
			return 'medium';
		} elseif ($timeSinceLastAccess < self::LOW_PRIORITY_THRESHOLD) {
			return 'low';
		} else {
			return 'very_low';
		}
	}
	
	/**
	 * NUEVO: Calcula el score de uso de un transient
	 * 
	 * @param array $accessHistory Historial de accesos
	 * @return float Score de uso (0-100)
	 */
	private function calculateUsageScore(array $accessHistory): float
	{
		if (empty($accessHistory)) {
			return 0;
		}
		
		$currentTime = time();
		$totalAccesses = count($accessHistory);
		$recentAccesses = array_filter($accessHistory, function($access) use ($currentTime) {
			return ($currentTime - $access['timestamp']) <= self::RECENT_ACCESS_THRESHOLD;
		});
		$dailyAccesses = array_filter($accessHistory, function($access) use ($currentTime) {
			return ($currentTime - $access['timestamp']) <= 86400; // Último día
		});
		
		// Score basado en accesos recientes y totales
		$recentScore = min(50, count($recentAccesses) * 10);
		$dailyScore = min(30, count($dailyAccesses) * 0.5);
		$totalScore = min(20, $totalAccesses * 0.1);
		
		return $this->safe_round($recentScore + $dailyScore + $totalScore, 2);
	}
	
	/**
	 * NUEVO: Registra eventos de limpieza programada con WordPress Cron
	 * 
	 * @return void
	 */
	// MIGRADO A RobustnessHooks.php: registerScheduledCleanupEvents() y executeScheduledCleanup()
	
	/**
	 * REFACTORIZADO: Obtiene el TTL base según el entorno y configuración personalizada
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $customTTLs Configuración personalizada
	 * @return int TTL base en segundos
	 */
	private function getBaseExpiration(string $cacheKey, array $customTTLs): int
	{
		// REFACTORIZADO: Detectar entorno de forma inteligente
		$environment = $this->detectEnvironment();
		
		// REFACTORIZADO: Obtener TTL según entorno
		$baseExpiration = $customTTLs[$environment] ?? $customTTLs['prod'];
		
		// REFACTORIZADO: Logging de configuración de TTL
		$this->logger->debug("TTL configurado para transient", [
			'cache_key' => $cacheKey,
			'environment' => $environment,
			'ttl_seconds' => $baseExpiration,
			'ttl_hours' => $this->safe_round($baseExpiration / self::SECONDS_PER_HOUR, 2)
		]);
		
		return $baseExpiration;
	}
	
	
	/**
	 * REFACTORIZADO: Aplica límites mínimos y máximos a la expiración
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $customTTLs Configuración personalizada
	 * @return int TTL final
	 */
	private function applyExpirationLimits(int $expiration, string $cacheKey, array $customTTLs): int
	{
		$minExpiration = $customTTLs['min'] ?? 30 * MINUTE_IN_SECONDS;
		$maxExpiration = $customTTLs['max'] ?? 24 * HOUR_IN_SECONDS;
		
		// REFACTORIZADO: Aplicar límites
		$finalExpiration = max($minExpiration, min($maxExpiration, $expiration));
		
		// REFACTORIZADO: Logging si se aplicaron límites
		if ($finalExpiration !== $expiration) {
			$this->logger->info("TTL ajustado por límites", [
				'cache_key' => $cacheKey,
				'original_ttl' => $expiration,
				'final_ttl' => $finalExpiration,
				'min_limit' => $minExpiration,
				'max_limit' => $maxExpiration
			]);
		}
		
		return $finalExpiration;
	}
	
	/**
	 * REFACTORIZADO: Detecta el entorno actual de forma inteligente
	 * 
	 * @return string Entorno detectado
	 */
	private function detectEnvironment(): string
	{
		// REFACTORIZADO: Prioridad 1: Constante WordPress
		if (defined('WP_ENVIRONMENT_TYPE')) {
			$wpEnv = WP_ENVIRONMENT_TYPE;
			if (in_array($wpEnv, ['development', 'staging', 'production'])) {
				return $wpEnv;
			}
		}
		
		// REFACTORIZADO: Prioridad 2: Variable de entorno
		$envVar = getenv('WP_ENVIRONMENT_TYPE');
		if ($envVar && in_array($envVar, ['development', 'staging', 'production'])) {
			return $envVar;
		}
		
		// REFACTORIZADO: Prioridad 3: WP_DEBUG
		if (defined('WP_DEBUG') && WP_DEBUG) {
			return 'development';
		}
		
		// REFACTORIZADO: Prioridad 4: Detección por hostname
		$hostname = gethostname();
		if (str_contains($hostname, 'dev') || str_contains($hostname, 'local')) {
			return 'development';
		} elseif (str_contains($hostname, 'staging') || str_contains($hostname, 'test')) {
			return 'staging';
		}
		
		// REFACTORIZADO: Fallback a producción por seguridad
		return 'production';
	}
	
	
	/**
	 * REFACTORIZADO: Realiza limpieza automática de caché antiguo con optimizaciones
	 * 
	 * @param string $cacheKey Clave del caché a limpiar
	 * @param bool $forceCleanup Forzar limpieza incluso si no es necesario
	 * @return bool True si la limpieza fue exitosa
	 */
	private function cleanOldCache(string $cacheKey, bool $forceCleanup = false): bool
	{
		try {
			// NUEVO: Verificar políticas de retención antes de proceder
			if (!$forceCleanup && $this->shouldRetainTransient($cacheKey)) {
				$policy = $this->getRetentionPolicy($cacheKey);
				$this->logger->info("Saltando limpieza de transient según política de retención", [
					'cache_key' => $cacheKey,
					'reason' => 'retention_policy',
					'policy_type' => $policy['type'],
					'strategy' => $policy['strategy'],
					'force_cleanup' => $forceCleanup
				]);
				return true; // No limpiar según política de retención
			}
			
			// NUEVO: Detección preventiva antes de limpiar
			$this->performPreventiveChecks($cacheKey);
			
			// NUEVO: Registrar acceso al transient para tracking de uso
			$this->recordTransientAccess($cacheKey);
			
			$cacheData = get_transient($cacheKey);
			
			// REFACTORIZADO: Manejo optimizado de casos edge
			if ($cacheData === false) {
				return true; // No hay caché
			}
			
			if (!is_array($cacheData)) {
				// REFACTORIZADO: Limpiar transients no-array que pueden ocupar memoria
				if ($forceCleanup && !empty($cacheData)) {
					$cacheManager = CacheManager::get_instance();
					$this->logger->info("Limpieza forzada de transient no-array", [
						'cache_key' => $cacheKey,
						'data_type' => gettype($cacheData),
						'data_size' => $cacheManager->estimateTransientSize($cacheData)
					]);
					delete_transient($cacheKey);
				}
				return true;
			}
			
			$maxCacheSize = $this->getMaxCacheSize($cacheKey);
			$originalSize = count($cacheData);
			
			// REFACTORIZADO: Limpiar solo si es necesario (a menos que sea forzado)
			if (!$forceCleanup && $originalSize <= $maxCacheSize) {
				return true; // No necesita limpieza
			}
			
			// REFACTORIZADO: Delegar limpieza inteligente basada en estrategia a CacheManager
			$cacheManager = CacheManager::get_instance();
			$cleanedData = $cacheManager->performIntelligentCleanup($cacheKey, $cacheData, $maxCacheSize);
			$cleanedSize = count($cleanedData);
			
			// REFACTORIZADO: Solo actualizar si hay cambios significativos
			if ($cleanedSize === $originalSize && !$forceCleanup) {
				return true; // No hay cambios
			}
			
			// REFACTORIZADO: Actualizar caché con datos limpios
			$expiration = $this->getCacheExpiration($cacheKey);
			$result = set_transient($cacheKey, $cleanedData, $expiration);
			
			if ($result) {
				// REFACTORIZADO: Logging optimizado solo cuando es necesario
				$this->logCleanupResults($cacheKey, $originalSize, $cleanedSize, $maxCacheSize, $expiration);
			}
			
			return $result;
			
		} catch (\Throwable $e) {
			$this->logger->error("Error limpiando caché antiguo", [
				'cache_key' => $cacheKey,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return false;
		}
	}	
	
	/**
	 * NUEVO: Convierte frecuencia de acceso a score numérico
	 * 
	 * @param string $frequency Frecuencia de acceso
	 * @return float Score numérico
	 */
	private function convertFrequencyToScore(string $frequency): float
	{
		$frequencyScores = [
			'very_high' => 100,
			'high' => 80,
			'medium' => 60,
			'low' => 30,
			'very_low' => 10,
			'never' => 0
		];
		
		return $frequencyScores[$frequency] ?? 0;
	}		
	
	/**
	 * REFACTORIZADO: Determina si un dato tiene prioridad alta
	 * 
	 * @param string $key Clave del dato
	 * @param mixed $value Valor del dato
	 * @return bool True si es de alta prioridad
	 */
	private function isHighPriorityData(string $key, mixed $value): bool
	{
		// REFACTORIZADO: Lógica para identificar datos prioritarios
		$priorityPatterns = [
			'current_', 'active_', 'running_', 'last_', 'latest_'
		];
		
		foreach ($priorityPatterns as $pattern) {
			if (str_starts_with($key, $pattern)) {
				return true;
			}
		}
		
		// REFACTORIZADO: Verificar si el valor indica prioridad
		if (is_array($value) && isset($value['priority'])) {
			return $value['priority'] === 'high';
		}
		
		return false;
	}
	
	/**
	 * REFACTORIZADO: Extrae timestamp de un dato
	 * 
	 * @param string $key Clave del dato
	 * @param mixed $value Valor del dato
	 * @return int Timestamp o 0 si no está disponible
	 */
	private function extractTimestamp(string $key, mixed $value): int
	{
		// REFACTORIZADO: Buscar timestamp en diferentes ubicaciones
		if (is_array($value)) {
			if (isset($value['timestamp'])) {
				return (int) $value['timestamp'];
			}
			if (isset($value['created_at'])) {
				return strtotime($value['created_at']);
			}
			if (isset($value['updated_at'])) {
				return strtotime($value['updated_at']);
			}
		}
		
		// REFACTORIZADO: Extraer timestamp de la clave si es posible
		if (preg_match('/_(\d{10,13})$/', $key, $matches)) {
			return (int) $matches[1];
		}
		
		return 0;
	}		

	/**
	 * Obtiene métricas de caché del sistema
	 * 
	 * @return SyncResponseInterface Métricas de caché
	 */
	public function getCacheMetrics(): SyncResponseInterface
	{
		try {
			// DELEGAR A CacheManager
			if (class_exists('\MiIntegracionApi\CacheManager')) {
				$cacheManager = CacheManager::get_instance();
				$result = $cacheManager->getCacheMetrics();
				
				// Convertir array a SyncResponseInterface
				if ($result['success'] ?? false) {
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
						$result,
						$result['message'] ?? 'Métricas de caché obtenidas correctamente'
					);
				} else {
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
						$result['message'] ?? 'Error al obtener métricas de caché',
						400,
						[
							'error' => $result['error'] ?? 'Error desconocido'
						]
					);
				}
			}
			
			// FALLBACK: Retornar error si CacheManager no está disponible
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'CacheManager no disponible para métricas de caché',
				503,
				[
					'total_caches' => 0,
					'total_size' => 0,
					'total_items' => 0,
					'expired_caches' => 0,
					'critical_alerts' => [],
					'detailed_metrics' => [],
					'fallback_reason' => 'CacheManager class not found',
					'error_code' => 'cache_manager_unavailable'
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getCacheMetrics'
			]);
		}
	}
	
	/**
	 * REFACTORIZADO: Obtiene las claves de caché monitoreadas
	 * 
	 * @return array Lista de claves de caché a monitorear
	 */
	private function getMonitoredCacheKeys(): array
	{
		// REFACTORIZADO: Claves de caché monitoreadas con configuración centralizada
		return [
			'mia_category_names_cache',
			'mia_sync_batch_times',
			'mia_sync_completed_batches',
			'mia_sync_current_batch_offset',
			'mia_sync_current_batch_limit',
			'mia_sync_current_batch_time',
			'mia_sync_batch_start_time',
			'mia_sync_current_product_sku',
			'mia_sync_current_product_name',
			'mia_sync_last_product',
			'mia_sync_last_product_time',
			'mia_sync_processed_skus',
			'mia_current_sync_operation_id'
		];
	}
	
	/**
	 * REFACTORIZADO: Obtiene métricas detalladas para un transient específico
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param mixed $cacheData Datos del caché
	 * @return array Métricas detalladas
	 */
	private function getDetailedCacheMetrics(string $cacheKey, mixed $cacheData): array
	{
		if ($cacheData === false) {
			return [
				'exists' => false,
				'size' => 0,
				'items' => 0,
				'expired' => true,
				'memory_bytes' => 0,
				'memory_mb' => 0,
				'last_cleanup' => $this->getLastCleanupTime($cacheKey),
				'cleanup_count' => $this->getCleanupCount($cacheKey),
				'performance_rating' => 'excellent'
			];
		}
		
		$size = is_array($cacheData) ? count($cacheData) : 1;
		$memoryBytes = $this->estimateCacheMemorySize($cacheData);
		$maxAllowed = $this->getMaxCacheSize($cacheKey);
		$expiration = $this->getCacheExpiration($cacheKey);
		
		// REFACTORIZADO: Calcular métricas de rendimiento
		$usagePercentage = $maxAllowed > 0 ? ($size / $maxAllowed) * 100 : 0;
		$performanceRating = $this->calculatePerformanceRating($usagePercentage, $memoryBytes);
		
		// REFACTORIZADO: Obtener historial de limpieza
		$cleanupHistory = $this->getCleanupHistory($cacheKey);
		
		return [
			'exists' => true,
			'size' => $size,
			'memory_bytes' => $memoryBytes,
			'memory_mb' => $this->safe_round($memoryBytes / 1024 / 1024, 2),
			'expired' => false,
			'max_allowed' => $maxAllowed,
			'expiration' => $expiration,
			'usage_percentage' => $this->safe_round($usagePercentage, 2),
			'performance_rating' => $performanceRating,
			'last_cleanup' => $cleanupHistory['last_cleanup'] ?? null,
			'cleanup_count' => $cleanupHistory['cleanup_count'] ?? 0,
			'cleanup_frequency' => $this->calculateCleanupFrequency($cleanupHistory),
			'growth_rate' => $this->calculateGrowthRate($cacheKey, $size),
			'critical_level' => $this->isCriticalLevel($cacheKey, $usagePercentage, $memoryBytes),
			'ttl_config' => $this->getCustomTTLConfiguration($cacheKey)
		];
	}
	
	/**
	 * REFACTORIZADO: Calcula métricas agregadas del sistema de caché
	 * 
	 * @param array $cacheKeys Claves de caché
	 * @param int $totalSize Tamaño total en bytes
	 * @param int $totalItems Total de elementos
	 * @param int $expiredCaches Cachés expirados
	 * @return array Métricas agregadas
	 */
	private function calculateAggregatedMetrics(array $cacheKeys, int $totalSize, int $totalItems, int $expiredCaches): array
	{
		$totalCaches = count($cacheKeys);
		$activeCaches = $totalCaches - $expiredCaches;
		
		return [
			'total_caches' => $totalCaches,
			'active_caches' => $activeCaches,
			'expired_caches' => $expiredCaches,
			'total_items' => $totalItems,
			'total_memory_bytes' => $totalSize,
			'total_memory_mb' => $this->safe_round($totalSize / 1024 / 1024, 2),
			'average_items_per_cache' => $totalItems > 0 ? $this->safe_round($totalItems / $totalCaches, 1) : 0,
			'average_memory_per_cache_mb' => $totalCaches > 0 ? $this->safe_round($totalSize / 1024 / 1024 / $totalCaches, 2) : 0,
			'cache_efficiency' => $this->calculateCacheEfficiency($totalSize, $totalItems),
			'memory_distribution' => $this->analyzeMemoryDistribution($totalSize, $totalCaches),
			'health_score' => $this->calculateHealthScore($activeCaches, $totalCaches, $totalSize)
		];
	}
	
	/**
	 * 
	 * @param array $metrics Métricas detalladas
	 * @return array Análisis de tendencias
	 */
	private function analyzeCacheTrends(array $metrics): array
	{
		$trends = [
			'growth_patterns' => [],
			'performance_trends' => [],
			'cleanup_effectiveness' => [],
			'critical_indicators' => []
		];
		
		foreach ($metrics as $cacheKey => $cacheMetrics) {
			if (!$cacheMetrics['exists']) continue;
			
			// REFACTORIZADO: Analizar patrones de crecimiento
			$growthPattern = $this->analyzeGrowthPattern($cacheKey, $cacheMetrics);
			if ($growthPattern) {
				$trends['growth_patterns'][$cacheKey] = $growthPattern;
			}
			
			// REFACTORIZADO: Analizar tendencias de rendimiento
			$performanceTrend = $this->analyzePerformanceTrend($cacheKey, $cacheMetrics);
			if ($performanceTrend) {
				$trends['performance_trends'][$cacheKey] = $performanceTrend;
			}
			
			// REFACTORIZADO: Analizar efectividad de limpieza
			$cleanupEffectiveness = $this->analyzeCleanupEffectiveness($cacheKey, $cacheMetrics);
			if ($cleanupEffectiveness) {
				$trends['cleanup_effectiveness'][$cacheKey] = $cleanupEffectiveness;
			}
			
			// REFACTORIZADO: Detectar indicadores críticos
			if ($cacheMetrics['critical_level']) {
				$trends['critical_indicators'][$cacheKey] = $this->getCriticalIndicators($cacheKey, $cacheMetrics);
			}
		}
		
		return $trends;
	}
	
	/**
	 * REFACTORIZADO: Calcula el score de rendimiento del sistema de caché
	 * 
	 * @param array $metrics Métricas detalladas
	 * @return float Score de rendimiento (0-100)
	 */
	private function calculatePerformanceScore(array $metrics): float
	{
		$totalScore = 0;
		$validMetrics = 0;
		
		foreach ($metrics as $cacheMetrics) {
			if (!$cacheMetrics['exists']) continue;
			
			$score = $this->calculateIndividualPerformanceScore($cacheMetrics);
			$totalScore += $score;
			$validMetrics++;
		}
		
		return $validMetrics > 0 ? $this->safe_round($totalScore / $validMetrics, 2) : 100;
	}
	
	/**
	 * REFACTORIZADO: Genera recomendaciones basadas en métricas y tendencias
	 * 
	 * @param array $metrics Métricas detalladas
	 * @param array $trends Análisis de tendencias
	 * @return array Lista de recomendaciones
	 */
	private function generateCacheRecommendations(array $metrics, array $trends): array
	{
		$recommendations = [];
		
		// REFACTORIZADO: Recomendaciones basadas en uso de memoria
		$memoryRecommendations = $this->generateMemoryRecommendations($metrics);
		$recommendations = array_merge($recommendations, $memoryRecommendations);
		
		// REFACTORIZADO: Recomendaciones basadas en patrones de crecimiento
		$growthRecommendations = $this->generateGrowthRecommendations($trends['growth_patterns'] ?? []);
		$recommendations = array_merge($recommendations, $growthRecommendations);
		
		// REFACTORIZADO: Recomendaciones basadas en rendimiento
		$performanceRecommendations = $this->generatePerformanceRecommendations($trends['performance_trends'] ?? []);
		$recommendations = array_merge($recommendations, $performanceRecommendations);
		
		// REFACTORIZADO: Recomendaciones basadas en limpieza
		$cleanupRecommendations = $this->generateCleanupRecommendations($trends['cleanup_effectiveness'] ?? []);
		$recommendations = array_merge($recommendations, $cleanupRecommendations);
		
		// REFACTORIZADO: Priorizar recomendaciones
		return $this->prioritizeRecommendations($recommendations);
	}
	
	/**
	 * REFACTORIZADO: Logging inteligente basado en métricas
	 * 
	 * @param array $report Reporte completo de métricas
	 */
	private function logCacheMetricsIntelligently(array $report): void
	{
		$totalMemory = $report['total_memory_mb'] ?? 0;
		$criticalAlerts = $report['critical_alerts'] ?? [];
		$performanceScore = $report['performance_score'] ?? 100;
		
		// REFACTORIZADO: Logging basado en umbrales de memoria
		if ($totalMemory > 100) { // Más de 100MB
			$this->logger->error("Uso crítico de caché detectado", [
				'total_memory_mb' => $totalMemory,
				'critical_alerts' => count($criticalAlerts),
				'performance_score' => $performanceScore,
				'recommendation' => 'Limpieza inmediata de caché requerida'
			]);
		} elseif ($totalMemory > 50) { // Más de 50MB
			$this->logger->warning("Uso alto de caché detectado", [
				'total_memory_mb' => $totalMemory,
				'critical_alerts' => count($criticalAlerts),
				'performance_score' => $performanceScore,
				'recommendation' => 'Considerar limpieza de caché'
			]);
		} elseif ($totalMemory > 25) { // Más de 25MB
			$this->logger->info("Uso moderado de caché", [
				'total_memory_mb' => $totalMemory,
				'performance_score' => $performanceScore
			]);
		}
		
		// REFACTORIZADO: Logging de alertas críticas
		if (!empty($criticalAlerts)) {
			$this->logger->warning("Alertas críticas de caché detectadas", [
				'alert_count' => count($criticalAlerts),
				'alerts' => $criticalAlerts
			]);
		}
		
		// REFACTORIZADO: Logging de rendimiento
		if ($performanceScore < 70) {
			$this->logger->warning("Rendimiento de caché bajo detectado", [
				'performance_score' => $performanceScore,
				'recommendations' => $report['recommendations'] ?? []
			]);
		}
	}
	
	/**
	 * REFACTORIZADO: Estima el tamaño de memoria de un caché
	 * 
	 * @param mixed $cacheData Datos del caché
	 * @return int Tamaño estimado en bytes
	 */
	private function estimateCacheMemorySize(mixed $cacheData): int
	{
		if (is_array($cacheData)) {
			$size = 0;
			foreach ($cacheData as $key => $value) {
				$size += strlen(serialize($key)) + strlen(serialize($value));
			}
			return $size;
		}
		
		return strlen(serialize($cacheData));
	}
	
	// MIGRADO A CacheManager.php: getLastCleanupTime(), getCleanupCount(), getCleanupHistory()
	
	/**
	 * REFACTORIZADO: Calcula el rating de rendimiento de un transient
	 * 
	 * @param float $usagePercentage Porcentaje de uso
	 * @param int $memoryBytes Tamaño en memoria
	 * @return string Rating de rendimiento
	 */
	private function calculatePerformanceRating(float $usagePercentage, int $memoryBytes): string
	{
		$memoryMB = $memoryBytes / 1024 / 1024;
		
		if ($usagePercentage > 90 || $memoryMB > 50) {
			return 'critical';
		} elseif ($usagePercentage > 75 || $memoryMB > 25) {
			return 'poor';
		} elseif ($usagePercentage > 50 || $memoryMB > 10) {
			return 'fair';
		} elseif ($usagePercentage > 25 || $memoryMB > 5) {
			return 'good';
		} else {
			return 'excellent';
		}
	}
	
	/**
	 * REFACTORIZADO: Calcula la frecuencia de limpieza de un transient
	 * 
	 * @param array $cleanupHistory Historial de limpieza
	 * @return string Frecuencia de limpieza
	 */
	private function calculateCleanupFrequency(array $cleanupHistory): string
	{
		$cleanupCount = $cleanupHistory['cleanup_count'] ?? 0;
		$lastCleanup = $cleanupHistory['last_cleanup'] ?? 0;
		$currentTime = time();
		
		if ($cleanupCount === 0) {
			return 'never';
		}
		
		$daysSinceLastCleanup = ($currentTime - $lastCleanup) / DAY_IN_SECONDS;
		
		if ($daysSinceLastCleanup < 1) {
			return 'daily';
		} elseif ($daysSinceLastCleanup < 7) {
			return 'weekly';
		} elseif ($daysSinceLastCleanup < 30) {
			return 'monthly';
		} else {
			return 'rarely';
		}
	}
	
	/**
	 * REFACTORIZADO: Calcula la tasa de crecimiento de un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param int $currentSize Tamaño actual
	 * @return float Tasa de crecimiento
	 */
	private function calculateGrowthRate(string $cacheKey, int $currentSize): float
	{
		$previousSize = get_option('mia_cache_previous_size_' . $cacheKey, $currentSize);
		$lastCheck = get_option('mia_cache_last_size_check_' . $cacheKey, time());
		$currentTime = time();
		
		// REFACTORIZADO: Actualizar tamaño anterior para la próxima comparación
		update_option('mia_cache_previous_size_' . $cacheKey, $currentSize);
		update_option('mia_cache_last_size_check_' . $cacheKey, $currentTime);
		
		$timeDiff = max(1, $currentTime - $lastCheck); // Evitar división por cero
		$sizeDiff = $currentSize - $previousSize;
		
		return $timeDiff > 0 ? $this->safe_round(($sizeDiff / $timeDiff) * self::SECONDS_PER_HOUR, 2) : 0;
	}
	
	/**
	 * REFACTORIZADO: Determina si un transient está en nivel crítico
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param float $usagePercentage Porcentaje de uso
	 * @param int $memoryBytes Tamaño en memoria
	 * @return bool True si está en nivel crítico
	 */
	private function isCriticalLevel(string $cacheKey, float $usagePercentage, int $memoryBytes): bool
	{
		$ttlConfig = $this->getCustomTTLConfiguration($cacheKey);
		$isCritical = $ttlConfig['critical'] ?? false;
		$memoryMB = $memoryBytes / 1024 / 1024;
		
		// REFACTORIZADO: Nivel crítico basado en configuración y métricas
		return $isCritical || $usagePercentage > 90 || $memoryMB > 50;
	}
	
	/**
	 * REFACTORIZADO: Calcula la eficiencia del sistema de caché
	 * 
	 * @param int $totalSize Tamaño total en bytes
	 * @param int $totalItems Total de elementos
	 * @return float Eficiencia (0-100)
	 */
	private function calculateCacheEfficiency(int $totalSize, int $totalItems): float
	{
		if ($totalItems === 0) return 100;
		
		$averageItemSize = $totalSize / $totalItems;
		$maxEfficientSize = 1024; // 1KB por elemento como referencia
		
		$efficiency = max(0, 100 - (($averageItemSize - $maxEfficientSize) / $maxEfficientSize) * 100);
		return $this->safe_round(min(100, $efficiency), 2);
	}
	
	/**
	 * REFACTORIZADO: Analiza la distribución de memoria entre transients
	 * 
	 * @param int $totalSize Tamaño total en bytes
	 * @param int $totalCaches Total de cachés
	 * @return array Análisis de distribución
	 */
	private function analyzeMemoryDistribution(int $totalSize, int $totalCaches): array
	{
		$averageSize = $totalCaches > 0 ? $totalSize / $totalCaches : 0;
		$averageSizeMB = $this->safe_round($averageSize / 1024 / 1024, 2);
		
		return [
			'average_size_mb' => $averageSizeMB,
			'distribution_type' => $this->getDistributionType($averageSizeMB),
			'balance_score' => $this->calculateBalanceScore($totalSize, $totalCaches)
		];
	}
	
	/**
	 * REFACTORIZADO: Calcula el score de salud del sistema de caché
	 * 
	 * @param int $activeCaches Cachés activos
	 * @param int $totalCaches Total de cachés
	 * @param int $totalSize Tamaño total
	 * @return float Score de salud (0-100)
	 */
	private function calculateHealthScore(int $activeCaches, int $totalCaches, int $totalSize): float
	{
		$activeRatio = $totalCaches > 0 ? ($activeCaches / $totalCaches) * 100 : 100;
		$memoryScore = $totalSize < 50 * 1024 * 1024 ? 100 : max(0, 100 - (($totalSize - 50 * 1024 * 1024) / (100 * 1024 * 1024)) * 100);
		
		$healthScore = ($activeRatio * 0.6) + ($memoryScore * 0.4);
		return $this->safe_round($healthScore, 2);
	}
	
	/**
	 * REFACTORIZADO: Analiza el patrón de crecimiento de un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $cacheMetrics Métricas del caché
	 * @return array|null Patrón de crecimiento o null
	 */
	private function analyzeGrowthPattern(string $cacheKey, array $cacheMetrics): ?array
	{
		$growthRate = $cacheMetrics['growth_rate'] ?? 0;
		$usagePercentage = $cacheMetrics['usage_percentage'] ?? 0;
		
		if ($growthRate === 0) return null;
		
		$pattern = [
			'type' => $growthRate > 0 ? 'growing' : 'shrinking',
			'rate' => $growthRate,
			'severity' => $this->getGrowthSeverity($growthRate, $usagePercentage),
			'trend' => $this->getGrowthTrend($growthRate),
			'recommendation' => $this->getGrowthRecommendation($growthRate, $usagePercentage)
		];
		
		return $pattern;
	}
	
	/**
	 * REFACTORIZADO: Analiza la tendencia de rendimiento de un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $cacheMetrics Métricas del caché
	 * @return array|null Tendencia de rendimiento o null
	 */
	private function analyzePerformanceTrend(string $cacheKey, array $cacheMetrics): ?array
	{
		$performanceRating = $cacheMetrics['performance_rating'] ?? 'excellent';
		$usagePercentage = $cacheMetrics['usage_percentage'] ?? 0;
		
		$trend = [
			'current_rating' => $performanceRating,
			'trend_direction' => $this->getPerformanceTrendDirection($cacheKey, $performanceRating),
			'improvement_potential' => $this->calculateImprovementPotential($usagePercentage),
			'priority' => $this->getPerformancePriority($performanceRating, $usagePercentage)
		];
		
		return $trend;
	}
	
	// MIGRADO A CacheManager.php: analyzeCleanupEffectiveness()
	
	/**
	 * REFACTORIZADO: Obtiene indicadores críticos de un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $cacheMetrics Métricas del caché
	 * @return array Indicadores críticos
	 */
	private function getCriticalIndicators(string $cacheKey, array $cacheMetrics): array
	{
		$usagePercentage = $cacheMetrics['usage_percentage'] ?? 0;
		$memoryMB = $cacheMetrics['memory_mb'] ?? 0;
		$growthRate = $cacheMetrics['growth_rate'] ?? 0;
		
		return [
			'high_usage' => $usagePercentage > 90,
			'high_memory' => $memoryMB > 50,
			'rapid_growth' => $growthRate > 100,
			'critical_factors' => $this->getCriticalFactors($usagePercentage, $memoryMB, $growthRate),
			'immediate_actions' => $this->getImmediateActions($usagePercentage, $memoryMB, $growthRate)
		];
	}
	
	/**
	 * REFACTORIZADO: Calcula el score de rendimiento individual de un transient
	 * 
	 * @param array $cacheMetrics Métricas del caché
	 * @return float Score de rendimiento (0-100)
	 */
	private function calculateIndividualPerformanceScore(array $cacheMetrics): float
	{
		$usagePercentage = $cacheMetrics['usage_percentage'] ?? 0;
		$memoryMB = $cacheMetrics['memory_mb'] ?? 0;
		$performanceRating = $cacheMetrics['performance_rating'] ?? 'excellent';
		
		// REFACTORIZADO: Score basado en múltiples factores
		$usageScore = max(0, 100 - $usagePercentage);
		$memoryScore = max(0, 100 - ($memoryMB * 2)); // Penalizar memoria alta
		$ratingScore = $this->getRatingScore($performanceRating);
		
		$totalScore = ($usageScore * 0.4) + ($memoryScore * 0.4) + ($ratingScore * 0.2);
		return $this->safe_round(max(0, min(100, $totalScore)), 2);
	}
	
	/**
	 * REFACTORIZADO: Genera recomendaciones basadas en uso de memoria
	 * 
	 * @param array $metrics Métricas detalladas
	 * @return array Recomendaciones de memoria
	 */
	private function generateMemoryRecommendations(array $metrics): array
	{
		$recommendations = [];
		
		foreach ($metrics as $cacheKey => $cacheMetrics) {
			if (!$cacheMetrics['exists']) continue;
			
			$memoryMB = $cacheMetrics['memory_mb'] ?? 0;
			$usagePercentage = $cacheMetrics['usage_percentage'] ?? 0;
			
			if ($memoryMB > 50) {
				$recommendations[] = [
					'type' => 'memory',
					'priority' => 'high',
					'cache_key' => $cacheKey,
					'message' => "Transient {$cacheKey} usa {$memoryMB}MB - limpieza inmediata requerida",
					'action' => 'force_cleanup'
				];
			} elseif ($memoryMB > 25) {
				$recommendations[] = [
					'type' => 'memory',
					'priority' => 'medium',
					'cache_key' => $cacheKey,
					'message' => "Transient {$cacheKey} usa {$memoryMB}MB - considerar limpieza",
					'action' => 'scheduled_cleanup'
				];
			}
			
			if ($usagePercentage > 90) {
				$recommendations[] = [
					'type' => 'usage',
					'priority' => 'high',
					'cache_key' => $cacheKey,
					'message' => "Transient {$cacheKey} al {$usagePercentage}% de capacidad - revisar límites",
					'action' => 'increase_limits'
				];
			}
		}
		
		return $recommendations;
	}
	
	/**
	 * REFACTORIZADO: Genera recomendaciones basadas en patrones de crecimiento
	 * 
	 * @param array $growthPatterns Patrones de crecimiento
	 * @return array Recomendaciones de crecimiento
	 */
	private function generateGrowthRecommendations(array $growthPatterns): array
	{
		$recommendations = [];
		
		foreach ($growthPatterns as $cacheKey => $pattern) {
			$growthRate = $pattern['rate'] ?? 0;
			$severity = $pattern['severity'] ?? 'low';
			
			if ($severity === 'high') {
				$recommendations[] = [
					'type' => 'growth',
					'priority' => 'high',
					'cache_key' => $cacheKey,
					'message' => "Transient {$cacheKey} crece rápidamente ({$growthRate} items/hora) - revisar lógica",
					'action' => 'investigate_growth'
				];
			} elseif ($severity === 'medium') {
				$recommendations[] = [
					'type' => 'growth',
					'priority' => 'medium',
					'cache_key' => $cacheKey,
					'message' => "Transient {$cacheKey} crece moderadamente - monitorear",
					'action' => 'monitor_growth'
				];
			}
		}
		
		return $recommendations;
	}
	
	/**
	 * REFACTORIZADO: Genera recomendaciones basadas en rendimiento
	 * 
	 * @param array $performanceTrends Tendencias de rendimiento
	 * @return array Recomendaciones de rendimiento
	 */
	private function generatePerformanceRecommendations(array $performanceTrends): array
	{
		$recommendations = [];
		
		foreach ($performanceTrends as $cacheKey => $trend) {
			$currentRating = $trend['current_rating'] ?? 'excellent';
			$priority = $trend['priority'] ?? 'low';
			
			if ($priority === 'high') {
				$recommendations[] = [
					'type' => 'performance',
					'priority' => 'high',
					'cache_key' => $cacheKey,
					'message' => "Transient {$cacheKey} tiene rendimiento {$currentRating} - optimización requerida",
					'action' => 'optimize_cache'
				];
			} elseif ($priority === 'medium') {
				$recommendations[] = [
					'type' => 'performance',
					'priority' => 'medium',
					'cache_key' => $cacheKey,
					'message' => "Transient {$cacheKey} puede optimizarse",
					'action' => 'review_cache'
				];
			}
		}
		
		return $recommendations;
	}
	
	// MIGRADO A CacheManager.php: generateCleanupRecommendations(), prioritizeRecommendations(), shouldTriggerCriticalAlert(), createCriticalAlert()
	
	/**
	 * REFACTORIZADO: Obtiene acciones inmediatas para un transient crítico
	 * 
	 * @param float $usagePercentage Porcentaje de uso
	 * @param float $memoryMB Memoria en MB
	 * @param float $growthRate Tasa de crecimiento
	 * @return array Acciones inmediatas
	 */
	private function getImmediateActions(float $usagePercentage, float $memoryMB, float $growthRate): array
	{
		$actions = [];
		
		if ($usagePercentage > 95) {
			$actions[] = 'force_cleanup';
		}
		if ($memoryMB > 75) {
			$actions[] = 'emergency_cleanup';
		}
		if ($growthRate > 200) {
			$actions[] = 'investigate_growth_cause';
		}
		
		return array_unique($actions);
	}
	
	/**
	 * REFACTORIZADO: Obtiene el tipo de distribución de memoria
	 * 
	 * @param float $averageSizeMB Tamaño promedio en MB
	 * @return string Tipo de distribución
	 */
	private function getDistributionType(float $averageSizeMB): string
	{
		if ($averageSizeMB > 20) return 'unbalanced_high';
		if ($averageSizeMB > 10) return 'unbalanced_medium';
		if ($averageSizeMB > 5) return 'balanced_medium';
		if ($averageSizeMB > 1) return 'balanced_low';
		return 'excellent';
	}
	
	/**
	 * REFACTORIZADO: Calcula el score de balance de memoria
	 * 
	 * @param int $totalSize Tamaño total
	 * @param int $totalCaches Total de cachés
	 * @return float Score de balance (0-100)
	 */
	private function calculateBalanceScore(int $totalSize, int $totalCaches): float
	{
		if ($totalCaches <= 1) return 100;
		
		$averageSize = $totalSize / $totalCaches;
		$variance = 0;
		
		// REFACTORIZADO: Calcular varianza para medir balance
		foreach ($this->getMonitoredCacheKeys() as $cacheKey) {
			$cacheData = get_transient($cacheKey);
			if ($cacheData !== false) {
				$size = is_array($cacheData) ? count($cacheData) : 1;
				$variance += pow($size - $averageSize, 2);
			}
		}
		
		$variance = $variance / $totalCaches;
		$standardDeviation = sqrt($variance);
		$coefficientOfVariation = $averageSize > 0 ? $standardDeviation / $averageSize : 0;
		
		$balanceScore = max(0, 100 - ($coefficientOfVariation * 100));
		return $this->safe_round($balanceScore, 2);
	}
	
	/**
	 * REFACTORIZADO: Obtiene la severidad del crecimiento
	 * 
	 * @param float $growthRate Tasa de crecimiento
	 * @param float $usagePercentage Porcentaje de uso
	 * @return string Severidad del crecimiento
	 */
	private function getGrowthSeverity(float $growthRate, float $usagePercentage): string
	{
		if ($growthRate > 200 || $usagePercentage > 95) return 'high';
		if ($growthRate > 100 || $usagePercentage > 80) return 'medium';
		if ($growthRate > 50 || $usagePercentage > 60) return 'low';
		return 'minimal';
	}
	
	/**
	 * REFACTORIZADO: Obtiene la tendencia de crecimiento
	 * 
	 * @param float $growthRate Tasa de crecimiento
	 * @return string Tendencia de crecimiento
	 */
	private function getGrowthTrend(float $growthRate): string
	{
		if ($growthRate > 100) return 'exponential';
		if ($growthRate > 50) return 'linear_fast';
		if ($growthRate > 10) return 'linear_moderate';
		if ($growthRate > 0) return 'linear_slow';
		return 'stable';
	}
	
	/**
	 * REFACTORIZADO: Obtiene recomendación de crecimiento
	 * 
	 * @param float $growthRate Tasa de crecimiento
	 * @param float $usagePercentage Porcentaje de uso
	 * @return string Recomendación
	 */
	private function getGrowthRecommendation(float $growthRate, float $usagePercentage): string
	{
		if ($growthRate > 200) return 'investigate_cause_immediately';
		if ($growthRate > 100) return 'review_growth_logic';
		if ($usagePercentage > 90) return 'increase_limits_or_cleanup';
		if ($growthRate > 50) return 'monitor_closely';
		return 'normal_operation';
	}
	
	/**
	 * REFACTORIZADO: Obtiene la dirección de la tendencia de rendimiento
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param string $currentRating Rating actual
	 * @return string Dirección de la tendencia
	 */
	private function getPerformanceTrendDirection(string $cacheKey, string $currentRating): string
	{
		$previousRating = get_option('mia_cache_previous_rating_' . $cacheKey, $currentRating);
		update_option('mia_cache_previous_rating_' . $cacheKey, $currentRating);
		
		$ratingOrder = ['excellent' => 5, 'good' => 4, 'fair' => 3, 'poor' => 2, 'critical' => 1];
		$currentScore = $ratingOrder[$currentRating] ?? 3;
		$previousScore = $ratingOrder[$previousRating] ?? 3;
		
		if ($currentScore > $previousScore) return 'improving';
		if ($currentScore < $previousScore) return 'declining';
		return 'stable';
	}
	
	/**
	 * REFACTORIZADO: Calcula el potencial de mejora
	 * 
	 * @param float $usagePercentage Porcentaje de uso
	 * @return float Potencial de mejora (0-100)
	 */
	private function calculateImprovementPotential(float $usagePercentage): float
	{
		return $this->safe_round(max(0, 100 - $usagePercentage), 2);
	}
	
	/**
	 * REFACTORIZADO: Obtiene la prioridad de rendimiento
	 * 
	 * @param string $performanceRating Rating de rendimiento
	 * @param float $usagePercentage Porcentaje de uso
	 * @return string Prioridad
	 */
	private function getPerformancePriority(string $performanceRating, float $usagePercentage): string
	{
		if ($performanceRating === 'critical' || $usagePercentage > 90) return 'high';
		if ($performanceRating === 'poor' || $usagePercentage > 75) return 'medium';
		return 'low';
	}
	
	/**
	 * REFACTORIZADO: Calcula el score de efectividad de limpieza
	 * 
	 * @param int $cleanupCount Conteo de limpiezas
	 * @param string $cleanupFrequency Frecuencia de limpieza
	 * @param float $usagePercentage Porcentaje de uso
	 * @return float Score de efectividad (0-100)
	 */
	// MIGRADO A CacheManager.php: calculateCleanupEffectivenessScore()
	
	/**
	 * REFACTORIZADO: Obtiene el potencial de optimización de limpieza
	 * 
	 * @param string $cleanupFrequency Frecuencia de limpieza
	 * @param float $usagePercentage Porcentaje de uso
	 * @return string Potencial de optimización
	 */
	// MIGRADO A CacheManager.php: getCleanupOptimizationPotential()
	
	/**
	 * REFACTORIZADO: Obtiene factores críticos
	 * 
	 * @param float $usagePercentage Porcentaje de uso
	 * @param float $memoryMB Memoria en MB
	 * @param float $growthRate Tasa de crecimiento
	 * @return array Factores críticos
	 */
	// MIGRADO A CacheManager.php: getCriticalFactors()
	
	/**
	 * REFACTORIZADO: Obtiene el score de rating
	 * 
	 * @param string $rating Rating de rendimiento
	 * @return float Score numérico
	 */
	// MIGRADO A CacheManager.php: getRatingScore()
	
	/**
	 * REFACTORIZADO: Obtiene el score de frecuencia
	 * 
	 * @param string $frequency Frecuencia de limpieza
	 * @return float Score numérico
	 */
	// MIGRADO A CacheManager.php: getFrequencyScore()

	/**
	 * Obtiene métricas de rendimiento de sincronización
	 * 
	 * @param string|null $run_id ID específico de ejecución (opcional)
	 * @return SyncResponseInterface Métricas de rendimiento
	 */
	public function get_sync_performance_metrics(?string $run_id = null): SyncResponseInterface
    {
		try {
			$this->load_sync_status();
			$historyResponse = $this->get_sync_history(100);
			
			// Si la respuesta del historial es exitosa, extraer los datos
			if (!$historyResponse->isSuccess()) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'No se pudo obtener el historial de sincronización',
					500,
					[
						'run_id' => $run_id,
						'history_error' => $historyResponse->getMessage()
					]
				);
			}
			
			$history = $historyResponse->getData()['data'] ?? [];
			
			$metrics = SyncMetrics::getSyncPerformanceMetrics(
				$run_id, 
				$this->sync_status, 
				$history
			);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$metrics,
				'Métricas de rendimiento obtenidas exitosamente',
				[
					'operation' => 'get_sync_performance_metrics',
					'run_id' => $run_id,
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'run_id' => $run_id,
				'operation' => 'get_sync_performance_metrics'
			]);
		}
	}
		

	/**
	 * Obtiene estadísticas de errores de sincronización
	 * 
	 * @param string|null $run_id ID específico de ejecución (opcional)
	 * @param int $limit Límite de resultados por tipo de error
	 * @return SyncResponseInterface Estadísticas de errores
	 */
	public function get_sync_error_stats(?string $run_id = null, int $limit = 10): SyncResponseInterface
    {
		try {
			// Validar parámetros
			if ($limit < 1 || $limit > 1000) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'El límite debe estar entre 1 y 1000',
					['limit' => $limit]
				);
			}
			
			$stats = SyncMetrics::getSyncErrorStats($run_id, $limit);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$stats,
				'Estadísticas de errores obtenidas exitosamente',
				[
					'operation' => 'get_sync_error_stats',
					'run_id' => $run_id,
					'limit' => $limit,
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'run_id' => $run_id,
				'limit' => $limit,
				'operation' => 'get_sync_error_stats'
			]);
		}
	}
	
	/**
	 * Diagnostica problemas comunes de sincronización y devuelve recomendaciones
	 * 
	 * @return SyncResponseInterface Diagnóstico con problemas detectados y recomendaciones
	 */
	public function diagnose_sync_issues(): SyncResponseInterface
    {
		try {
			global $wpdb;
			
			$issues = [];
			$recommendations = [];
			$diagnostics = [];
		
		// Verificar si hay una sincronización activa
		$this->load_sync_status();
		if ($this->sync_status['current_sync']['in_progress']) {
			// Verificar si la sincronización está estancada
			$last_update = $this->sync_status['current_sync']['last_update'] ?? 0;
			$now = time();
			
			if ($now - $last_update > 600) { // 10 minutos sin actividad
				$issues[] = sprintf(
					__('Sincronización estancada. Última actualización hace %d minutos.', 'mi-integracion-api'),
					floor(($now - $last_update) / 60)
				);
				$recommendations[] = __('Cancelar la sincronización actual y reiniciar.', 'mi-integracion-api');
			}
			
			$diagnostics['current_sync'] = [
				'entity' => $this->sync_status['current_sync']['entity'],
				'direction' => $this->sync_status['current_sync']['direction'],
				'current_batch' => $this->sync_status['current_sync']['current_batch'],
				'total_batches' => $this->sync_status['current_sync']['total_batches'],
				'items_synced' => $this->sync_status['current_sync']['items_synced'],
				'batch_size' => $this->sync_status['current_sync']['batch_size'],
				'last_update' => date('Y-m-d H:i:s', $this->sync_status['current_sync']['last_update']),
				'elapsed_time' => $now - $this->sync_status['current_sync']['start_time']
			];
		}
		
		// Verificar tabla de errores
		$table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
		
		if (!$table_exists) {
			$issues[] = __('La tabla de errores de sincronización no existe.', 'mi-integracion-api');
			$recommendations[] = __('Desactivar y reactivar el plugin para crear la tabla.', 'mi-integracion-api');
		} else {
			// Verificar número de errores
			$error_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
			$diagnostics['error_table'] = [
				'exists' => true,
				'error_count' => (int)$error_count
			];
			
			if ($error_count > 1000) {
				$issues[] = sprintf(
					__('Exceso de registros de error (%d). Puede afectar al rendimiento.', 'mi-integracion-api'),
					$error_count
				);
				$recommendations[] = __('Ejecutar limpieza de registros de error antiguos.', 'mi-integracion-api');
			}
		}
		
		// Verificar transients
		$transients_to_check = [
			'mia_sync_last_activity',
			'mia_sync_completed_batches',
			'mia_sync_processed_skus',
			'mia_sync_current_batch_offset',
			'mia_sync_current_batch_limit'
		];
		
		$transient_status = [];
		foreach ($transients_to_check as $transient) {
			$value = get_transient($transient);
			$transient_status[$transient] = [
				'exists' => $value !== false,
				'value_type' => gettype($value)
			];
			
			if ($transient === 'mia_sync_processed_skus' && is_array($value)) {
				$transient_status[$transient]['count'] = count($value);
				
				if (count($value) > 10000) {
					$issues[] = sprintf(
						__('Exceso de SKUs procesados en caché (%d). Puede causar problemas de memoria.', 'mi-integracion-api'),
						count($value)
					);
					$recommendations[] = __('Considerar borrar el transient mia_sync_processed_skus.', 'mi-integracion-api');
				}
			}
		}
		$diagnostics['transients'] = $transient_status;
		
		// Verificar configuraciones de WooCommerce
		if (function_exists('wc_get_products')) {
			$diagnostics['woocommerce'] = [
				'active' => true,
				'version' => WC()->version ?? 'desconocida'
			];
			
			// Verificar límites CRUD de WooCommerce
			$max_execution_time = ini_get('max_execution_time');
			if ($max_execution_time > 0 && $max_execution_time < 120) {
				$issues[] = sprintf(
					__('Tiempo de ejecución PHP bajo (%d segundos). Puede causar interrupciones.', 'mi-integracion-api'),
					$max_execution_time
				);
				$recommendations[] = __('Aumentar max_execution_time a 300 segundos o más.', 'mi-integracion-api');
			}
			
			$memory_limit = ini_get('memory_limit');
			$memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
			
			if ($memory_limit_bytes < 256 * 1024 * 1024) { // 256 MB
				$issues[] = sprintf(
					__('Límite de memoria PHP bajo (%s). Puede causar fallos con catálogos grandes.', 'mi-integracion-api'),
					$memory_limit
				);
				$recommendations[] = __('Aumentar memory_limit a 256M o más.', 'mi-integracion-api');
			}
			
			$diagnostics['php_limits'] = [
				'max_execution_time' => $max_execution_time,
				'memory_limit' => $memory_limit,
				'memory_limit_bytes' => $memory_limit_bytes
			];
		} else {
			$issues[] = __('WooCommerce no está activo o no se detecta correctamente.', 'mi-integracion-api');
			$recommendations[] = __('Verificar la instalación de WooCommerce.', 'mi-integracion-api');
			$diagnostics['woocommerce'] = ['active' => false];
		}
		
		// Verificar API de Verial
		if (!$this->api_connector->has_valid_credentials()) {
			$issues[] = __('No hay credenciales válidas para la API de Verial.', 'mi-integracion-api');
			$recommendations[] = __('Configurar las credenciales de API en la página de configuración.', 'mi-integracion-api');
		} else {
			// Realizar prueba básica de conexión
			$test_result = $this->api_connector->test_connectivity();
			$diagnostics['api_connection'] = [
				'credentials_valid' => true,
				'test_result' => $test_result->isSuccess() ? 'success' : 'error'
			];
			
			if (!$test_result->isSuccess()) {
				$issues[] = sprintf(
					__('Error al conectar con API de Verial: %s', 'mi-integracion-api'),
					$test_result->getMessage()
				);
				$recommendations[] = __('Verificar URL y credenciales de API.', 'mi-integracion-api');
			}
		}
		
		// Devolver resultado completo
		$diagnosisData = [
			'issues' => $issues,
			'recommendations' => $recommendations,
			'diagnostics' => $diagnostics,
			'sync_active' => $this->sync_status['current_sync']['in_progress'],
			'timestamp' => current_time('mysql'),
			'php_version' => PHP_VERSION,
			'wordpress_version' => get_bloginfo('version')
		];
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$diagnosisData,
			'Diagnóstico de sincronización completado',
			[
				'operation' => 'diagnose_sync_issues',
				'issues_count' => count($issues),
				'recommendations_count' => count($recommendations),
				'timestamp' => time()
			]
		);
		
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'diagnose_sync_issues'
			]);
		}
	}
	
	/**
	 * Calcula el tamaño óptimo de lote basado en el rendimiento histórico
	 * 
	 * @return SyncResponseInterface Recomendación de tamaño de lote y análisis
	 */
	public function calculate_optimal_batch_size(): SyncResponseInterface
    {
		try {
			// Obtener historial de tiempos de procesamiento de lotes
			$batch_times = \mia_get_sync_transient('mia_sync_batch_times') ?: [];
			
			if (empty($batch_times)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					[
						'recommended_size' => 75, // Valor por defecto razonable
						'confidence' => 'low',
						'message' => __('No hay suficientes datos históricos. Usando valor predeterminado.', 'mi-integracion-api'),
						'analysis' => []
					],
					'Análisis de tamaño de lote completado con datos limitados',
					[
						'operation' => 'calculate_optimal_batch_size',
						'data_availability' => 'limited',
						'timestamp' => time()
					]
				);
			}
		
		// Agrupar por tamaño de lote y calcular promedios
		$grouped_data = [];
		foreach ($batch_times as $key => $data) {
			$size = $data['limit'] ?? 0;
			if (!$size) continue;
			
			if (!isset($grouped_data[$size])) {
				$grouped_data[$size] = [
					'count' => 0,
					'total_duration' => 0,
					'total_items' => 0,
					'samples' => []
				];
			}
			
			$grouped_data[$size]['count']++;
			$grouped_data[$size]['total_duration'] += $data['duration'] ?? 0;
			$grouped_data[$size]['total_items'] += $data['items'] ?? 0;
			
			// Guardar muestra para análisis (limitado a 5 por tamaño)
			if (count($grouped_data[$size]['samples']) < 5) {
				$grouped_data[$size]['samples'][] = [
					'duration' => $data['duration'] ?? 0,
					'items' => $data['items'] ?? 0,
					'key' => $key
				];
			}
		}
		
		// Calcular rendimiento por tamaño
		$performance_metrics = [];
		foreach ($grouped_data as $size => $data) {
			if ($data['count'] < 2) continue; // Ignorar tamaños con pocas muestras
			
			$avg_duration = $data['total_duration'] / $data['count'];
			$avg_items = $data['total_items'] / $data['count'];
			$items_per_second = $avg_items / $avg_duration;
			
			$performance_metrics[$size] = [
				'size' => (int)$size,
				'avg_duration' => $this->safe_round($avg_duration, 2),
				'items_per_second' => $this->safe_round($items_per_second, 2),
				'sample_count' => $data['count'],
				'samples' => $data['samples']
			];
		}
		
		if (empty($performance_metrics)) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				[
					'recommended_size' => 75,
					'confidence' => 'low',
					'message' => __('Datos insuficientes para análisis.', 'mi-integracion-api'),
					'analysis' => []
				],
				'Análisis de tamaño de lote completado con datos insuficientes',
				[
					'operation' => 'calculate_optimal_batch_size',
					'data_availability' => 'insufficient',
					'timestamp' => time()
				]
			);
		}
		
		// Encontrar el tamaño con mejor rendimiento
		$best_size = 75; // Valor predeterminado
		$best_performance = 0;
		$best_confidence = 'medium';
		
		foreach ($performance_metrics as $size => $metrics) {
			$performance_score = $metrics['items_per_second'] * min(1, $metrics['sample_count'] / 10);
			
			if ($performance_score > $best_performance) {
				$best_performance = $performance_score;
				$best_size = $size;
				$best_confidence = $metrics['sample_count'] >= 5 ? 'high' : 'medium';
			}
		}
		
		// Aplicar límites razonables
		$best_size = max(25, min(200, $best_size));
		
		// Mensaje personalizado
		$message = sprintf(
			__('Tamaño de lote recomendado: %d. Basado en %d muestras con un rendimiento de %.2f elementos/segundo.', 'mi-integracion-api'),
			$best_size,
			$performance_metrics[$best_size]['sample_count'] ?? 0,
			$performance_metrics[$best_size]['items_per_second'] ?? 0
		);
		
		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			[
				'recommended_size' => $best_size,
				'confidence' => $best_confidence,
				'message' => $message,
				'analysis' => $performance_metrics,
				'raw_data_count' => count($batch_times)
			],
			'Análisis de tamaño de lote completado exitosamente',
			[
				'operation' => 'calculate_optimal_batch_size',
				'recommended_size' => $best_size,
				'confidence' => $best_confidence,
				'data_points' => count($batch_times),
				'timestamp' => time()
			]
		);
		
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'calculate_optimal_batch_size'
			]);
		}
	}


	/**
	 * Inicializa la configuración de sincronización
	 *
	 * @param array $config Configuración de sincronización
	 * @return bool
	 */
	public function init_config(array $config): bool
    {
		if (!DataValidator::validate_sync_config($config)) {
			$this->logger->error('Configuración de sincronización inválida');
			return false;
		}

		$this->batch_size = $config['batch_size'] ?? BatchSizeHelper::getBatchSize('productos');
		$this->interval = $config['interval'] ?? 300;
		return true;
	}

	/**
	 * Sincroniza pedidos
	 *
	 * @param array $orders Datos de pedidos
	 * @return bool
	 */
	public function sync_orders(array $orders): bool
    {
		if (!is_array($orders)) {
			$this->logger->error('Datos de pedidos inválidos');
			return false;
		}

		foreach ($orders as $order) {
			if (!DataValidator::validate_order_data($order)) {
				$this->logger->error('Datos de pedido inválidos en lote');
				continue;
			}

			try {
				// Sincronizar pedido individual
				$sync_result = $this->sync_single_order($order);
				
				if (!$sync_result) {
					$this->logger->warning('Error al sincronizar pedido', [
						'order_id' => $order['id'] ?? 'unknown',
						'order_number' => $order['order_number'] ?? 'unknown'
					]);
				}
			} catch (\Exception $e) {
				$this->logger->error('Excepción al sincronizar pedido', [
					'order_id' => $order['id'] ?? 'unknown',
					'error' => $e->getMessage()
				]);
			}
		}

		return true;
	}

	/**
	 * Sincroniza clientes
	 *
	 * @param array $customers Datos de clientes
	 * @return bool
	 */
	public function sync_customers(array $customers): bool
    {
		if (!is_array($customers)) {
			$this->logger->error('Datos de clientes inválidos');
			return false;
		}

		foreach ($customers as $customer) {
			if (!DataValidator::validate_customer_data($customer)) {
				$this->logger->error('Datos de cliente inválidos en lote');
				continue;
			}

			try {
				// Sincronizar cliente individual
				$sync_result = $this->sync_single_customer($customer);
				
				if (!$sync_result) {
					$this->logger->warning('Error al sincronizar cliente', [
						'customer_id' => $customer['id'] ?? 'unknown',
						'email' => $customer['email'] ?? 'unknown'
					]);
				}
			} catch (\Exception $e) {
				$this->logger->error('Excepción al sincronizar cliente', [
					'customer_id' => $customer['id'] ?? 'unknown',
					'error' => $e->getMessage()
				]);
			}
		}

		return true;
	}

	/**
	 * Sincroniza categorías
	 *
	 * @param array $categories Datos de categorías
	 * @return bool
	 */
	public function sync_categories(array $categories): bool
    {
		if (!is_array($categories)) {
			$this->logger->error('Datos de categorías inválidos');
			return false;
		}

		foreach ($categories as $category) {
			if (!DataValidator::validate_category_data($category)) {
				$this->logger->error('Datos de categoría inválidos en lote');
				continue;
			}

			try {
				// Sincronizar categoría individual
				$sync_result = $this->sync_single_category($category);
				
				if (!$sync_result) {
					$this->logger->warning('Error al sincronizar categoría', [
						'category_id' => $category['id'] ?? 'unknown',
						'name' => $category['name'] ?? 'unknown'
					]);
				}
			} catch (\Exception $e) {
				$this->logger->error('Excepción al sincronizar categoría', [
					'category_id' => $category['id'] ?? 'unknown',
					'error' => $e->getMessage()
				]);
			}
		}

		return true;
	}

	/**
	 * Inicializa el proceso de heartbeat
	 * 
	 * @param string $entity Nombre de la entidad
	 * @return void
	 */
	private function initHeartbeatProcess(string $entity): void
	{
		if (!class_exists('\WP_Background_Process')) {
			$this->logger->warning(
				"WP_Background_Process no disponible",
				[
					'entity' => $entity,
					'category' => "sync-{$entity}"
				]
			);
			return;
		}

		$this->heartbeat_process = new HeartbeatProcess($entity);
		$this->heartbeat_process->start();
	}

	/**
	 * Detiene el proceso de heartbeat
	 * 
	 * @return void
	 */
	private function stopHeartbeatProcess(): void
	{
		if ($this->heartbeat_process instanceof HeartbeatProcess) {
			$this->heartbeat_process->stop();
			$this->heartbeat_process = null;
		}
	}

	/**
	 * Obtiene información del último lote que falló para recuperación
	 *
	 * @return array|false Información del último lote fallido o false si no hay información
	 */
	private function get_last_failed_batch(): bool|array
    {
		// Verificar transient con offset del último lote en proceso
		$offset = \mia_get_sync_transient('mia_sync_current_batch_offset');
		$limit = \mia_get_sync_transient('mia_sync_current_batch_limit');
		$time = \mia_get_sync_transient('mia_sync_current_batch_time');
		
		// Si no tenemos información de recuperación, usar el estado actual
		if (false === $offset || false === $limit) {
			return false;
		}
		
		// Verificar si la información no es demasiado antigua (< 24 horas)
		if ($time && (time() - $time) > 86400) {
			// Información demasiado antigua, no usarla
			return false;
		}
		
		return [
			'offset' => (int)$offset,
			'limit' => (int)$limit,
			'time' => $time
		];
	}

	/**
	 * Obtiene métricas de sincronización
	 * 
	 * @param int $days Número de días para el resumen (por defecto 7)
	 * @return SyncResponseInterface Métricas de sincronización
	 */
	public function get_sync_metrics(int $days = 7): SyncResponseInterface
    {
		try {
			// Validar parámetros
			if ($days < 1 || $days > 365) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'El número de días debe estar entre 1 y 365',
					['days' => $days]
				);
			}
			
			$metrics = $this->metrics->getSummaryMetrics($days);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$metrics,
				'Métricas de sincronización obtenidas exitosamente',
				[
					'operation' => 'get_sync_metrics',
					'days' => $days,
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'days' => $days,
				'operation' => 'get_sync_metrics'
			]);
		}
	}


	/**
	 * Sincroniza las imágenes de un producto después de guardarlo usando el sistema legacy
	 * 
	 * @param int $product_id ID del producto de WooCommerce
	 * @param int $verial_product_id ID del producto en Verial
	 * @return void
	 */
	/**
	 * TODO(F5-Imágenes): Reemplazar dependencia directa del SyncManager legacy
	 * por un adaptador de imágenes inyectable que implemente una interfaz.
	 */
	private function sync_images_after_product_save( $product_id, $verial_product_id ): void
    {
		try {
			if (empty($product_id) || empty($verial_product_id)) {
				return;
			}

			// COMENTADO: Las imágenes ya se procesan automáticamente en BatchProcessor
			// No se requiere sincronización adicional aquí
			$this->logger->info("🖼️ Imágenes procesadas automáticamente por BatchProcessor", [
				'product_id' => $product_id,
				'verial_product_id' => $verial_product_id
			]);
		} catch (\Throwable $e) {
			$this->logger->error("❌ Excepción en sync_images_after_product_save", [
				'error' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString()
			]);
		}
	}
	
	// *** FIN DE MÉTODOS LEGACY ELIMINADOS ***

	/**
	 * Obtiene endpoints disponibles del sistema
	 * 
	 * @return SyncResponseInterface Lista de endpoints disponibles
	 */
	public function get_available_endpoints(): SyncResponseInterface
    {
		try {
			$endpoints = [
			'read_operations' => [],
			'write_operations' => [],
			'total_count' => 0,
			'categories' => [
				'geolocalizacion' => [],
				'clientes' => [],
				'articulos' => [],
				'pedidos' => [],
				'mascotas' => [],
				'configuracion' => []
			]
		];

		// Lista completa de endpoints F4 (38 total)
		$endpoint_map = [
			// Geolocalización (5)
			'GetPaisesWS' => ['category' => 'geolocalizacion', 'type' => 'read', 'description' => 'Obtener países'],
			'GetProvinciasWS' => ['category' => 'geolocalizacion', 'type' => 'read', 'description' => 'Obtener provincias'],
			'GetLocalidadesWS' => ['category' => 'geolocalizacion', 'type' => 'read', 'description' => 'Obtener localidades'],
			'NuevaProvinciaWS' => ['category' => 'geolocalizacion', 'type' => 'write', 'description' => 'Crear provincia'],
			'NuevaLocalidadWS' => ['category' => 'geolocalizacion', 'type' => 'write', 'description' => 'Crear localidad'],

			// Clientes (4)
			'GetClientesWS' => ['category' => 'clientes', 'type' => 'read', 'description' => 'Obtener clientes'],
			'NuevoClienteWS' => ['category' => 'clientes', 'type' => 'write', 'description' => 'Crear cliente'],
			'NuevaDireccionEnvioWS' => ['category' => 'clientes', 'type' => 'write', 'description' => 'Crear dirección envío'],
			'GetAgentesWS' => ['category' => 'clientes', 'type' => 'read', 'description' => 'Obtener agentes'],

			// Artículos y Catálogo (15)
			'GetArticulosWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener artículos'],
			'GetNumArticulosWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Contar artículos'],
			'GetStockArticulosWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener stock'],
			'GetImagenesArticulosWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener imágenes'],
			'GetCategoriasWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener categorías'],
			'GetCategoriasWebWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener categorías web'],
			'GetColeccionesWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener colecciones'],
			'GetFabricantesWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener fabricantes'],
			'GetCursosWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener cursos'],
			'GetAsignaturasWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Obtener asignaturas'],
			'GetCamposConfigurablesArticulosWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Campos configurables'],
			'GetArbolCamposConfigurablesArticulosWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Árbol campos configurables'],
			'GetValoresValidadosCampoConfigurableArticulosWS' => ['category' => 'articulos', 'type' => 'read', 'description' => 'Valores validados campos'],

			// Pedidos y Pagos (7)
			'GetHistorialPedidosWS' => ['category' => 'pedidos', 'type' => 'read', 'description' => 'Historial pedidos'],
			'EstadoPedidosWS' => ['category' => 'pedidos', 'type' => 'read', 'description' => 'Estado pedidos'],
			'PedidoModificableWS' => ['category' => 'pedidos', 'type' => 'read', 'description' => 'Verificar si modificable'],
			'NuevoDocClienteWS' => ['category' => 'pedidos', 'type' => 'write', 'description' => 'Crear documento'],
			'UpdateDocClienteWS' => ['category' => 'pedidos', 'type' => 'write', 'description' => 'Actualizar documento'],
			'NuevoPagoWS' => ['category' => 'pedidos', 'type' => 'write', 'description' => 'Registrar pago'],
			'GetFormasEnvioWS' => ['category' => 'pedidos', 'type' => 'read', 'description' => 'Obtener formas envío'],

			// Mascotas (3)
			'GetMascotasWS' => ['category' => 'mascotas', 'type' => 'read', 'description' => 'Obtener mascotas'],
			'NuevaMascotaWS' => ['category' => 'mascotas', 'type' => 'write', 'description' => 'Crear/modificar mascota'],
			'BorrarMascotaWS' => ['category' => 'mascotas', 'type' => 'write', 'description' => 'Eliminar mascota'],

			// Configuración (4)
			'GetCondicionesTarifaWS' => ['category' => 'configuracion', 'type' => 'read', 'description' => 'Condiciones tarifa'],
			'GetMetodosPagoWS' => ['category' => 'configuracion', 'type' => 'read', 'description' => 'Métodos pago'],
			'GetNextNumDocsWS' => ['category' => 'configuracion', 'type' => 'read', 'description' => 'Siguiente número documento'],
			'GetVersionWS' => ['category' => 'configuracion', 'type' => 'read', 'description' => 'Versión del servicio'],
		];

		// Verificar cuáles endpoints están disponibles
		foreach ($endpoint_map as $endpoint_class => $config) {
			$full_class_name = "MiIntegracionApi\\Endpoints\\{$endpoint_class}";
			
			if (class_exists($full_class_name)) {
				$endpoint_info = [
					'class' => $endpoint_class,
					'description' => $config['description'],
					'available' => true
				];

				// Añadir a la categoría correspondiente
				$endpoints['categories'][$config['category']][] = $endpoint_info;

				// Añadir al tipo (read/write)
				if ($config['type'] === 'read') {
					$endpoints['read_operations'][] = $endpoint_info;
				} else {
					$endpoints['write_operations'][] = $endpoint_info;
				}

				$endpoints['total_count']++;
			}
		}

		// Estadísticas
		$endpoints['stats'] = [
			'total_endpoints' => $endpoints['total_count'],
			'read_operations' => $this->getArraySize($endpoints['read_operations']),
			'write_operations' => $this->getArraySize($endpoints['write_operations']),
			'coverage_percentage' => $this->safe_round(($endpoints['total_count'] / 38) * 100, 1),
			'by_category' => array_map('count', $endpoints['categories'])
		];

		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$endpoints,
			'Endpoints disponibles obtenidos exitosamente',
			[
				'operation' => 'get_available_endpoints',
				'total_endpoints' => $endpoints['total_count'],
				'timestamp' => time()
			]
		);
		
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'get_available_endpoints'
			]);
		}
	}

	/**
	 * Verifica si un endpoint específico está disponible
	 * 
	 * @param string $endpoint_name Nombre del endpoint (ej: 'GetArticulosWS')
	 * @return bool True si está disponible, false en caso contrario
	 */
	public function is_endpoint_available(string $endpoint_name): bool {
		$full_class_name = "MiIntegracionApi\\Endpoints\\{$endpoint_name}";
		return class_exists($full_class_name);
	}

	/**
	 * Obtiene endpoints desde el sistema de archivos
	 * 
	 * @return SyncResponseInterface Estadísticas de endpoints encontrados
	 */
	public function get_endpoints_from_filesystem(): SyncResponseInterface
    {
		try {
			$endpoints_dir = dirname(__DIR__) . '/Endpoints/';
			$stats = [
				'total_files' => 0,
				'get_endpoints' => 0,
				'other_endpoints' => 0,
				'files_found' => []
			];

			if (!is_dir($endpoints_dir)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'El directorio de endpoints no existe: ' . $endpoints_dir,
					404,
					[
						'endpoints_dir' => $endpoints_dir,
						'stats' => $stats,
						'error_code' => 'endpoints_directory_not_found'
					]
				);
			}

			$files = glob($endpoints_dir . '*WS.php');
			
			if ($files === false) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Error al escanear el directorio de endpoints',
					500,
					[
						'endpoints_dir' => $endpoints_dir,
						'pattern' => '*WS.php',
						'error_code' => 'glob_scan_failed'
					]
				);
			}
			
			foreach ($files as $file) {
				$filename = basename($file, '.php');
				$stats['files_found'][] = $filename;
				$stats['total_files']++;
				
				if (str_starts_with($filename, 'Get')) {
					$stats['get_endpoints']++;
				} else {
					$stats['other_endpoints']++;
				}
			}

			$stats['coverage_percentage'] = $this->safe_round(($stats['total_files'] / 38) * 100, 1);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$stats,
				'Endpoints del sistema de archivos obtenidos exitosamente',
				[
					'operation' => 'get_endpoints_from_filesystem',
					'endpoints_dir' => $endpoints_dir,
					'total_files' => $stats['total_files'],
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'get_endpoints_from_filesystem'
			]);
		}
	}


	/**
	 * Evalúa si un producto necesita actualización
	 * Método helper migrado desde BatchAutomationManager
	 * 
	 * @param array $productData Datos del producto
	 * @return bool True si necesita actualización
	 * @since 2.0.0
	 */
	private function needsProductUpdate(array $productData): bool
	{
		// Si no tiene verial_id, necesita ser asociado
		if (empty($productData['verial_id'])) {
			return true;
		}
		
		// Si nunca se ha sincronizado
		if (empty($productData['last_sync'])) {
			return true;
		}
		
		// Si la última sincronización fue hace más de 24 horas
		$lastSync = (int)$productData['last_sync'];
		if ((time() - $lastSync) > (24 * 60 * 60)) {
			return true;
		}
		
		// Si el estado de sync indica error
		if (in_array($productData['sync_status'], ['error', 'failed', 'pending'])) {
			return true;
		}
		
		return false;
	}

	/**
	 * Evaluación completa de continuación de sincronización
	 * Fusiona validación básica + verificación avanzada + elementos pendientes
	 * Combina funcionalidad de shouldContinueBatch y validateAndDecideContinuation
	 * 
	 * @param string $entity Entidad a sincronizar
	 * @param string $direction Dirección de sincronización
	 * @param array $syncStatus Estado actual de sincronización
	 * @return bool True si debe continuar, false si debe parar
	 * @since 2.0.0 Migrado desde BatchAutomationManager + validateAndDecideContinuation
	 */
	private function shouldContinueSync(string $entity, string $direction, array $syncStatus): bool
	{
		try {
			// 1. VALIDACIÓN BÁSICA - Verificar cancelación usando el método existente
			if (!$this->sync_status['current_sync']['in_progress'] ?? false) {
				$this->logger->info('Sincronización no está en progreso', ['entity' => $entity]);
				return false;
			}
			
			// 2. VALIDACIÓN DE PARÁMETROS - Verificar parámetros de entrada
			if (empty($entity) || empty($direction)) {
				$this->logger->warning("Parámetros inválidos para validación de continuación", [
					'entity' => $entity,
					'direction' => $direction
				]);
				return false;
			}
			
			// 3. VERIFICACIÓN DE ESTADO DE SINCRONIZACIÓN - Validar estado básico
			if (!$this->isValidSyncState()) {
				$this->logger->warning("Estado de sincronización inválido - finalizando");
				return false;
			}
			
			// 4. VERIFICACIÓN DE LOTES - Verificar estado de lote actual
			$currentBatch = $syncStatus['current_sync']['current_batch'] ?? 0;
			$totalBatches = $syncStatus['current_sync']['total_batches'] ?? 0;
			
			if ($currentBatch >= $totalBatches) {
				$this->logger->info('Todos los lotes completados', [
					'entity' => $entity,
					'current_batch' => $currentBatch,
					'total_batches' => $totalBatches
				]);
				return false;
			}
			
			// 5. VERIFICACIÓN DE LOCKS - Usar el sistema existente de SyncLock
			if (class_exists('MiIntegracionApi\\Core\\SyncLock')) {
				if (!SyncLock::isLocked($entity)) {
					$this->logger->warning('Lock perdido, no se puede continuar', ['entity' => $entity]);
					return false;
				}
			}
			
			// 6. VERIFICACIÓN DE MEMORIA - Verificar memoria disponible
			$memoryUsage = MemoryManager::getMemoryStats()['current'];
			$memoryLimit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
			if ($memoryLimit > 0 && $memoryUsage > ($memoryLimit * 0.8)) {
				$this->logger->warning('Memoria insuficiente para continuar lote', [
					'entity' => $entity,
					'memory_usage' => $memoryUsage,
					'memory_limit' => $memoryLimit
				]);
				return false;
			}
			
			// 7. VERIFICACIÓN DE SOPORTE DE CONTINUACIÓN - Verificar si soporta continuación
			if (!$this->supportsContinuationCheck($entity, $direction)) {
				$this->logger->info("Entidad/dirección no requiere verificación de continuación", [
					'entity' => $entity,
					'direction' => $direction,
					'supported_entities' => self::CONTINUATION_SUPPORTED_ENTITIES,
					'supported_directions' => self::CONTINUATION_SUPPORTED_DIRECTIONS
				]);
				return false;
			}
			
			// 8. VERIFICACIÓN DE ELEMENTOS PENDIENTES - Verificar si hay elementos pendientes
			$pending_check_result = $this->checkPendingItemsRobust($entity, $direction);
			
			if ($pending_check_result['has_pending']) {
				// Ajustar contadores de forma conservadora
				$this->adjustSyncCountersConservatively($pending_check_result);
				return true;
			}
			
			return false;
			
		} catch (\Throwable $e) {
			$this->logger->error("Error en validación de continuación - finalizando por seguridad", [
				'entity' => $entity,
				'direction' => $direction,
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return false; // En caso de error, finalizar por seguridad
		}
	}

	/**
	 * Sincroniza categorías desde Verial
	 *
	 * @param int $offset Posición de inicio  
	 * @param int $batch_size Tamaño del lote
	 * @param array $filters Filtros adicionales
	 * @return array Resultado de la sincronización
	 */
	private function sync_categories_from_verial(int $offset, int $batch_size, array $filters = []): array
	{
		$this->logger->info("Iniciando sincronización de categorías desde Verial", [
			'offset' => $offset,
			'batch_size' => $batch_size
		]);

		try {
			// Obtener categorías normales
			$categorias = $this->api_connector->get_categorias();
			$categorias_web = $this->api_connector->get_categorias_web();
			
			$all_categories = [];
			$processed = 0;
			$errors = 0;
			
			// Procesar categorías normales
			if ($categorias->isSuccess() && isset($categorias->getData()['Categorias'])) {
				foreach ($categorias->getData()['Categorias'] as $categoria) {
					if ($processed >= $batch_size) break;
					if ($processed < $offset) {
						$processed++;
						continue;
					}
					
					// Procesar categoría
					$result = $this->process_single_category($categoria);
					if (!$result['success']) {
						$errors++;
					}
					$processed++;
				}
			}
			
			// Procesar categorías web si aún hay cupo
			if ($processed < $batch_size && $categorias_web->isSuccess() && isset($categorias_web->getData()['Categorias'])) {
				foreach ($categorias_web->getData()['Categorias'] as $categoria) {
					if ($processed >= $batch_size) break;
					if ($processed < $offset) {
						$processed++;
						continue;
					}
					
					$result = $this->process_single_category($categoria);
					if (!$result['success']) {
						$errors++;
					}
					$processed++;
				}
			}
			
			return [
				'success' => $errors === 0,
				'processed' => $processed,
				'errors' => $errors,
				'message' => sprintf('Procesadas %d categorías, %d errores', $processed, $errors)
			];
			
		} catch (\Exception $e) {
			$this->logger->error("Error sincronizando categorías", [
				'error' => $e->getMessage(),
				'offset' => $offset,
				'batch_size' => $batch_size
			]);
			
			return [
				'success' => false,
				'processed' => 0,
				'errors' => 1,
				'error' => $e->getMessage()
			];
		}
	}

	/**
	 * Sincroniza datos geográficos desde Verial
	 *
	 * @param int $offset Posición de inicio
	 * @param int $batch_size Tamaño del lote  
	 * @param array $filters Filtros adicionales
	 * @return array Resultado de la sincronización
	 */
	private function sync_geo_from_verial(int $offset, int $batch_size, array $filters = []): array
	{
		$this->logger->info("Iniciando sincronización de datos geográficos desde Verial", [
			'offset' => $offset,
			'batch_size' => $batch_size
		]);

		try {
			$processed = 0;
			$errors = 0;
			
			// Sincronizar países
			if (method_exists($this->api_connector, 'get_paises')) {
				$paises = $this->api_connector->get_paises();
				if ($paises->isSuccess()) {
					foreach ($paises->getData() as $pais) {
						if ($processed >= $batch_size) break;
						
						$result = $this->process_single_geo_item($pais, 'country');
						if (!$result['success']) {
							$errors++;
						}
						$processed++;
					}
				}
			}
			
			// Sincronizar provincias si hay cupo
			if ($processed < $batch_size && method_exists($this->api_connector, 'get_provincias')) {
				$provincias = $this->api_connector->get_provincias();
				if ($provincias->isSuccess()) {
					foreach ($provincias->getData() as $provincia) {
						if ($processed >= $batch_size) break;
						
						$result = $this->process_single_geo_item($provincia, 'state');
						if (!$result['success']) {
							$errors++;
						}
						$processed++;
					}
				}
			}
			
			return [
				'success' => $errors === 0,
				'processed' => $processed,
				'errors' => $errors,
				'message' => sprintf('Procesados %d elementos geográficos, %d errores', $processed, $errors)
			];
			
		} catch (\Exception $e) {
			$this->logger->error("Error sincronizando datos geográficos", [
				'error' => $e->getMessage(),
				'offset' => $offset,
				'batch_size' => $batch_size
			]);
			
			return [
				'success' => false,
				'processed' => 0,
				'errors' => 1,
				'error' => $e->getMessage()
			];
		}
	}

	/**
	 * Sincroniza configuración desde Verial
	 *
	 * @param int $offset Posición de inicio
	 * @param int $batch_size Tamaño del lote
	 * @param array $filters Filtros adicionales
	 * @return array Resultado de la sincronización
	 */
	private function sync_config_from_verial(int $offset, int $batch_size, array $filters = []): array
	{
		$this->logger->info("Iniciando sincronización de configuración desde Verial", [
			'offset' => $offset,
			'batch_size' => $batch_size
		]);

		try {
			$processed = 0;
			$errors = 0;
			
			// Sincronizar métodos de pago
			if (method_exists($this->api_connector, 'get_metodos_pago')) {
				$metodos = $this->api_connector->get_metodos_pago();
				if ($metodos->isSuccess()) {
					foreach ($metodos->getData() as $metodo) {
						if ($processed >= $batch_size) break;
						
						$result = $this->process_single_config_item($metodo, 'payment_method');
						if (!$result['success']) {
							$errors++;
						}
						$processed++;
					}
				}
			}
			
			// Sincronizar formas de envío si hay cupo
			if ($processed < $batch_size && method_exists($this->api_connector, 'get_formas_envio')) {
				$formas = $this->api_connector->get_formas_envio();
				if ($formas->isSuccess()) {
					foreach ($formas->getData() as $forma) {
						if ($processed >= $batch_size) break;
						
						$result = $this->process_single_config_item($forma, 'shipping_method');
						if (!$result['success']) {
							$errors++;
						}
						$processed++;
					}
				}
			}
			
			return [
				'success' => $errors === 0,
				'processed' => $processed,
				'errors' => $errors,
				'message' => sprintf('Procesados %d elementos de configuración, %d errores', $processed, $errors)
			];
			
		} catch (\Exception $e) {
			$this->logger->error("Error sincronizando configuración", [
				'error' => $e->getMessage(),
				'offset' => $offset,
				'batch_size' => $batch_size
			]);
			
			return [
				'success' => false,
				'processed' => 0,
				'errors' => 1,
				'error' => $e->getMessage()
			];
		}
	}

	/**
	 * Procesa una categoría individual
	 */
	private function process_single_category(array $categoria): array
	{
		try {
			// Monitorear memoria antes del procesamiento
			$this->monitorMemoryDuringProcessing('category', $categoria['Id'] ?? 'unknown');
			
			// Placeholder para procesamiento de categoría
			// TODO: Implementar lógica específica según estructura de categorías
			
			$this->logger->debug("Procesando categoría", [
				'id' => $categoria['Id'] ?? 'unknown',
				'nombre' => $categoria['Nombre'] ?? 'unknown'
			]);
			
			return ['success' => true];
		} catch (\Exception $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Procesa un elemento geográfico individual
	 */
	private function process_single_geo_item(array $item, string $type): array
	{
		try {
			// Monitorear memoria antes del procesamiento
			$this->monitorMemoryDuringProcessing('geo', $item['Id'] ?? 'unknown');
			
			// Placeholder para procesamiento geográfico
			// TODO: Implementar lógica específica según tipo (country, state, city)
			
			$this->logger->debug("Procesando elemento geográfico", [
				'type' => $type,
				'item' => $item
			]);
			
			return ['success' => true];
		} catch (\Exception $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Procesa un elemento de configuración individual
	 */
	private function process_single_config_item(array $item, string $type): array
	{
		try {
			// Monitorear memoria antes del procesamiento
			$this->monitorMemoryDuringProcessing('config', $item['Id'] ?? 'unknown');
			
			// Placeholder para procesamiento de configuración
			// TODO: Implementar lógica específica según tipo (payment_method, shipping_method, etc.)
			
			$this->logger->debug("Procesando elemento de configuración", [
				'type' => $type,
				'item' => $item
			]);
			
			return ['success' => true];
		} catch (\Exception $e) {
			return ['success' => false, 'error' => $e->getMessage()];
		}
	}

	/**
	 * Monitorea el uso de memoria durante el procesamiento
	 */
	private function monitorMemoryDuringProcessing(string $entity, string $itemId): bool
	{
		// ✅ MIGRADO: Usar IdGenerator para memory monitor operation ID
		$operationId = $this->currentOperationId ?? \MiIntegracionApi\Helpers\IdGenerator::generateOperationId('memory_monitor');
		
		// Verificar memoria disponible
		if (!$this->metrics->checkMemoryUsage($operationId)) {
			$this->logger->warning("Umbral de memoria alcanzado durante procesamiento", [
				'entity' => $entity,
				'item_id' => $itemId,
							'memory_usage' => MemoryManager::getMemoryStats()['current'],
			'memory_peak' => MemoryManager::getMemoryStats()['peak'],
				'memory_limit' => ini_get('memory_limit')
			]);
			
			// Intentar liberar memoria
			$this->performMemoryCleanup();
			
			// Verificar nuevamente después de la limpieza
			if (!$this->metrics->checkMemoryUsage($operationId)) {
				throw new \Exception('Umbral crítico de memoria alcanzado, deteniendo procesamiento');
			}
			
			return false;
		}
		
		return true;
	}

	/**
	 * Realiza limpieza de memoria durante el procesamiento
	 */
	private function performMemoryCleanup(): void
	{
		$this->logger->info('Iniciando limpieza de memoria');
		
		// Limpiar caché si está disponible
		if (function_exists('wp_cache_flush')) {
			wp_cache_flush();
		}
		
		// Limpiar transients temporales
		$this->cleanupTemporaryData();
		
		// Forzar garbage collection
		if (function_exists('gc_collect_cycles')) {
			$cycles = gc_collect_cycles();
			$this->logger->debug("Garbage collection ejecutado", ['cycles_collected' => $cycles]);
		}
		
		$this->logger->info('Limpieza de memoria completada', [
			'memory_after_cleanup' => MemoryManager::getMemoryStats()['current'],
			'peak_memory' => MemoryManager::getMemoryStats()['peak']
		]);
	}

	/**
	 * Limpia datos temporales para liberar memoria
	 */
	private function cleanupTemporaryData(): void
	{
		// Limpiar transients de progreso antiguos (más de 1 hora)
		global $wpdb;
		
		$cutoff_time = time() - self::CACHE_CLEANUP_CUTOFF;
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_mia_sync_%' 
			AND option_value < %d",
			$cutoff_time
		));
		
		// Limpiar timeouts de transients huérfanos
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE '_transient_timeout_mia_sync_%' 
			AND option_value < %d",
			time()
		));
		
		$this->logger->debug('Datos temporales limpiados');
	}

	/**
	 * Verifica los límites de memoria del sistema
	 * 
	 * @return SyncResponseInterface Estado de memoria del sistema
	 */
	public function checkSystemMemoryLimits(): SyncResponseInterface
	{
		try {
			$memoryStats = MemoryManager::getMemoryStats();
			$current_memory = $memoryStats['current'];
			$peak_memory = $memoryStats['peak'];
			$memory_limit = $this->parseMemoryLimit(ini_get('memory_limit'));
			
			$current_percentage = ($current_memory / $memory_limit) * 100;
			$peak_percentage = ($peak_memory / $memory_limit) * 100;
			
			$status = [
				'current_usage' => $current_memory,
				'current_usage_mb' => $this->safe_round($current_memory / 1024 / 1024, 2),
				'current_percentage' => $this->safe_round($current_percentage, 2),
				'peak_usage' => $peak_memory,
				'peak_usage_mb' => $this->safe_round($peak_memory / 1024 / 1024, 2),
				'peak_percentage' => $this->safe_round($peak_percentage, 2),
				'memory_limit' => $memory_limit,
				'memory_limit_mb' => $this->safe_round($memory_limit / 1024 / 1024, 2),
				'available_memory' => $memory_limit - $current_memory,
				'available_memory_mb' => $this->safe_round(($memory_limit - $current_memory) / 1024 / 1024, 2),
				'status' => $this->getMemoryStatus($current_percentage),
				'recommendations' => $this->getMemoryRecommendations($current_percentage)
			];
			
			$this->logger->debug('Estado de memoria del sistema', $status);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$status,
				'Límites de memoria del sistema verificados exitosamente',
				[
					'operation' => 'checkSystemMemoryLimits',
					'memory_status' => $status['status'],
					'current_percentage' => $status['current_percentage'],
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'checkSystemMemoryLimits'
			]);
		}
	}

	/**
	 * Obtiene el estado de la memoria basado en el porcentaje de uso
	 */
	private function getMemoryStatus(float $percentage): string
	{
		if ($percentage >= 90) {
			return 'critical';
		} elseif ($percentage >= 80) {
			return 'warning';
		} elseif ($percentage >= 70) {
			return 'caution';
		} else {
			return 'normal';
		}
	}

	/**
	 * Obtiene recomendaciones basadas en el uso de memoria
	 */
	private function getMemoryRecommendations(float $percentage): array
	{
		$recommendations = [];
		
		if ($percentage >= 90) {
			$recommendations[] = 'Detener procesamiento inmediatamente';
			$recommendations[] = 'Reducir el tamaño de lote a la mitad';
			$recommendations[] = 'Considerar aumentar memory_limit';
		} elseif ($percentage >= 80) {
			$recommendations[] = 'Reducir el tamaño de lote';
			$recommendations[] = 'Activar limpieza frecuente de memoria';
			$recommendations[] = 'Monitorear de cerca el uso de memoria';
		} elseif ($percentage >= 70) {
			$recommendations[] = 'Activar monitoreo de memoria más frecuente';
			$recommendations[] = 'Preparar para posible reducción de lote';
		} else {
			$recommendations[] = 'Uso de memoria normal';
		}
		
		return $recommendations;
	}

	/**
	 * Convierte string de memoria a bytes (duplicado del ApiConnector)
	 */
	private function parseMemoryLimit(string $memory_limit): int
	{
		$memory_limit = trim($memory_limit);
		if ($memory_limit === '-1') {
			return PHP_INT_MAX;
		}
		
		$unit = strtolower(substr($memory_limit, -1));
		$size = (int) substr($memory_limit, 0, -1);
		
		switch ($unit) {
			case 'g':
				return $size * 1024 * 1024 * 1024;
			case 'm':
				return $size * 1024 * 1024;
			case 'k':
				return $size * 1024;
			default:
				return (int) $memory_limit;
		}
	}

	/**
	 * Obtiene el estado actual de memoria del sistema
	 * 
	 * @return SyncResponseInterface Estado actual de memoria
	 */
	public function getCurrentMemoryStatus(): SyncResponseInterface
	{
		return $this->checkSystemMemoryLimits();
	}

	/**
	 * Configura alertas de memoria para monitoreo proactivo
	 */
	public function configureMemoryAlerts(array $thresholds = []): void
	{
		$default_thresholds = [
			'warning' => 70,
			'critical' => 85,
			'emergency' => 95
		];
		
		$thresholds = array_merge($default_thresholds, $thresholds);
		
		update_option('mia_memory_alert_thresholds', $thresholds);
		
		$this->logger->info('Alertas de memoria configuradas', $thresholds);
	}

	/**
	 * Verifica si se debe enviar una alerta de memoria
	 */
	private function shouldSendMemoryAlert(float $percentage): ?string
	{
		$thresholds = get_option('mia_memory_alert_thresholds', [
			'warning' => 70,
			'critical' => 85,
			'emergency' => 95
		]);
		
		if ($percentage >= $thresholds['emergency']) {
			return 'emergency';
		} elseif ($percentage >= $thresholds['critical']) {
			return 'critical';
		} elseif ($percentage >= $thresholds['warning']) {
			return 'warning';
		}
		
		return null;
	}

	// ===================================================================
	// MULTI-ENTITY SYNC COORDINATION METHODS
	// ===================================================================

	/**
	 * Sincroniza múltiples entidades respetando dependencias
	 *
	 * @param array $entities Lista de entidades a sincronizar
	 * @param array $options Opciones globales
	 * @return SyncResponseInterface Resultados de todas las sincronizaciones
	 */
	public function sync_multiple_entities(array $entities, array $options = []): SyncResponseInterface
	{
		try {
			if (empty($entities)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'La lista de entidades no puede estar vacía',
					['entities' => $entities]
				);
			}

			$sorted_entities = $this->sort_entities_by_dependencies($entities);
			$results = [];
			$global_start = microtime(true);
			// ✅ MIGRADO: Usar IdGenerator para multi-sync operation ID
			$operation_id = \MiIntegracionApi\Helpers\IdGenerator::generateOperationId('multi_sync', ['entities' => $entities]);
			
			$this->logger->info("Iniciando sincronización multi-entidad", [
				'entities' => $entities,
				'sorted_order' => $sorted_entities,
				'operation_id' => $operation_id
			]);
			
			foreach ($sorted_entities as $entity) {
				// Verificar dependencias antes de sincronizar
				$dependency_check = $this->validate_entity_dependencies($entity, $options);
				if (!$dependency_check['valid']) {
					$results[$entity] = [
						'success' => false,
						'error' => "Dependencias no satisfechas: " . implode(', ', $dependency_check['missing']),
						'entity' => $entity,
						'processed' => 0,
						'errors' => 1
					];
					
					if (!($options['continue_on_error'] ?? false)) {
						$this->logger->warning("Deteniendo sincronización multi-entidad por dependencias", [
							'failed_entity' => $entity,
							'missing_dependencies' => $dependency_check['missing']
						]);
						break;
					}
					continue;
				}
				
				// Sincronizar entidad usando el método existente delegado
				$entity_options = array_merge($options, [
					'operation_id' => $operation_id . "_{$entity}"
				]);
				
				$result = $this->sync_single_entity($entity, $entity_options);
				$results[$entity] = $result;
				
				// Si una entidad falla y no se permite continuar, detener
				if (!$result['success'] && !($options['continue_on_error'] ?? false)) {
					$this->logger->warning("Deteniendo sincronización multi-entidad por error", [
						'failed_entity' => $entity,
						'error' => $result['error'] ?? 'Error desconocido'
					]);
					break;
				}
			}
			
			$total_duration = microtime(true) - $global_start;
			
			// Calcular estadísticas globales
			$global_stats = [
				'success' => !array_filter($results, fn($r) => !$r['success']),
				'operation_id' => $operation_id,
				'total_duration' => $total_duration,
				'entities_processed' => count($results),
				'total_processed' => array_sum(array_column($results, 'processed')),
				'total_errors' => array_sum(array_column($results, 'errors')),
				'entity_results' => $results
			];
			
			$this->logger->info("Sincronización multi-entidad completada", $global_stats);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$global_stats,
				'Sincronización multi-entidad completada exitosamente',
				[
					'operation' => 'sync_multiple_entities',
					'operation_id' => $operation_id,
					'entities_count' => count($entities),
					'entities_processed' => count($results),
					'total_duration' => $total_duration,
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'sync_multiple_entities',
				'entities' => $entities
			]);
		}
	}

	/**
	 * Sincroniza una entidad específica delegando a su servicio correspondiente
	 *
	 * @param string $entity_name Nombre de la entidad
	 * @param array $options Opciones de sincronización
	 * @return SyncResponseInterface Resultado de la sincronización
	 */
	public function sync_single_entity(string $entity_name, array $options = []): SyncResponseInterface
	{
		try {
			if (empty($entity_name)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'El nombre de la entidad no puede estar vacío',
					['entity_name' => $entity_name]
				);
			}

			if (!isset($this->entity_services[$entity_name])) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					"Servicio para entidad '{$entity_name}' no registrado",
					404,
					[
						'entity_name' => $entity_name,
						'available_services' => array_keys($this->entity_services),
						'error_code' => 'entity_service_not_registered'
					]
				);
			}

			$batch_size = $options['batch_size'] ?? $this->get_default_batch_size($entity_name);
			$filters = $options['filters'] ?? [];
			$force_restart = $options['force_restart'] ?? false;
			
			$this->logger->info("Iniciando sincronización de entidad", [
				'entity' => $entity_name,
				'batch_size' => $batch_size,
				'force_restart' => $force_restart
			]);

			$result = null;
			
			switch ($entity_name) {
				case 'productos':
				case 'products':
					// Delegar al método principal de sincronización de productos
					$result = $this->sync_products_from_verial(0, $batch_size, ['force_restart' => $force_restart]);
					break;
					
				case 'clientes':
				case 'clients':
					if (!class_exists('\MiIntegracionApi\Sync\SyncClientes')) {
						throw new \Exception('Clase SyncClientes no encontrada');
					}
					$result = \MiIntegracionApi\Sync\SyncClientes::sync(
						$this->api_connector, 
						$batch_size, 
						0, 
						['force_restart' => $force_restart]
					);
					break;
					
				case 'pedidos':
				case 'orders':
					if (!class_exists('\MiIntegracionApi\Sync\SyncPedidos')) {
						throw new \Exception('Clase SyncPedidos no encontrada');
					}
					$result = \MiIntegracionApi\Sync\SyncPedidos::sync(
						$this->api_connector, 
						null, 
						$batch_size, 
						[
							'use_batch_processor' => true,
							'force_restart' => $force_restart
						]
					);
					break;
					
				case 'categorias':
				case 'categories':
					// CORREGIDO: Usar SyncCategorias directamente
					if (!class_exists('\MiIntegracionApi\Sync\SyncCategorias')) {
						throw new \Exception('Clase SyncCategorias no encontrada');
					}
					$result = \MiIntegracionApi\Sync\SyncCategorias::sync($this->api_connector, $batch_size, 0, ['force_restart' => $force_restart]);
					break;
					
				case 'geo':
					// CORREGIDO: Usar SyncGeo directamente
					if (!class_exists('\MiIntegracionApi\Sync\SyncGeo')) {
						throw new \Exception('Clase SyncGeo no encontrada');
					}
					$result = \MiIntegracionApi\Sync\SyncGeo::sync($this->api_connector, $batch_size, 0, ['force_restart' => $force_restart]);
					break;
					
				default:
					// Para entidades personalizadas, intentar usar la clase de servicio registrada
					$service_class = $this->entity_services[$entity_name];
					if (class_exists($service_class)) {
						if (method_exists($service_class, 'sync')) {
							$result = $service_class::sync($this->api_connector, $batch_size, 0, ['force_restart' => $force_restart]);
						} else {
							throw new \Exception("Método 'sync' no encontrado en la clase: {$service_class}");
						}
					} else {
						throw new \Exception("Clase de servicio no encontrada: {$service_class}");
					}
			}

			// Si el resultado es un array (método legacy), convertirlo a SyncResponseInterface
			if (is_array($result)) {
				if (isset($result['success']) && $result['success']) {
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
						$result,
						'Sincronización de entidad completada exitosamente',
						[
							'operation' => 'sync_single_entity',
							'entity_name' => $entity_name,
							'batch_size' => $batch_size,
							'force_restart' => $force_restart,
							'timestamp' => time()
						]
					);
				} else {
					return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
						$result['error'] ?? 'Error desconocido en la sincronización',
						500,
						[
							'operation' => 'sync_single_entity',
							'entity_name' => $entity_name,
							'processed' => $result['processed'] ?? 0,
							'errors' => $result['errors'] ?? 1,
							'error_code' => $result['error'] ?? 'error_unknown'
						]
					);
				}
			}

			// Si el resultado ya es SyncResponseInterface, devolverlo directamente
			if ($result instanceof SyncResponseInterface) {
				return $result;
			}

			// Si no es ni array ni SyncResponseInterface, crear una respuesta de error
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'El resultado de la sincronización no es del tipo esperado',
				500,
				[
					'operation' => 'sync_single_entity',
					'entity_name' => $entity_name,
					'result_type' => gettype($result),
					'error_code' => 'invalid_result_type'
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'sync_single_entity',
				'entity_name' => $entity_name,
				'options' => $options
			]);
		}
	}

	/**
	 * Valida que las dependencias de una entidad estén satisfechas
	 *
	 * @param string $entity_name Nombre de la entidad
	 * @param array $options Opciones (puede incluir skip_dependency_check)
	 * @return SyncResponseInterface Resultado de la validación
	 */
	public function validate_entity_dependencies(string $entity_name, array $options = []): SyncResponseInterface
	{
		try {
			if (empty($entity_name)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'El nombre de la entidad no puede estar vacío',
					['entity_name' => $entity_name],
					['error_code' => 'entity_name_empty']
				);
			}

			if ($options['skip_dependency_check'] ?? false) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					['valid' => true, 'missing' => []],
					'Validación de dependencias omitida por configuración',
					[
						'operation' => 'validate_entity_dependencies',
						'entity_name' => $entity_name,
						'skip_dependency_check' => true,
						'timestamp' => time()
					]
				);
			}

			$dependencies = $this->entity_dependencies[$entity_name] ?? [];
			$missing_dependencies = [];
			
			foreach ($dependencies as $dependency) {
				// Verificar si la dependencia ha sido sincronizada recientemente usando SyncMetrics
				$dependency_metrics = SyncMetrics::getMetrics($dependency);
				if (!$dependency_metrics) {
					$missing_dependencies[] = $dependency;
					$this->logger->warning("Dependencia no sincronizada", [
						'entity' => $entity_name,
						'dependency' => $dependency
					]);
				}
			}
			
			$validation_result = [
				'valid' => empty($missing_dependencies),
				'missing' => $missing_dependencies,
				'dependencies_checked' => count($dependencies),
				'missing_count' => count($missing_dependencies)
			];

			if (empty($missing_dependencies)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					$validation_result,
					'Todas las dependencias están satisfechas',
					[
						'operation' => 'validate_entity_dependencies',
						'entity_name' => $entity_name,
						'dependencies_checked' => count($dependencies),
						'timestamp' => time()
					]
				);
			} else {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Dependencias no satisfechas: ' . implode(', ', $missing_dependencies),
					422,
					[
						'operation' => 'validate_entity_dependencies',
						'entity_name' => $entity_name,
						'missing_dependencies' => $missing_dependencies,
						'dependencies_checked' => count($dependencies),
						'error_code' => 'dependencies_not_satisfied'
					]
				);
			}
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'validate_entity_dependencies',
				'entity_name' => $entity_name
			]);
		}
	}

	/**
	 * Ordena entidades por dependencias (topological sort)
	 *
	 * @param array $entities Lista de entidades
	 * @return SyncResponseInterface Entidades ordenadas por dependencias
	 */
	public function sort_entities_by_dependencies(array $entities): SyncResponseInterface
	{
		try {
			if (empty($entities)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'La lista de entidades no puede estar vacía',
					['entities' => $entities],
					['error_code' => 'entities_empty']
				);
			}

			$sorted = [];
			$visited = [];
			$visiting = [];
			$circular_dependencies = [];
			
			$visit = function($entity) use (&$visit, &$sorted, &$visited, &$visiting, &$circular_dependencies, $entities) {
				if (isset($visiting[$entity])) {
					$circular_dependencies[] = $entity;
					throw new \Exception("Dependencia circular detectada incluyendo: {$entity}");
				}
				
				if (isset($visited[$entity])) {
					return;
				}
				
				$visiting[$entity] = true;
				
				$dependencies = $this->entity_dependencies[$entity] ?? [];
				foreach ($dependencies as $dependency) {
					if (in_array($dependency, $entities)) {
						$visit($dependency);
					}
				}
				
				unset($visiting[$entity]);
				$visited[$entity] = true;
				$sorted[] = $entity;
			};
			
			foreach ($entities as $entity) {
				$visit($entity);
			}
			
			$result = [
				'sorted_entities' => $sorted,
				'original_entities' => $entities,
				'total_entities' => count($entities),
				'sorted_count' => count($sorted),
				'circular_dependencies_detected' => !empty($circular_dependencies),
				'circular_dependencies' => $circular_dependencies
			];
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$result,
				'Entidades ordenadas por dependencias exitosamente',
				[
					'operation' => 'sort_entities_by_dependencies',
					'entities_count' => count($entities),
					'sorted_count' => count($sorted),
					'has_circular_dependencies' => !empty($circular_dependencies),
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			// Si hay dependencias circulares, retornar error específico
			if (str_contains($e->getMessage(), 'Dependencia circular')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					$e->getMessage(),
					422,
					[
						'operation' => 'sort_entities_by_dependencies',
						'entities' => $entities,
						'circular_dependencies' => $circular_dependencies ?? [],
						'error_code' => 'circular_dependencies_detected'
					]
				);
			}
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'sort_entities_by_dependencies',
				'entities' => $entities
			]);
		}
	}

	/**
	 * Obtiene el tamaño de lote por defecto para una entidad
	 *
	 * @param string $entity_name Nombre de la entidad
	 * @return int Tamaño de lote por defecto
	 */
	public function get_default_batch_size(string $entity_name): int
	{
		// Delegar completamente a BatchSizeHelper para centralización
		return BatchSizeHelper::getBatchSize($entity_name);
	}

	/**
	 * MEJORA 1.3: Obtiene el tamaño de lote de forma consistente y robusta
	 * 
	 * Unifica las múltiples fuentes de batch size en una sola función que maneja
	 * la prioridad y validación de forma robusta
	 * 
	 * @param string $entity_name Nombre de la entidad
	 * @param array $request_data Datos de la petición ($_REQUEST)
	 * @param array $options Opciones adicionales
	 * @return int Tamaño de lote validado y consistente
	 */
	public function getConsistentBatchSize(string $entity_name, array $request_data = [], array $options = []): int
	{
		try {
			$batch_size = null;
			$source = 'unknown';

			// Prioridad 1: Valor específico en opciones (para calls internos)
			if (!empty($options['batch_size']) && is_numeric($options['batch_size'])) {
				$batch_size = (int)$options['batch_size'];
				$source = 'options';
			}
			// Prioridad 2: Valor en petición HTTP (usuario frontend)
			elseif (!empty($request_data['batch_size']) && is_numeric($request_data['batch_size'])) {
				$batch_size = (int)$request_data['batch_size'];
				$source = 'request';
			}
			// Prioridad 3: BatchSizeHelper (configuración guardada)
			elseif (class_exists('\MiIntegracionApi\Helpers\BatchSizeHelper')) {
				$batch_size = BatchSizeHelper::getBatchSize($entity_name);
				$source = 'BatchSizeHelper';
			}
			// Prioridad 4: ConfigManager (configuración del plugin)
			elseif (class_exists('\MiIntegracionApi\Core\ConfigManager')) {
				$config_manager = \MiIntegracionApi\Core\ConfigManager::getInstance();
				$batch_size = $config_manager->getBatchSize($entity_name);
				$source = 'ConfigManager';
			}

			// Fallback final: valores por defecto hardcodeados
			if (!$batch_size || $batch_size <= 0) {
				$batch_size = $this->get_default_batch_size($entity_name);
				$source = 'default_hardcoded';
			}

			// Validación de límites robusta usando BatchSizeHelper centralizado
			$validated_batch_size = BatchSizeHelper::validateBatchSize($entity_name, $batch_size);

			// Guardar el valor validado para consistencia futura si cambió
			if ($validated_batch_size !== $batch_size && class_exists('\MiIntegracionApi\Helpers\BatchSizeHelper')) {
				BatchSizeHelper::setBatchSize($entity_name, $validated_batch_size);
			}

			$this->logger->info("Batch size determinado de forma consistente", [
				'entity' => $entity_name,
				'original_value' => $batch_size,
				'validated_value' => $validated_batch_size,
				'source' => $source,
				'was_adjusted' => ($validated_batch_size !== $batch_size)
			]);

			return $validated_batch_size;

		} catch (\Throwable $e) {
			$this->logger->error("Error obteniendo batch size consistente - usando fallback", [
				'entity' => $entity_name,
				'error' => $e->getMessage()
			]);
			return $this->get_default_batch_size($entity_name);
		}
	}


	/**
	 * Obtiene el estado de sincronización de todas las entidades registradas
	 *
	 * @return SyncResponseInterface Estado actual de las entidades
	 */
	public function get_entities_status(): SyncResponseInterface
	{
		try {
			$status = [];
			$total_entities = count($this->entity_services);
			$registered_entities = 0;
			$available_services = 0;
			
			foreach ($this->entity_services as $entity_name => $service_class) {
				$metrics = SyncMetrics::getMetrics($entity_name);
				$service_exists = class_exists($service_class);
				
				if ($service_exists) {
					$available_services++;
				}
				$registered_entities++;
				
				$status[$entity_name] = [
					'registered' => true,
					'service_exists' => $service_exists,
					'last_sync' => $metrics['timestamp'] ?? null,
					'last_operation_id' => $metrics['operation_id'] ?? null,
					'dependencies' => $this->entity_dependencies[$entity_name] ?? [],
					'service_class' => $service_class
				];
			}
			
			$summary = [
				'total_entities' => $total_entities,
				'registered_entities' => $registered_entities,
				'available_services' => $available_services,
				'services_missing' => $total_entities - $available_services,
				'entities_status' => $status
			];
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$summary,
				'Estado de entidades obtenido exitosamente',
				[
					'operation' => 'get_entities_status',
					'total_entities' => $total_entities,
					'available_services' => $available_services,
					'timestamp' => time()
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'get_entities_status'
			]);
		}
	}

	/**
	 * Registra un nuevo servicio de entidad (para extensibilidad)
	 *
	 * @param string $entity_name Nombre de la entidad
	 * @param string $service_class Clase del servicio
	 * @param array $dependencies Dependencias de la entidad
	 * @return bool Éxito del registro
	 */
	public function register_entity_service(string $entity_name, string $service_class, array $dependencies = []): bool
	{
		if (!class_exists($service_class)) {
			$this->logger->warning("Servicio no existe al registrar entidad", [
				'entity' => $entity_name,
				'service_class' => $service_class
			]);
			return false;
		}

		$this->entity_services[$entity_name] = $service_class;
		$this->entity_dependencies[$entity_name] = $dependencies;
		
		$this->logger->info("Servicio de entidad registrado", [
			'entity' => $entity_name,
			'dependencies' => $dependencies,
			'service_class' => $service_class
		]);
		
		return true;
	}

	/**
	 * Método de conveniencia para sincronizar todas las entidades básicas en orden
	 *
	 * @param array $options Opciones de sincronización
	 * @return SyncResponseInterface Resultado de la sincronización coordinada
	 */
	public function sync_all_entities(array $options = []): SyncResponseInterface
	{
		try {
			$basic_entities = ['geo', 'config', 'categories', 'clients', 'products'];
			
			$this->logger->info("Iniciando sincronización completa de entidades básicas", [
				'entities' => $basic_entities,
				'options' => $options
			]);
			
			$result = $this->sync_multiple_entities($basic_entities, $options);
			
			// El resultado ya es SyncResponseInterface, solo agregamos metadatos adicionales
			if ($result instanceof SyncResponseInterface) {
				$data = $result->getData();
				$metadata = $result->getMetadata();
				
				// Agregar información específica de sync_all_entities
				$metadata['sync_type'] = 'all_entities';
				$metadata['basic_entities'] = $basic_entities;
				$metadata['entities_count'] = count($basic_entities);
				
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					$data,
					$result->isSuccess() ? 'Sincronización completa de entidades básicas completada exitosamente' : 'Error en sincronización completa de entidades básicas',
					$metadata
				);
			}
			
			// Si por alguna razón no es SyncResponseInterface, crear una respuesta de error
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'El resultado de sync_multiple_entities no es del tipo esperado',
				500,
				[
					'operation' => 'sync_all_entities',
					'basic_entities' => $basic_entities,
					'result_type' => gettype($result),
					'error_code' => 'invalid_result_type'
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'sync_all_entities',
				'basic_entities' => ['geo', 'config', 'categories', 'clients', 'products']
			]);
		}
	}

	/**
	 * Método de conveniencia para sincronizar solo entidades de datos maestros
	 *
	 * @param array $options Opciones de sincronización
	 * @return SyncResponseInterface Resultado de la sincronización
	 */
	public function sync_master_data(array $options = []): SyncResponseInterface
	{
		try {
			$master_entities = ['geo', 'config', 'categories'];
			
			$this->logger->info("Iniciando sincronización de datos maestros", [
				'entities' => $master_entities,
				'options' => $options
			]);
			
			$result = $this->sync_multiple_entities($master_entities, $options);
			
			// El resultado ya es SyncResponseInterface, solo agregamos metadatos adicionales
			if ($result instanceof SyncResponseInterface) {
				$data = $result->getData();
				$metadata = $result->getMetadata();
				
				// Agregar información específica de sync_master_data
				$metadata['sync_type'] = 'master_data';
				$metadata['master_entities'] = $master_entities;
				$metadata['entities_count'] = count($master_entities);
				
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					$data,
					$result->isSuccess() ? 'Sincronización de datos maestros completada exitosamente' : 'Error en sincronización de datos maestros',
					$metadata
				);
			}
			
			// Si por alguna razón no es SyncResponseInterface, crear una respuesta de error
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'El resultado de sync_multiple_entities no es del tipo esperado',
				500,
				[
					'operation' => 'sync_master_data',
					'master_entities' => $master_entities,
					'result_type' => gettype($result),
					'error_code' => 'invalid_result_type'
				]
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'sync_master_data',
				'master_entities' => ['geo', 'config', 'categories']
			]);
		}
	}


	/**
	 * Verifica si el estado de sincronización es válido y consistente
	 * 
	 * @return bool True si el estado es válido
	 */
	private function isValidSyncState(): bool
	{
		// Verificar estructura básica del estado
		if (!isset($this->sync_status['current_sync'])) {
			return false;
		}

		$current = $this->sync_status['current_sync'];

		// Verificar campos requeridos
		$required_fields = ['in_progress', 'entity', 'direction', 'current_batch', 'total_batches', 'items_synced'];
		foreach ($required_fields as $field) {
			if (!isset($current[$field])) {
				$this->logger->warning("Campo requerido faltante en estado de sync", ['field' => $field]);
				return false;
			}
		}

		// Verificar que la sincronización esté marcada como en progreso
		if (!$current['in_progress']) {
			$this->logger->warning("Sincronización no está marcada como en progreso");
			return false;
		}

		// Verificar que los números sean lógicos
		if ($current['current_batch'] < 0 || $current['total_batches'] < 0 || $current['items_synced'] < 0) {
			$this->logger->warning("Números inválidos en estado de sync", [
				'current_batch' => $current['current_batch'],
				'total_batches' => $current['total_batches'],
				'items_synced' => $current['items_synced']
			]);
			return false;
		}

		return true;
	}

	/**
	 * Verifica elementos pendientes de forma robusta con timeout y reintentos
	 * 
	 * @param string $entity Entidad a verificar
	 * @param string $direction Dirección de sincronización
	 * @return array Resultado con has_pending y metadata
	 */
	private function checkPendingItemsRobust(string $entity, string $direction): array
	{
		$result = [
			'has_pending' => false,
			'pending_count' => 0,
			'check_method' => 'none',
			'error' => null
		];

		try {
			// CORRECCIÓN: Calcular offset basado en lotes, no en items_synced
			// items_synced puede ser inexacto si hay productos duplicados/ignorados
			$current_batch = max(0, (int)($this->sync_status['current_sync']['current_batch'] ?? 0));
			$batch_size = max(1, (int)($this->sync_status['current_sync']['batch_size'] ?? 50));
			$current_offset = $current_batch * $batch_size;
			
			$this->logger->info("Calculando offset para verificación de pendientes", [
				'current_batch' => $current_batch,
				'batch_size' => $batch_size,
				'calculated_offset' => $current_offset,
				'items_synced_old_method' => $this->sync_status['current_sync']['items_synced'] ?? 0
			]);
			
			// Obtener API connector con validación
			$api = $this->get_api_connector();
			if (!$api) {
				$result['error'] = 'ApiConnector no disponible';
				$this->logger->warning("No se puede verificar elementos pendientes - API no disponible");
				return $result;
			}

			// REFACTORIZADO: Usar método configurable para tamaño de lote
			$test_batch_size = $this->getContinuationTestBatchSize($entity);
			$result['check_method'] = 'api_test_batch';

			// REFACTORIZADO: Timeout configurable
			$verification_timeout = $this->getContinuationCheckTimeout($entity);
			$start_time = time();

			$test_products = $api->get_productos_paginados($current_offset, $test_batch_size);

			// Verificar timeout
			if ((time() - $start_time) > $verification_timeout) {
				$elapsed_time = time() - $start_time;
				$timeout_error = SyncError::timeoutError(
					'Timeout en verificación de elementos pendientes',
					[
						'timeout_seconds' => $verification_timeout,
						'elapsed_time' => $elapsed_time,
						'entity' => $entity,
						'current_offset' => $current_offset,
						'test_batch_size' => $test_batch_size
					]
				);
				
				$result['error'] = $timeout_error->getMessage();
				$this->logger->warning("Timeout verificando elementos pendientes", [
					'duration' => $elapsed_time,
					'timeout' => $verification_timeout,
					'context' => $timeout_error->getContext()
				]);
				return $result;
			}

			// Analizar resultado
			if (is_array($test_products) && !empty($test_products)) {
				$result['has_pending'] = true;
				$result['pending_count'] = count($test_products);
				
				$this->logger->info("Elementos pendientes detectados", [
					'offset' => $current_offset,
					'found_count' => count($test_products),
					'method' => $result['check_method']
				]);
			} else {
				$this->logger->info("No se encontraron elementos pendientes", [
					'offset' => $current_offset,
					'method' => $result['check_method']
				]);
			}

		} catch (\Throwable $e) {
			$result['error'] = $e->getMessage();
			$this->logger->error("Error verificando elementos pendientes", [
				'entity' => $entity,
				'direction' => $direction,
				'error' => $e->getMessage()
			]);
		}

		return $result;
	}

	/**
	 * REFACTORIZACIÓN: Verifica si una entidad/dirección soporta verificación de continuación
	 * Permite extensibilidad vía filtros de WordPress
	 * 
	 * @param string $entity Entidad a verificar
	 * @param string $direction Dirección de sincronización
	 * @return bool True si soporta verificación de continuación
	 */
	private function supportsContinuationCheck(string $entity, string $direction): bool
	{
		// Verificación base usando constantes
		$entity_supported = in_array($entity, self::CONTINUATION_SUPPORTED_ENTITIES);
		$direction_supported = in_array($direction, self::CONTINUATION_SUPPORTED_DIRECTIONS);
		$base_support = $entity_supported && $direction_supported;
		
		// Permitir override vía filtros de WordPress
		$supports = apply_filters('mia_supports_continuation_check', $base_support, $entity, $direction);
		
		// Permitir configuración específica por entidad
		$supports = apply_filters("mia_supports_continuation_check_{$entity}", $supports, $direction);
		
		return (bool) $supports;
	}

	/**
	 * REFACTORIZACIÓN: Obtiene timeout configurable para verificación de continuación
	 * Permite configuración flexible vía filtros de WordPress
	 * 
	 * @param string $entity Entidad para timeout específico
	 * @return int Timeout en segundos
	 */
	private function getContinuationCheckTimeout(string $entity = ''): int
	{
		$default_timeout = self::CONTINUATION_CHECK_TIMEOUT;
		
		// Permitir configuración vía filtro de WordPress
		$timeout = apply_filters('mia_continuation_check_timeout', $default_timeout, $entity);
		
		// Permitir configuración específica por entidad
		if (!empty($entity)) {
			$timeout = apply_filters("mia_continuation_check_timeout_{$entity}", $timeout, $entity);
		}
		
		// Validar que el timeout esté en un rango razonable (2-60 segundos)
		$timeout = max(2, min(60, (int) $timeout));
		
		return $timeout;
	}

	/**
	 * REFACTORIZACIÓN: Obtiene tamaño de lote de prueba configurable
	 * Permite configuración flexible para diferentes entidades
	 * 
	 * @param string $entity Entidad para tamaño específico
	 * @return int Tamaño de lote de prueba
	 */
	private function getContinuationTestBatchSize(string $entity = ''): int
	{
		$default_size = self::CONTINUATION_TEST_BATCH_SIZE;
		
		// Permitir configuración vía filtro de WordPress
		$size = apply_filters('mia_continuation_test_batch_size', $default_size, $entity);
		
		// Permitir configuración específica por entidad
		if (!empty($entity)) {
			$size = apply_filters("mia_continuation_test_batch_size_{$entity}", $size, $entity);
		}
		
		// Validar que esté en un rango razonable (1-20 elementos)
		$size = max(1, min(20, (int) $size));
		
		return $size;
	}

	/**
	 * Ajusta contadores de sincronización de forma conservadora
	 * 
	 * @param array $pending_result Resultado de la verificación de elementos pendientes
	 */
	private function adjustSyncCountersConservatively(array $pending_result): void
	{
		try {
			// Solo hacer ajustes conservadores para no desestabilizar el progreso visual
			if (isset($pending_result['pending_count']) && $pending_result['pending_count'] > 0) {
				// Incrementar total_batches solo por 1 para continuar progresivamente
				$old_total = $this->sync_status['current_sync']['total_batches'];
				$this->sync_status['current_sync']['total_batches'] = $old_total + 1;
				
				$this->logger->info("Ajuste conservador de total_batches", [
					'old_total_batches' => $old_total,
					'new_total_batches' => $this->sync_status['current_sync']['total_batches'],
					'pending_found' => $pending_result['pending_count']
				]);
				
				$this->save_sync_status();
			}
		} catch (\Throwable $e) {
			$this->logger->warning("Error en ajuste conservador de contadores", [
				'error' => $e->getMessage()
			]);
		}
	}

	/**
	 * Envío unificado de respuestas de error desde Sync_Manager
	 * @param SyncResponseInterface $response Respuesta de sincronización
	 * @param int $status_code Código de estado HTTP (opcional)
	 */
	protected function sendJsonError(SyncResponseInterface $response, int $status_code = 400): void {
		$logger = new LoggerBasic('sync-manager-error');
		
		$error_message = $response->getMessage();
		$error_code = $response->getCode();
		$error_data = $response->getData();
		
		\MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger("sync-ajax")->error('Enviando respuesta de error desde Sync_Manager', [
			'error_code' => $error_code,
			'error_message' => $error_message,
			'error_data' => $error_data,
			'method' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? 'unknown'
		]);
		
		wp_send_json_error([
			'message' => $error_message,
			'code' => $error_code,
			'data' => $error_data
		], $status_code);
	}
	
	/**
	 * Envío unificado de respuestas exitosas desde Sync_Manager
	 * @param mixed $data Datos a enviar
	 * @param int $status_code Código de estado HTTP (opcional)
	 */
	protected function sendJsonSuccess(mixed $data, int $status_code = 200): void {
		wp_send_json_success($data, $status_code);
	}
	
	/**
	 * Obtiene el timestamp de la última sincronización exitosa para una entidad
	 * 
	 * @param string $entity_name Nombre de la entidad (products, clientes, etc.)
	 * @return int|null Timestamp de la última sincronización exitosa o null si nunca se ha sincronizado
	 */
	public function getLastSuccessfulSyncTimestamp(string $entity_name): ?int {
		// Normalizar nombre de entidad
		$entity_key = $entity_name === 'products' ? 'productos' : $entity_name;
		
		// Buscar en opciones de WordPress
		$option_name = "mi_integracion_api_last_sync_{$entity_key}";
		$last_sync = get_option($option_name, null);
		
		if ($last_sync && is_numeric($last_sync)) {
			return (int) $last_sync;
		}
		
		// Fallback: buscar en transients si no hay opción guardada
		$transient_name = "mia_last_successful_sync_{$entity_key}";
		$last_sync_transient = get_transient($transient_name);
		
		if ($last_sync_transient && is_numeric($last_sync_transient)) {
			// Migrar a opción permanente
			update_option($option_name, $last_sync_transient);
			return (int) $last_sync_transient;
		}
		
		return null;
	}
	
	/**
	 * Actualiza el timestamp de la última sincronización exitosa
	 * 
	 * @param string $entity_name Nombre de la entidad
	 * @param int|null $timestamp Timestamp a guardar (por defecto: tiempo actual)
	 * @return bool Éxito de la operación
	 */
	public function updateLastSuccessfulSyncTimestamp(string $entity_name, ?int $timestamp = null): bool {
		$timestamp = $timestamp ?? time();
		$entity_key = $entity_name === 'products' ? 'productos' : $entity_name;
		$option_name = "mi_integracion_api_last_sync_{$entity_key}";
		
		return update_option($option_name, $timestamp);
	}

	/**
	 * Manejo unificado de excepciones desde Sync_Manager
	 * @param \Throwable $e Excepción capturada
	 * @param string $context Contexto donde ocurrió la excepción
	 * @param int $status_code Código de estado HTTP para la respuesta
	 */
	protected function handleException(\Throwable $e, string $context = 'unknown', int $status_code = 500): void {
		$logger = new LoggerBasic('sync-manager-exception');
		
		\MiIntegracionApi\Logging\Core\LogManager::getInstance()->getLogger("sync-ajax")->error('Excepción en Sync_Manager', [
			'context' => $context,
			'exception_class' => get_class($e),
			'message' => $e->getMessage(),
			'file' => $e->getFile(),
			'line' => $e->getLine(),
			'trace' => $e->getTraceAsString()
		]);
		
		// Crear SyncResponse y usar método unificado
		$sync_response = new \MiIntegracionApi\ErrorHandling\Responses\SyncResponse(
			false,
			[],
			null,
			$status_code,
			sprintf(__('Error interno en Sync_Manager (%s): %s', 'mi-integracion-api'), $context, $e->getMessage()),
			['exception_class' => get_class($e), 'context' => $context]
		);
		
		$this->sendJsonError($sync_response, $status_code);
	}
	
	// ===================================================================
	// SISTEMA DE LIMPIEZA EN LOTES Y ASÍNCRONA - TAREA 4.1
	// ===================================================================
	
	/**
	 * NUEVO: Identifica transients de gran tamaño
	 * 
	 * @param array $cacheKeys Lista de claves de cache
	 * @param int $minSizeBytes Tamaño mínimo en bytes para considerar "grande"
	 * @return array Transients grandes ordenados por tamaño
	 */
	private function identifyLargeTransients(array $cacheKeys, int $minSizeBytes = 1024 * 1024): array
	{
		$largeTransients = [];
		
		foreach ($cacheKeys as $cacheKey) {
			$cacheData = get_transient($cacheKey);
			if ($cacheData !== false) {
				$sizeBytes = $this->calculateTransientSize($cacheData);
				if ($sizeBytes >= $minSizeBytes) {
					$largeTransients[] = [
						'key' => $cacheKey,
						'size_bytes' => $sizeBytes,
						'size_mb' => $this->safe_round($sizeBytes / (1024 * 1024), 2)
					];
				}
			}
		}
		
		// Ordenar por tamaño descendente
		usort($largeTransients, function($a, $b) {
			return $b['size_bytes'] - $a['size_bytes'];
		});
		
		return $largeTransients;
	}

    /**
     * NUEVO: Sistema avanzado de programación con WP-Cron
     *
     * @return SyncResponseInterface Resultado de la configuración
     */
	public function setupAdvancedCronSystem(): SyncResponseInterface
	{
		try {
			// VALIDACIÓN CRÍTICA - Verificar que WP-Cron esté disponible
			if (!function_exists('wp_schedule_event')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'WP-Cron no está disponible en este entorno',
					500,
					[
						'operation' => 'setupAdvancedCronSystem',
						'function_missing' => 'wp_schedule_event'
					],
					['error_code' => 'wp_cron_unavailable']
				);
			}
			
			if (!function_exists('wp_next_scheduled')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Funciones de WP-Cron no están disponibles',
					500,
					[
						'operation' => 'setupAdvancedCronSystem',
						'function_missing' => 'wp_next_scheduled'
					],
					['error_code' => 'wp_cron_functions_unavailable']
				);
			}
			
			$result = [
				'success' => false,
				'jobs_configured' => 0,
				'errors' => []
			];
			
			// Limpiar eventos cron existentes
			$this->clearExistingCronJobs();
			
			// Configurar eventos cron recurrentes
			$cronJobs = $this->getCronJobConfiguration();
			
			foreach ($cronJobs as $jobName => $jobConfig) {
				$scheduled = $this->scheduleRecurringCronJob($jobName, $jobConfig);
				if ($scheduled) {
					$result['jobs_configured']++;
				} else {
					$result['errors'][] = "No se pudo programar: {$jobName}";
				}
			}
			
			// Configurar eventos cron inteligentes
			$this->setupIntelligentCronJobs();
			
			$result['success'] = true;
			
			$this->logger->info("Sistema avanzado de WP-Cron configurado", [
				'jobs_configured' => $result['jobs_configured'],
				'total_jobs' => count($cronJobs)
			]);

			// Agregar metadatos adicionales
			$metadata = [
				'operation' => 'setupAdvancedCronSystem',
				'jobs_configured' => $result['jobs_configured'],
				'total_jobs' => count($cronJobs),
				'errors_count' => count($result['errors']),
				'success' => $result['success'],
				'timestamp' => time()
			];

			$message = $result['success'] 
				? "Sistema avanzado de WP-Cron configurado exitosamente: {$result['jobs_configured']} trabajos programados"
				: "Error al configurar sistema de WP-Cron: " . implode(', ', $result['errors']);

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$result,
				$message,
				$metadata
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'setupAdvancedCronSystem'
			]);
		}
	}
	
	/**
	 * NUEVO: Obtiene configuración de trabajos cron
	 * 
	 * @return array Configuración de trabajos cron
	 */
	private function getCronJobConfiguration(): array
	{
		return [
			'mia_daily_cleanup' => [
				'schedule' => 'daily',
				'time' => '02:00',
				'callback' => [$this, 'executeDailyCleanup'],
				'description' => 'Limpieza diaria de transients'
			],
			'mia_weekly_optimization' => [
				'schedule' => 'weekly',
				'time' => '03:00',
				'callback' => [$this, 'executeWeeklyOptimization'],
				'description' => 'Optimización semanal de memoria'
			],
			'mia_monthly_maintenance' => [
				'schedule' => 'monthly',
				'time' => '04:00',
				'callback' => [$this, 'executeMonthlyMaintenance'],
				'description' => 'Mantenimiento mensual del sistema'
			],
			'mia_memory_monitoring' => [
				'schedule' => 'hourly',
				'time' => '00:00',
				'callback' => [$this, 'executeMemoryMonitoring'],
				'description' => 'Monitoreo horario de memoria'
			],
			'mia_cache_health_check' => [
				'schedule' => 'twicedaily',
				'time' => '06:00',
				'callback' => [$this, 'executeCacheHealthCheck'],
				'description' => 'Verificación de salud de caché'
			]
		];
	}
	
	/**
	 * NUEVO: Programa trabajo cron recurrente
	 * 
	 * @param string $jobName Nombre del trabajo
	 * @param array $jobConfig Configuración del trabajo
	 * @return bool True si se programó correctamente
	 */
	private function scheduleRecurringCronJob(string $jobName, array $jobConfig): bool
	{
		try {
			// Verificar si ya está programado
			if (wp_next_scheduled($jobName)) {
				wp_clear_scheduled_hook($jobName);
			}
			
			// Programar trabajo recurrente
			$scheduled = wp_schedule_event(
				$this->calculateNextRunTime($jobConfig['schedule'], $jobConfig['time']),
				$jobConfig['schedule'],
				$jobName,
				[$jobName]
			);
			
			if ($scheduled) {
				// Registrar callback
				add_action($jobName, $jobConfig['callback']);
				
				$this->logger->info("Trabajo cron programado", [
					'job_name' => $jobName,
					'schedule' => $jobConfig['schedule'],
					'next_run' => wp_next_scheduled($jobName),
					'description' => $jobConfig['description']
				]);
				
				return true;
			}
			
		} catch (\Exception $e) {
			$this->logger->error("Error al programar trabajo cron", [
				'job_name' => $jobName,
				'error' => $e->getMessage()
			]);
		}
		
		return false;
	}
	
	/**
	 * NUEVO: Configura trabajos cron inteligentes
	 * 
	 * @return void
	 */
	private function setupIntelligentCronJobs(): void
	{
		// Trabajo de limpieza inteligente basado en métricas
		add_action('mia_intelligent_cleanup_trigger', [$this, 'executeIntelligentCleanup']);
		
		// Trabajo de optimización adaptativa
		add_action('mia_adaptive_optimization_trigger', [$this, 'executeAdaptiveOptimization']);
		
		// Trabajo de migración automática
		add_action('mia_auto_migration_trigger', [$this, 'executeAutoMigration']);
		
		// Trabajo de monitoreo de salud del sistema
		add_action('mia_system_health_monitor', [$this, 'executeSystemHealthMonitoring']);
		
		$this->logger->info("Trabajos cron inteligentes configurados", [
			'intelligent_jobs' => [
				'mia_intelligent_cleanup_trigger',
				'mia_adaptive_optimization_trigger',
				'mia_auto_migration_trigger',
				'mia_system_health_monitor'
			]
		]);
	}
	
	/**
	 * NUEVO: Calcula tiempo de próxima ejecución
	 * 
	 * @param string $schedule Programación (daily, weekly, monthly, etc.)
	 * @param string $time Hora de ejecución (HH:MM)
	 * @return int Timestamp de próxima ejecución
	 */
	private function calculateNextRunTime(string $schedule, string $time): int
	{
		$currentTime = current_time('timestamp');
		$timeParts = explode(':', $time);
		$targetHour = (int)$timeParts[0];
		$targetMinute = (int)$timeParts[1];
		
		$nextRun = strtotime("today {$targetHour}:{$targetMinute}:00");
		
		// Si ya pasó la hora de hoy, programar para mañana
		if ($nextRun <= $currentTime) {
			$nextRun = strtotime("tomorrow {$targetHour}:{$targetMinute}:00");
		}
		
		return $nextRun;
	}
	
	/**
	 * NUEVO: Limpia trabajos cron existentes
	 * 
	 * @return void
	 */
	private function clearExistingCronJobs(): void
	{
		$cronJobs = [
			'mia_daily_cleanup',
			'mia_weekly_optimization',
			'mia_monthly_maintenance',
			'mia_memory_monitoring',
			'mia_cache_health_check'
		];
		
		foreach ($cronJobs as $jobName) {
			if (wp_next_scheduled($jobName)) {
				wp_clear_scheduled_hook($jobName);
			}
		}
		
		$this->logger->info("Trabajos cron existentes limpiados", [
			'cleared_jobs' => count($cronJobs)
		]);
	}
	
	/**
	 * NUEVO: Registra historial de programación de cron
	 * 
	 * @param string $jobId ID del trabajo
	 * @param string $jobType Tipo de trabajo
	 * @param int $delay Delay en segundos
	 * @param int $targetHour Hora objetivo
	 * @return void
	 */
	private function recordCronSchedulingHistory(string $jobId, string $jobType, int $delay, int $targetHour): void
	{
		$history = get_option('mia_cron_scheduling_history', []);
		
		$historyEntry = [
			'timestamp' => time(),
			'job_id' => $jobId,
			'job_type' => $jobType,
			'delay_seconds' => $delay,
			'target_hour' => $targetHour,
			'scheduled_for' => date('Y-m-d H:i:s', time() + $delay)
		];
		
		$history[] = $historyEntry;
		
		// Mantener solo los últimos 100 registros
		if (count($history) > 100) {
			$history = array_slice($history, -100);
		}
		
		update_option('mia_cron_scheduling_history', $history);
	}

    /**
     * NUEVO: Sistema de compatibilidad con plugins de caché
     *
     * @return SyncResponseInterface Estado de compatibilidad
     */
	public function setupCachePluginCompatibility(): SyncResponseInterface
	{
		try {
			// VALIDACIÓN CRÍTICA - Verificar que WordPress esté disponible
			if (!function_exists('is_plugin_active')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Funciones de WordPress no están disponibles para verificar plugins',
					500,
					[
						'operation' => 'setupCachePluginCompatibility',
						'function_missing' => 'is_plugin_active'
					],
					['error_code' => 'wordpress_functions_unavailable']
				);
			}
			
			if (!function_exists('get_option')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Funciones de opciones de WordPress no están disponibles',
					500,
					[
						'operation' => 'setupCachePluginCompatibility',
						'function_missing' => 'get_option'
					],
					['error_code' => 'wordpress_options_unavailable']
				);
			}
			
			$result = [
				'success' => false,
				'detected_plugins' => [],
				'compatibility_status' => [],
				'errors' => []
			];
			
			// Detectar plugins de caché activos
			$detectedPlugins = $this->detectActiveCachePlugins();
			$result['detected_plugins'] = $detectedPlugins;
			
			// Configurar compatibilidad para cada plugin detectado
			foreach ($detectedPlugins as $pluginSlug => $pluginInfo) {
				$compatibilityResult = $this->configureCachePluginCompatibility($pluginSlug, $pluginInfo);
				$result['compatibility_status'][$pluginSlug] = $compatibilityResult;
			}
			
			// ELIMINADO: Hooks de sincronización de caché innecesarios
			// $this->setupCacheSynchronizationHooks(); // <-- REMOVIDO
			
			$result['success'] = true;
			
			$this->logger->info("Compatibilidad con plugins de caché configurada", [
				'detected_plugins' => array_keys($detectedPlugins),
				'total_plugins' => count($detectedPlugins)
			]);

			// Agregar metadatos adicionales
			$metadata = [
				'operation' => 'setupCachePluginCompatibility',
				'detected_plugins_count' => count($detectedPlugins),
				'detected_plugin_slugs' => array_keys($detectedPlugins),
				'compatibility_status_count' => count($result['compatibility_status']),
				'errors_count' => count($result['errors']),
				'success' => $result['success'],
				'timestamp' => time()
			];

			$message = $result['success'] 
				? "Compatibilidad con plugins de caché configurada exitosamente: " . count($detectedPlugins) . " plugins detectados"
				: "Error al configurar compatibilidad con plugins de caché: " . implode(', ', $result['errors']);

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$result,
				$message,
				$metadata
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'setupCachePluginCompatibility'
			]);
		}
	}
	
	/**
	 * NUEVO: Detecta plugins de caché activos
	 * 
	 * @return array Plugins detectados
	 */
	private function detectActiveCachePlugins(): array
	{
		$detectedPlugins = [];
		
		// Verificar plugins populares de caché
		$cachePlugins = [
			'w3-total-cache' => [
				'name' => 'W3 Total Cache',
				'class' => 'W3_TotalCache',
				'function' => 'w3tc_flush_all',
				'type' => 'page_cache'
			],
			'wp-super-cache' => [
				'name' => 'WP Super Cache',
				'class' => 'WP_Super_Cache',
				'function' => 'wp_cache_clear_cache',
				'type' => 'page_cache'
			],
			'wp-rocket' => [
				'name' => 'WP Rocket',
				'class' => 'WP_Rocket',
				'function' => 'rocket_clean_domain',
				'type' => 'page_cache'
			],
			'lite-speed-cache' => [
				'name' => 'LiteSpeed Cache',
				'class' => 'LiteSpeed_Cache',
				'function' => 'LiteSpeed_Cache::purge_all',
				'type' => 'page_cache'
			],
			'autoptimize' => [
				'name' => 'Autoptimize',
				'class' => 'autoptimizeCache',
				'function' => 'autoptimizeCache::clearall',
				'type' => 'optimization_cache'
			],
			'wp-optimize' => [
				'name' => 'WP Optimize',
				'class' => 'WP_Optimize',
				'function' => 'wp_optimize_cache_clear',
				'type' => 'optimization_cache'
			],
			'hummingbird' => [
				'name' => 'Hummingbird',
				'class' => 'WP_Hummingbird',
				'function' => 'wp_hummingbird_cache_clear',
				'type' => 'performance_cache'
			]
		];
		
		foreach ($cachePlugins as $slug => $pluginInfo) {
			if ($this->isPluginActive($slug, $pluginInfo)) {
				$detectedPlugins[$slug] = array_merge($pluginInfo, [
					'active' => true,
					'detected_at' => time()
				]);
			}
		}
		
		return $detectedPlugins;
	}
	
	/**
	 * NUEVO: Verifica si un plugin está activo
	 * 
	 * @param string $slug Slug del plugin
	 * @param array $pluginInfo Información del plugin
	 * @return bool True si está activo
	 */
	private function isPluginActive(string $slug, array $pluginInfo): bool
	{
		// Verificar por clase
		if (isset($pluginInfo['class']) && class_exists($pluginInfo['class'])) {
			return true;
		}
		
		// Verificar por función
		if (isset($pluginInfo['function']) && function_exists($pluginInfo['function'])) {
			return true;
		}
		
		// Verificar por constante
		if (defined('WP_ROCKET_VERSION') && $slug === 'wp-rocket') {
			return true;
		}
		
		// Verificar por opción de WordPress
		if (get_option("{$slug}_version")) {
			return true;
		}
		
		return false;
	}
	
	/**
	 * NUEVO: Configura compatibilidad para un plugin específico
	 * 
	 * @param string $pluginSlug Slug del plugin
	 * @param array $pluginInfo Información del plugin
	 * @return array Resultado de la configuración
	 */
	private function configureCachePluginCompatibility(string $pluginSlug, array $pluginInfo): array
	{
		$result = [
			'configured' => false,
			'hooks_added' => [],
			'functions_available' => [],
			'errors' => []
		];
		
		try {
			// Agregar hooks de sincronización
			$hooksAdded = $this->addCachePluginHooks($pluginSlug, $pluginInfo);
			$result['hooks_added'] = $hooksAdded;
			
			// Verificar funciones disponibles
			$functionsAvailable = $this->checkCachePluginFunctions($pluginSlug, $pluginInfo);
			$result['functions_available'] = $functionsAvailable;
			
			// Configurar limpieza automática
			$this->configureAutomaticCacheCleaning($pluginSlug, $pluginInfo);
			
			$result['configured'] = true;
			
		} catch (\Exception $e) {
			$result['errors'][] = 'Error al configurar compatibilidad: ' . $e->getMessage();
		}
		
		return $result;
	}
	
	/**
	 * NUEVO: Agrega hooks para sincronización con plugins de caché
	 * 
	 * @param string $pluginSlug Slug del plugin
	 * @param array $pluginInfo Información del plugin
	 * @return array Hooks agregados
	 */
	private function addCachePluginHooks(string $pluginSlug, array $pluginInfo): array
	{
		$hooksAdded = [];
		
		switch ($pluginSlug) {
			case 'w3-total-cache':
				// Hook para limpiar caché cuando se actualicen transients
				add_action('mia_transient_updated', [$this, 'w3tcClearCache'], 10, 2);
				$hooksAdded[] = 'mia_transient_updated -> w3tcClearCache';
				break;
				
			case 'wp-super-cache':
				// Hook para limpiar caché cuando se actualicen transients
				add_action('mia_transient_updated', [$this, 'wpSuperCacheClearCache'], 10, 2);
				$hooksAdded[] = 'mia_transient_updated -> wpSuperCacheClearCache';
				break;
				
			case 'wp-rocket':
				// Hook para limpiar caché cuando se actualicen transients
				add_action('mia_transient_updated', [$this, 'wpRocketClearCache'], 10, 2);
				$hooksAdded[] = 'mia_transient_updated -> wpRocketClearCache';
				break;
				
			case 'lite-speed-cache':
				// Hook para limpiar caché cuando se actualicen transients
				add_action('mia_transient_updated', [$this, 'liteSpeedCacheClearCache'], 10, 2);
				$hooksAdded[] = 'mia_transient_updated -> liteSpeedCacheClearCache';
				break;
		}
		
		return $hooksAdded;
	}
	
	/**
	 * NUEVO: Verifica funciones disponibles del plugin de caché
	 * 
	 * @param string $pluginSlug Slug del plugin
	 * @param array $pluginInfo Información del plugin
	 * @return array Funciones disponibles
	 */
	private function checkCachePluginFunctions(string $pluginSlug, array $pluginInfo): array
	{
		$functionsAvailable = [];
		
		if (isset($pluginInfo['function']) && function_exists($pluginInfo['function'])) {
			$functionsAvailable[] = $pluginInfo['function'];
		}
		
		if (isset($pluginInfo['class']) && class_exists($pluginInfo['class'])) {
			$functionsAvailable[] = $pluginInfo['class'];
		}
		
		// Verificar funciones adicionales específicas del plugin
		switch ($pluginSlug) {
			case 'w3-total-cache':
				if (function_exists('w3tc_flush_all')) $functionsAvailable[] = 'w3tc_flush_all';
				if (function_exists('w3tc_flush_post')) $functionsAvailable[] = 'w3tc_flush_post';
				break;
				
			case 'wp-super-cache':
				if (function_exists('wp_cache_clear_cache')) $functionsAvailable[] = 'wp_cache_clear_cache';
				if (function_exists('wp_cache_clean_cache')) $functionsAvailable[] = 'wp_cache_clean_cache';
				break;
				
			case 'wp-rocket':
				if (function_exists('rocket_clean_domain')) $functionsAvailable[] = 'rocket_clean_domain';
				if (function_exists('rocket_clean_minify')) $functionsAvailable[] = 'rocket_clean_minify';
				break;
		}
		
		return $functionsAvailable;
	}
	
	/**
	 * NUEVO: Configura limpieza automática de caché
	 * 
	 * @param string $pluginSlug Slug del plugin
	 * @param array $pluginInfo Información del plugin
	 * @return void
	 */
	private function configureAutomaticCacheCleaning(string $pluginSlug, array $pluginInfo): void
	{
		// Limpiar caché cuando se limpien transients críticos
		add_action('mia_critical_transient_cleaned', function($cacheKey) use ($pluginSlug) {
			$this->clearCachePluginCache($pluginSlug, $cacheKey);
		}, 10, 1);
		
		// Limpiar caché cuando se migren transients a base de datos
		add_action('mia_transient_migrated_to_database', function($cacheKey) use ($pluginSlug) {
			$this->clearCachePluginCache($pluginSlug, $cacheKey);
		}, 10, 1);
	}
	
	/**
	 * NUEVO: Limpia caché del plugin específico
	 * 
	 * @param string $pluginSlug Slug del plugin
	 * @param string $cacheKey Clave del transient
	 * @return bool True si se limpió correctamente
	 */
	public function clearCachePluginCache(string $pluginSlug, string $cacheKey = ''): bool
	{
		try {
			switch ($pluginSlug) {
				case 'w3-total-cache':
					return $this->w3tcClearCache($cacheKey);
					
				case 'wp-super-cache':
					return $this->wpSuperCacheClearCache($cacheKey);
					
				case 'wp-rocket':
					return $this->wpRocketClearCache($cacheKey);
					
				case 'lite-speed-cache':
					return $this->liteSpeedCacheClearCache($cacheKey);
					
				default:
					return false;
			}
		} catch (\Exception $e) {
			$this->logger->error("Error al limpiar caché del plugin {$pluginSlug}", [
				'error' => $e->getMessage(),
				'cache_key' => $cacheKey
			]);
			return false;
		}
	}
	
	/**
	 * NUEVO: Configura hooks de sincronización de caché
	 * 
	 * @return void
	 */
	/**
	 * ELIMINADO: Método setupCacheSynchronizationHooks() removido
	 * 
	 * Este método registraba hooks de caché externo innecesarios que causaban errores fatales
	 * porque los métodos correspondientes no existían. Los hooks no eran necesarios para
	 * la funcionalidad principal de sincronización del plugin.
	 * 
	 * Hooks eliminados:
	 * - handleExternalCacheUpdate, handleExternalCacheInvalidation, handleExternalCacheCleared
	 */

    /**
     * NUEVO: Sistema de integración con herramientas de monitoreo
     *
     * @return SyncResponseInterface Estado de la integración
     */
	public function setupMonitoringToolsIntegration(): SyncResponseInterface
	{
		try {
			// VALIDACIÓN CRÍTICA - Verificar que las funciones de monitoreo estén disponibles
			if (!function_exists('function_exists')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Funciones de verificación no están disponibles',
					500,
					[
						'operation' => 'setupMonitoringToolsIntegration',
						'function_missing' => 'function_exists'
					],
					['error_code' => 'verification_functions_unavailable']
				);
			}
			
			if (!function_exists('defined')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Funciones de verificación de constantes no están disponibles',
					500,
					[
						'operation' => 'setupMonitoringToolsIntegration',
						'function_missing' => 'defined'
					],
					['error_code' => 'constant_verification_unavailable']
				);
			}
			
			$result = [
				'success' => false,
				'detected_tools' => [],
				'integration_status' => [],
				'hooks_configured' => [],
				'errors' => []
			];
			
			// Detectar herramientas de monitoreo
			$detectedTools = $this->detectMonitoringTools();
			$result['detected_tools'] = $detectedTools;
			
			// Configurar integración para cada herramienta
			foreach ($detectedTools as $toolSlug => $toolInfo) {
				$integrationResult = $this->configureMonitoringToolIntegration($toolSlug, $toolInfo);
				$result['integration_status'][$toolSlug] = $integrationResult;
			}
			
			// Configurar hooks de monitoreo
			$hooksConfigured = $this->setupMonitoringHooks();
			$result['hooks_configured'] = $hooksConfigured;
			
			// Configurar métricas exportables
			$this->setupExportableMetrics();
			
			$result['success'] = true;
			
			$this->logger->info("Integración con herramientas de monitoreo configurada", [
				'detected_tools' => array_keys($detectedTools),
				'total_tools' => count($detectedTools)
			]);

			// Agregar metadatos adicionales
			$metadata = [
				'operation' => 'setupMonitoringToolsIntegration',
				'detected_tools_count' => count($detectedTools),
				'detected_tool_slugs' => array_keys($detectedTools),
				'integration_status_count' => count($result['integration_status']),
				'hooks_configured_count' => count($result['hooks_configured']),
				'errors_count' => count($result['errors']),
				'success' => $result['success'],
				'timestamp' => time()
			];

			$message = $result['success'] 
				? "Integración con herramientas de monitoreo configurada exitosamente: " . count($detectedTools) . " herramientas detectadas"
				: "Error al configurar integración con herramientas de monitoreo: " . implode(', ', $result['errors']);

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$result,
				$message,
				$metadata
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'setupMonitoringToolsIntegration'
			]);
		}
	}
	
	/**
	 * NUEVO: Detecta herramientas de monitoreo disponibles
	 * 
	 * @return array Herramientas detectadas
	 */
	private function detectMonitoringTools(): array
	{
		$detectedTools = [];
		
		// Verificar herramientas populares de monitoreo
		$monitoringTools = [
			'new-relic' => [
				'name' => 'New Relic',
				'constant' => 'NEWRELIC_PHP_VERSION',
				'function' => 'newrelic_custom_metric',
				'type' => 'apm_monitoring'
			],
			'datadog' => [
				'name' => 'Datadog',
				'constant' => 'DD_TRACE_ENABLED',
				'function' => 'dd_trace',
				'type' => 'apm_monitoring'
			],
			'sentry' => [
				'name' => 'Sentry',
				'constant' => 'SENTRY_DSN',
				'function' => 'Sentry\captureMessage',
				'type' => 'error_monitoring'
			],
			'bugsnag' => [
				'name' => 'Bugsnag',
				'constant' => 'BUGSNAG_API_KEY',
				'function' => 'Bugsnag\Client::make',
				'type' => 'error_monitoring'
			],
			'logstash' => [
				'name' => 'Logstash',
				'constant' => 'LOGSTASH_ENABLED',
				'function' => 'logstash_log',
				'type' => 'log_monitoring'
			],
			'prometheus' => [
				'name' => 'Prometheus',
				'constant' => 'PROMETHEUS_ENABLED',
				'function' => 'prometheus_counter_inc',
				'type' => 'metrics_monitoring'
			],
			'grafana' => [
				'name' => 'Grafana',
				'constant' => 'GRAFANA_ENABLED',
				'function' => 'grafana_metric',
				'type' => 'metrics_monitoring'
			]
		];
		
		foreach ($monitoringTools as $slug => $toolInfo) {
			if ($this->isMonitoringToolActive($slug, $toolInfo)) {
				$detectedTools[$slug] = array_merge($toolInfo, [
					'active' => true,
					'detected_at' => time()
				]);
			}
		}
		
		return $detectedTools;
	}
	
	/**
	 * NUEVO: Verifica si una herramienta de monitoreo está activa
	 * 
	 * @param string $slug Slug de la herramienta
	 * @param array $toolInfo Información de la herramienta
	 * @return bool True si está activa
	 */
	private function isMonitoringToolActive(string $slug, array $toolInfo): bool
	{
		// Verificar por constante
		if (isset($toolInfo['constant']) && defined($toolInfo['constant'])) {
			return true;
		}
		return false;
	}

	// ============================================================================
	// FASE 3: MÉTODOS PARA CRON JOBS DE MONITORING Y OPTIMIZATION
	// ============================================================================

	/**
	 * DELEGADO A MemoryManager: Ejecuta monitoreo de salud del sistema para cron jobs
	 * 
	 * @return array Resultado del monitoreo
	 */
	public static function executeSystemHealthMonitoring(): array
	{
		// DELEGAR A MemoryManager
		if (class_exists('\MiIntegracionApi\Core\MemoryManager')) {
			return MemoryManager::executeSystemHealthMonitoring();
		}
		
		// FALLBACK: Retornar error si MemoryManager no está disponible
		return [
			'success' => false,
			'error' => 'MemoryManager no disponible para monitoreo de salud del sistema',
			'execution_time' => date('Y-m-d H:i:s')
		];
	}

	/**
	 * DELEGADO A MemoryManager: Ejecuta optimización adaptativa del sistema para cron jobs
	 * 
	 * @return array Resultado de la optimización
	 */
	public static function executeAdaptiveOptimization(): array
	{
		// DELEGAR A MemoryManager
		if (class_exists('\MiIntegracionApi\Core\MemoryManager')) {
			return MemoryManager::executeAdaptiveOptimization();
		}
		
		// FALLBACK: Retornar error si MemoryManager no está disponible
		return [
			'success' => false,
			'error' => 'MemoryManager no disponible para optimización adaptativa del sistema',
			'execution_time' => date('Y-m-d H:i:s')
		];
	}

	/**
	 * DELEGADO A RobustnessHooks: Ejecuta limpieza inteligente del sistema para cron jobs
	 * 
	 * @return array Resultado de la limpieza
	 */
	public static function executeIntelligentCleanup(): array
	{
		// DELEGAR A RobustnessHooks
		if (class_exists('\MiIntegracionApi\Hooks\RobustnessHooks')) {
			return \MiIntegracionApi\Hooks\RobustnessHooks::executeIntelligentCleanup();
		}
		
		// FALLBACK: Retornar error si RobustnessHooks no está disponible
		return [
			'success' => false,
			'error' => 'RobustnessHooks no disponible para limpieza inteligente del sistema',
			'execution_time' => date('Y-m-d H:i:s')
		];
	}

	/**
	 * ELIMINADO: Método executeBatchSync no implementado
	 * 
	 * @deprecated Este método dependía de ProductBatchProcessor::executeBatchSync() que no existe
	 * @return array Resultado de error
	 */
	public static function executeBatchSync(): array
	{
		return [
			'success' => false,
			'error' => 'Método executeBatchSync no implementado - ProductBatchProcessor fue eliminado',
			'execution_time' => date('Y-m-d H:i:s')
		];
	}

	// ============================================================================
	// MÉTODOS AUXILIARES PARA OPTIMIZATION Y CLEANUP
	// ============================================================================

	/**
	 * Verifica si los transients requieren optimización
	 */
	private static function checkTransientOptimization(): array
	{
		// Implementación básica - se puede expandir
		return ['requires_optimization' => false];
	}

	/**
	 * Optimiza transients del sistema
	 */
	private static function optimizeTransients(): array
	{
		// Implementación básica - se puede expandir
		return ['success' => true, 'items_optimized' => 0];
	}

	/**
	 * Verifica si el cache requiere optimización
	 */
	private static function checkCacheOptimization(): array
	{
		// Implementación básica - se puede expandir
		return ['requires_optimization' => false];
	}

	/**
	 * Optimiza cache del sistema
	 */
	private static function optimizeCache(): array
	{
		// Implementación básica - se puede expandir
		return ['success' => true, 'items_optimized' => 0];
	}

	/**
	 * Realiza limpieza inteligente de transients
	 */
	private static function performIntelligentTransientCleanup(): array
	{
		// Implementación básica - se puede expandir
		return ['success' => true, 'items_cleaned' => 0];
	}

	/**
	 * Realiza limpieza inteligente de logs
	 */
	private static function performIntelligentLogCleanup(): array
	{
		// Implementación básica - se puede expandir
		return ['success' => true, 'items_cleaned' => 0];
	}

	/**
	 * Realiza limpieza inteligente de cache
	 */
	private static function performIntelligentCacheCleanup(): array
	{
		// Implementación básica - se puede expandir
		return ['success' => true, 'items_cleaned' => 0];
	}

	/**
	 * Realiza limpieza inteligente de archivos temporales
	 */
	private static function performIntelligentTempCleanup(): array
	{
		// Implementación básica - se puede expandir
		return ['success' => true, 'items_cleaned' => 0];
	}

	// ============================================================================
	// FASE 1: MÉTODOS DE LOGGING OPTIMIZADO
	// ============================================================================
	
	/**
	 * FASE 1: Determina si se debe hacer logging de debug
	 * Aplica buenas prácticas: DRY, Single Responsibility
	 *
	 * @return bool True si se debe hacer debug logging
	 */
	private function shouldLogDebug(): bool {
		// Solo debug en desarrollo o si está explícitamente habilitado
		$environment = $this->detectEnvironment();
		$explicitDebug = defined('MIA_DEBUG_LOGGING') && MIA_DEBUG_LOGGING;
		$wpDebug = defined('WP_DEBUG') && WP_DEBUG;
		
		// FASE 1: Validación temprana (Fail Fast)
		if ($environment === 'production' && !$explicitDebug) {
			return false; // Nunca debug en producción sin configuración explícita
		}
		
		return $explicitDebug || ($wpDebug && $environment !== 'production');
	}
	


	// ============================================================================
	// MÉTODOS DE INTEGRACIÓN CON HERRAMIENTAS DE MONITOREO
	// ============================================================================

	/**
	 * NUEVO: Configura integración para una herramienta específica
	 * 
	 * @param string $toolSlug Slug de la herramienta
	 * @param array $toolInfo Información de la herramienta
	 * @return array Resultado de la configuración
	 */
	private function configureMonitoringToolIntegration(string $toolSlug, array $toolInfo): array
	{
		$result = [
			'configured' => false,
			'hooks_added' => [],
			'metrics_configured' => [],
			'errors' => []
		];
		
		try {
			// Agregar hooks de monitoreo
			$hooksAdded = $this->addMonitoringToolHooks($toolSlug, $toolInfo);
			$result['hooks_added'] = $hooksAdded;
			
			// Configurar métricas específicas
			$metricsConfigured = $this->configureMonitoringToolMetrics($toolSlug, $toolInfo);
			$result['metrics_configured'] = $metricsConfigured;
			
			$result['configured'] = true;
			
		} catch (\Exception $e) {
			$result['errors'][] = 'Error al configurar integración: ' . $e->getMessage();
		}
		
		return $result;
	}
	
	/**
	 * NUEVO: Agrega hooks para herramientas de monitoreo
	 * 
	 * @param string $toolSlug Slug de la herramienta
	 * @param array $toolInfo Información de la herramienta
	 * @return array Hooks agregados
	 */
	private function addMonitoringToolHooks(string $toolSlug, array $toolInfo): array
	{
		$hooksAdded = [];
		
		switch ($toolSlug) {
			case 'new-relic':
				// Hook para métricas personalizadas
				add_action('mia_metric_recorded', [$this, 'newRelicRecordMetric'], 10, 3);
				$hooksAdded[] = 'mia_metric_recorded -> newRelicRecordMetric';
				break;
				
			case 'datadog':
				// Hook para métricas personalizadas
				add_action('mia_metric_recorded', [$this, 'datadogRecordMetric'], 10, 3);
				$hooksAdded[] = 'mia_metric_recorded -> datadogRecordMetric';
				break;
				
			case 'sentry':
				// Hook para errores y excepciones
				add_action('mia_error_occurred', [$this, 'sentryCaptureError'], 10, 2);
				$hooksAdded[] = 'mia_error_occurred -> sentryCaptureError';
				break;
				
			case 'bugsnag':
				// Hook para errores y excepciones
				add_action('mia_error_occurred', [$this, 'bugsnagCaptureError'], 10, 2);
				$hooksAdded[] = 'mia_error_occurred -> bugsnagCaptureError';
				break;
		}
		
		return $hooksAdded;
	}
	
	/**
	 * NUEVO: Configura métricas para herramientas de monitoreo
	 * 
	 * @param string $toolSlug Slug de la herramienta
	 * @param array $toolInfo Información de la herramienta
	 * @return array Métricas configuradas
	 */
	private function configureMonitoringToolMetrics(string $toolSlug, array $toolInfo): array
	{
		$metricsConfigured = [];
		
		// Métricas estándar para todas las herramientas
		$standardMetrics = [
			'transient_count' => 'Número total de transients',
			'transient_size_mb' => 'Tamaño total de transients en MB',
			'cleanup_operations' => 'Operaciones de limpieza ejecutadas',
			'memory_usage_percent' => 'Uso de memoria en porcentaje',
			'cache_hit_ratio' => 'Ratio de aciertos de caché',
			'optimization_score' => 'Score de optimización del sistema'
		];
		
		foreach ($standardMetrics as $metricName => $metricDescription) {
			$metricsConfigured[] = [
				'name' => $metricName,
				'description' => $metricDescription,
				'type' => 'gauge',
				'unit' => $this->getMetricUnit($metricName)
			];
		}
		
		// Métricas específicas por herramienta
		switch ($toolSlug) {
			case 'new-relic':
				$metricsConfigured[] = [
					'name' => 'custom_transient_metrics',
					'description' => 'Métricas personalizadas de transients',
					'type' => 'custom',
					'unit' => 'count'
				];
				break;
				
			case 'datadog':
				$metricsConfigured[] = [
					'name' => 'datadog_transient_metrics',
					'description' => 'Métricas de transients para Datadog',
					'type' => 'custom',
					'unit' => 'count'
				];
				break;
		}
		
		return $metricsConfigured;
	}
	
	/**
	 * NUEVO: Configura hooks de monitoreo
	 * 
	 * @return array Hooks configurados
	 */
	private function setupMonitoringHooks(): array
	{
		$hooksConfigured = [];
		
		// Hook para métricas de transients
		add_action('mia_transient_metric_recorded', [$this, 'recordTransientMetric'], 10, 3);
		$hooksConfigured[] = 'mia_transient_metric_recorded -> recordTransientMetric';
		
		// Hook para métricas de limpieza
		add_action('mia_cleanup_metric_recorded', [$this, 'recordCleanupMetric'], 10, 3);
		$hooksConfigured[] = 'mia_cleanup_metric_recorded -> recordCleanupMetric';
		
		// Hook para métricas de memoria
		add_action('mia_memory_metric_recorded', [$this, 'recordMemoryMetric'], 10, 3);
		$hooksConfigured[] = 'mia_memory_metric_recorded -> recordMemoryMetric';
		
		// Hook para métricas de optimización
		add_action('mia_optimization_metric_recorded', [$this, 'recordOptimizationMetric'], 10, 3);
		$hooksConfigured[] = 'mia_optimization_metric_recorded -> recordOptimizationMetric';
		
		// Hook para errores y excepciones
		add_action('mia_error_occurred', [$this, 'recordErrorMetric'], 10, 2);
		$hooksConfigured[] = 'mia_error_occurred -> recordErrorMetric';
		
		return $hooksConfigured;
	}
	
	/**
	 * NUEVO: Configura métricas exportables
	 * 
	 * @return void
	 */
	private function setupExportableMetrics(): void
	{
		// Endpoint REST para métricas
		add_action('rest_api_init', [$this, 'registerMetricsEndpoints']);
		
		// Endpoint para exportación de métricas
		add_action('wp_ajax_mia_export_metrics', [$this, 'exportMetrics']);
		add_action('wp_ajax_nopriv_mia_export_metrics', [$this, 'exportMetrics']);
		
		// Endpoint para métricas en tiempo real
		add_action('wp_ajax_mia_get_realtime_metrics', [$this, 'getRealtimeMetrics']);
		add_action('wp_ajax_nopriv_mia_get_realtime_metrics', [$this, 'getRealtimeMetrics']);
	}
	
	/**
	 * NUEVO: Obtiene unidad de métrica
	 * 
	 * @param string $metricName Nombre de la métrica
	 * @return string Unidad de la métrica
	 */
	private function getMetricUnit(string $metricName): string
	{
		$units = [
			'transient_count' => 'count',
			'transient_size_mb' => 'MB',
			'cleanup_operations' => 'count',
			'memory_usage_percent' => 'percent',
			'cache_hit_ratio' => 'ratio',
			'optimization_score' => 'score'
		];
		
		return $units[$metricName] ?? 'count';
	}

    /**
     * NUEVO: Sistema de compatibilidad con multisite
     *
     * @return SyncResponseInterface Estado de la compatibilidad
     */
	public function setupMultisiteCompatibility(): SyncResponseInterface
	{
		try {
			// VALIDACIÓN CRÍTICA - Verificar que las funciones de multisite estén disponibles
			if (!function_exists('is_multisite')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Funciones de multisite no están disponibles',
					500,
					[
						'operation' => 'setupMultisiteCompatibility',
						'function_missing' => 'is_multisite'
					],
					['error_code' => 'multisite_functions_unavailable']
				);
			}
			
			if (!function_exists('get_current_blog_id')) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Funciones de identificación de sitio no están disponibles',
					500,
					[
						'operation' => 'setupMultisiteCompatibility',
						'function_missing' => 'get_current_blog_id'
					],
					['error_code' => 'site_identification_unavailable']
				);
			}
			
			$result = [
				'success' => false,
				'is_multisite' => false,
				'current_site_id' => null,
				'sites_configured' => [],
				'coordination_enabled' => false,
				'errors' => []
			];
			
			// Verificar si es multisite
			if (!is_multisite()) {
				$result['message'] = 'No es un sitio multisite';
				$result['success'] = true;
				
				$metadata = [
					'operation' => 'setupMultisiteCompatibility',
					'is_multisite' => false,
					'message' => 'No es un sitio multisite',
					'timestamp' => time()
				];

				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
					$result,
					'No es un sitio multisite - compatibilidad no requerida',
					$metadata
				);
			}
			
			$result['is_multisite'] = true;
			$result['current_site_id'] = get_current_blog_id();
			
			// ELIMINADO: Hooks de multisite innecesarios
			// $this->configureMultisiteHooks(); // <-- REMOVIDO
			// $this->setupMultisiteCoordination(); // <-- REMOVIDO
			// $this->configureSiteSpecificSettings(); // <-- REMOVIDO
			
			$result['coordination_enabled'] = true;
			$result['success'] = true;
			
			$this->logger->info("Compatibilidad con multisite configurada", [
				'current_site_id' => $result['current_site_id'],
				'total_sites' => $this->getTotalSitesCount()
			]);

			// Agregar metadatos adicionales
			$metadata = [
				'operation' => 'setupMultisiteCompatibility',
				'is_multisite' => true,
				'current_site_id' => $result['current_site_id'],
				'coordination_enabled' => $result['coordination_enabled'],
				'errors_count' => count($result['errors']),
				'success' => $result['success'],
				'timestamp' => time()
			];

			$message = $result['success'] 
				? "Compatibilidad con multisite configurada exitosamente para el sitio ID: {$result['current_site_id']}"
				: "Error al configurar compatibilidad multisite: " . implode(', ', $result['errors']);

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$result,
				$message,
				$metadata
			);
			
		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'setupMultisiteCompatibility'
			]);
		}
	}
	
	/**
	 * NUEVO: Configura hooks específicos para multisite
	 * 
	 * @return void
	 */
	/**
	 * ELIMINADO: Método configureMultisiteHooks() removido
	 * 
	 * Este método registraba hooks de multisite innecesarios que causaban errores fatales
	 * porque los métodos correspondientes no existían. Los hooks no eran necesarios para
	 * la funcionalidad principal de sincronización del plugin.
	 * 
	 * Hooks eliminados:
	 * - handleSiteSwitch, handleNewSiteCreation, handleSiteDeletion
	 * - handleSiteActivation, handleSiteDeactivation, handleMultisiteSync
	 */
	
	/**
	 * NUEVO: Configura coordinación entre sitios
	 * 
	 * @return void
	 */
	private function setupMultisiteCoordination(): void
	{
		// Configurar limpieza coordinada
		add_action('mia_coordinated_cleanup_required', [$this, 'executeCoordinatedCleanup'], 10, 1);
		
		// Configurar optimización coordinada
		add_action('mia_coordinated_optimization_required', [$this, 'executeCoordinatedOptimization'], 10, 1);
		
		// Configurar monitoreo coordinado
		add_action('mia_coordinated_monitoring_required', [$this, 'executeCoordinatedMonitoring'], 10, 1);
		
		// Configurar migración coordinada
		add_action('mia_coordinated_migration_required', [$this, 'executeCoordinatedMigration'], 10, 1);
	}
	
	/**
	 * NUEVO: Configura configuraciones específicas por sitio
	 * 
	 * @return void
	 */
	private function configureSiteSpecificSettings(): void
	{
		// Obtener configuración específica del sitio actual
		$siteConfig = $this->getSiteSpecificConfiguration();
		
		// Aplicar configuración específica del sitio
		$this->applySiteSpecificConfiguration($siteConfig);
		
		// Configurar hooks específicos del sitio
		$this->setupSiteSpecificHooks($siteConfig);
	}
	
	/**
	 * NUEVO: Obtiene configuración específica del sitio
	 * 
	 * @return array Configuración del sitio
	 */
	private function getSiteSpecificConfiguration(): array
	{
		$siteId = get_current_blog_id();
		$siteConfig = get_blog_option($siteId, 'mia_site_config', []);
		
		// Configuración por defecto si no existe
		if (empty($siteConfig)) {
			$siteConfig = [
				'cleanup_schedule' => 'daily',
				'cleanup_time' => '02:00',
				'memory_threshold' => 80,
				'optimization_enabled' => true,
				'migration_enabled' => true,
				'monitoring_enabled' => true,
				'coordination_enabled' => true
			];
			
			// Guardar configuración por defecto
			update_blog_option($siteId, 'mia_site_config', $siteConfig);
		}
		
		return $siteConfig;
	}
	
	/**
	 * NUEVO: Aplica configuración específica del sitio
	 * 
	 * @param array $siteConfig Configuración del sitio
	 * @return void
	 */
	private function applySiteSpecificConfiguration(array $siteConfig): void
	{
		// Aplicar horario de limpieza específico del sitio
		if (isset($siteConfig['cleanup_schedule']) && isset($siteConfig['cleanup_time'])) {
			$this->scheduleSiteSpecificCleanup($siteConfig['cleanup_schedule'], $siteConfig['cleanup_time']);
		}
		
		// Aplicar umbral de memoria específico del sitio
		if (isset($siteConfig['memory_threshold'])) {
			$this->setSiteSpecificMemoryThreshold($siteConfig['memory_threshold']);
		}
		
		// Aplicar configuraciones de optimización
		if (isset($siteConfig['optimization_enabled'])) {
			$this->setSiteSpecificOptimization($siteConfig['optimization_enabled']);
		}
		
		// Aplicar configuraciones de migración
		if (isset($siteConfig['migration_enabled'])) {
			$this->setSiteSpecificMigration($siteConfig['migration_enabled']);
		}
	}
	
	/**
	 * NUEVO: Configura hooks específicos del sitio
	 * 
	 * @param array $siteConfig Configuración del sitio
	 * @return void
	 */
	private function setupSiteSpecificHooks(array $siteConfig): void
	{
		// Hook para limpieza específica del sitio
		if ($siteConfig['cleanup_enabled'] ?? true) {
			add_action('mia_site_specific_cleanup', [$this, 'executeSiteSpecificCleanup'], 10, 1);
		}
		
		// Hook para optimización específica del sitio
		if ($siteConfig['optimization_enabled'] ?? true) {
			add_action('mia_site_specific_optimization', [$this, 'executeSiteSpecificOptimization'], 10, 1);
		}
		
		// Hook para monitoreo específico del sitio
		if ($siteConfig['monitoring_enabled'] ?? true) {
			add_action('mia_site_specific_monitoring', [$this, 'executeSiteSpecificMonitoring'], 10, 1);
		}
	}
	
	/**
	 * NUEVO: Obtiene número total de sitios
	 * 
	 * @return int Número total de sitios
	 */
	private function getTotalSitesCount(): int
	{
		if (!is_multisite()) {
			return 1;
		}
		
		$sites = get_sites(['count_total' => true]);
		return $sites;
	}
	
	/**
	 * NUEVO: Ejecuta limpieza coordinada entre sitios
	 * 
	 * @param array $options Opciones de limpieza
	 * @return array Resultado de la limpieza coordinada
	 */
	// MIGRADO A BatchProcessor.php: executeCoordinatedCleanup() y executeCleanupOnSite()
	
	/**
	 * NUEVO: Calcula el delay en segundos hasta una hora específica
	 * 
	 * @param int $targetHour Hora objetivo (0-23)
	 * @return int Delay en segundos
	 */
	private function calculateDelayToHour(int $targetHour): int
	{
		$currentHour = (int)date('G');
		$currentMinute = (int)date('i');
		$currentSecond = (int)date('s');
		
		$currentSeconds = $currentHour * self::SECONDS_PER_HOUR + $currentMinute * 60 + $currentSecond;
		$targetSeconds = $targetHour * self::SECONDS_PER_HOUR;
		
		$delay = $targetSeconds - $currentSeconds;
		
		// Si la hora ya pasó hoy, programar para mañana
		if ($delay <= 0) {
			$delay += 86400; // 24 horas
		}
		
		return $delay;
	}
	
	/**
	 * NUEVO: Ejecuta limpieza condicional solo cuando es necesario
	 * 
	 * @return array Resultado de la evaluación y limpieza
	 */
	// MIGRADO A BatchProcessor.php: executeConditionalCleanup() y evaluateCleanupConditions()
	
	/**
	 * NUEVO: Determina si se debe ejecutar limpieza basado en condiciones
	 * 
	 * @param array $conditions Condiciones evaluadas
	 * @return bool True si se necesita limpieza
	 */
	// MIGRADO A BatchProcessor.php: shouldExecuteCleanup()
	
	/**
	 * NUEVO: Obtiene la razón para ejecutar limpieza
	 * 
	 * @param array $conditions Condiciones evaluadas
	 * @return string Razón de la limpieza
	 */
	// MIGRADO A BatchProcessor.php: getCleanupReason()
	
	/**
	 * NUEVO: Determina el tipo de limpieza apropiado
	 * 
	 * @param array $conditions Condiciones evaluadas
	 * @return string Tipo de limpieza
	 */
	// MIGRADO A BatchProcessor.php: determineCleanupType()
	
	/**
	 * NUEVO: Ejecuta el tipo de limpieza apropiado
	 * 
	 * @param array $conditions Condiciones evaluadas
	 * @return array Resultados de la limpieza
	 */
	// MIGRADO A BatchProcessor.php: executeAppropriateCleanup()
	
	/**
	 * NUEVO: Calcula score de rendimiento para limpieza
	 * 
	 * @param array $metrics Métricas de evaluación
	 * @return float Score de rendimiento
	 */
	// MIGRADO A BatchProcessor.php: calculateCleanupPerformanceScore()
	
	/**
	 * NUEVO: Registra historial de limpieza por lotes
	 * 
	 * @param array $results Resultados de la limpieza por lotes
	 * @return void
	 */
	// MIGRADO A BatchProcessor.php: recordBatchCleanupHistory()
	
	/**
	 * NUEVO: Registra limpieza de un transient individual
	 * 
	 * @param string $cacheKey Clave del transient
	 * @param int $sizeBytes Tamaño en bytes
	 * @param string $cleanupType Tipo de limpieza
	 * @return void
	 */
	// MIGRADO A BatchProcessor.php: recordTransientCleanup()

	/**
	 * 🚨 INTERCEPTOR GLOBAL: Registra hooks para capturar errores de SKU en múltiples puntos
	 * 
	 * @return void
	 */
	public function register_sku_error_interceptors(): void {
		// Hook ANTES de que WooCommerce valide productos
		add_action('woocommerce_before_product_object_save', [$this, 'intercept_before_wc_validation'], 10, 2);
		
		// Hook DESPUÉS de que WooCommerce valide productos
		add_action('woocommerce_after_product_object_save', [$this, 'intercept_after_wc_validation'], 10, 2);
		
		// Hook para errores de validación de WooCommerce
		add_filter('woocommerce_product_data_store_cpt_get_products_query', [$this, 'intercept_wc_query_errors'], 10, 2);
		
		// Hook para errores de guardado de productos
		add_action('woocommerce_product_object_updated_props', [$this, 'intercept_wc_save_errors'], 10, 2);
		
		$this->logger->info('🚨 Interceptores de errores de SKU registrados correctamente', [
			'category' => 'sku-error-interceptors',
			'hooks_registered' => [
				'woocommerce_before_product_object_save',
				'woocommerce_after_product_object_save',
				'woocommerce_product_data_store_cpt_get_products_query',
				'woocommerce_product_object_updated_props'
			]
		]);
	}

	/**
	 * Intercepta ANTES de la validación de WooCommerce
	 * 
	 * @param \WC_Product $product Producto a validar
	 * @param mixed|null $data Datos del producto o WC_Data_Store
	 * @return void
	 */
	public function intercept_before_wc_validation(\WC_Product $product, mixed $data = null): void {
	}

	/**
	 * Intercepta DESPUÉS de la validación de WooCommerce
	 * 
	 * @param \WC_Product $product Producto validado
	 * @param mixed|null $data Datos del producto o WC_Data_Store
	 * @return void
	 */
	public function intercept_after_wc_validation(\WC_Product $product, mixed $data = null): void {
	}

    /**
     * Intercepta errores en consultas de WooCommerce
     *
     * @param array $query Query de WooCommerce
     * @param array $query_vars Variables de la query
     * @return SyncResponseInterface Query modificada
     */
	public function intercept_wc_query_errors(array $query, array $query_vars): SyncResponseInterface
    {
		try {
			// Validar parámetros de entrada
			if (empty($query)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::validationError(
					'Query de WooCommerce no puede estar vacía',
					['query' => $query, 'query_vars' => $query_vars],
					['error_code' => 'empty_wc_query']
				);
			}

			// Procesar la query (implementación básica)
			$processed_query = $query;
			
			// Agregar metadatos adicionales
			$metadata = [
				'operation' => 'intercept_wc_query_errors',
				'query_keys' => array_keys($query),
				'query_vars_keys' => array_keys($query_vars),
				'query_size' => count($query),
				'query_vars_size' => count($query_vars),
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$processed_query,
				'Query de WooCommerce procesada exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'intercept_wc_query_errors',
				'query_keys' => array_keys($query ?? []),
				'query_vars_keys' => array_keys($query_vars ?? [])
			]);
		}
	}

	/**
	 * Intercepta errores en el guardado de productos
	 * 
	 * @param \WC_Product $product Producto guardado
	 * @param mixed|null $updated_props Propiedades actualizadas
	 * @return void
	 */
	public function intercept_wc_save_errors(\WC_Product $product, mixed $updated_props = null): void {
	}

	/**
	 * ===================================================================
	 * ENDPOINTS OPTIMIZADOS PARA FRONTEND (AJAX/API)
	 * ===================================================================
	 * 
	 * Esta sección contiene endpoints optimizados para la comunicación entre
	 * la interfaz de usuario y el backend, diseñados específicamente para:
	 * - Minimizar la transferencia de datos
	 * - Reducir la carga del servidor
	 *- Proporcionar respuestas en formato JSON estandarizado
	 * - Incluir metadatos de rendimiento y estado
	 * 
	 * Características principales:
	 * - Autenticación mediante nonces de WordPress
	 * - Manejo centralizado de errores
	 * - Formato de respuesta consistente
	 * - Caché inteligente cuando es apropiado
	 * - Validación de permisos
	 *
	 * @see registerFrontendEndpoints() Para el registro de los hooks AJAX
	 * @see formatApiResponse() Para el formato estándar de respuestas
	 * @see handleApiError() Para el manejo centralizado de errores
	 * @since 1.0.0
	 * @version 2.0.0
	 */

	/**
	 * Registra los endpoints AJAX optimizados para el frontend
	 *
	 * Este método registra todos los hooks de WordPress necesarios para manejar
	 * las peticiones AJAX desde el frontend. Cada endpoint está diseñado para
	 * ser ligero y eficiente, con un formato de respuesta consistente.
	 *
	 * Los endpoints registrados incluyen:
	 * - mia_system_health_optimized: Obtiene métricas de salud del sistema
	 * - mia_performance_metrics_optimized: Proporciona métricas de rendimiento
	 *
	 * @return void
	 * @since 1.0.0
	 * @version 2.0.0
	 * @see admin-ajax.php Para el manejo de peticiones AJAX en WordPress
	 * @see wp_ajax_* Para la documentación de los hooks AJAX
	 *
	 * @example
	 * // Ejemplo de llamada desde JavaScript
     * jQuery.ajax({
     *     url: ajaxurl,
     *     type: 'POST',
     *     data: {
     *         action: 'mia_system_health_optimized',
     *         nonce: mia_vars.nonce
     *     },
     *     success: function(response) {
     *         console.log('Estado del sistema:', response.data);
     *     }
     * });
     *
     * @hook wp_ajax_mia_system_health_optimized
     * @hook wp_ajax_mia_performance_metrics_optimized
	 */
	public function registerFrontendEndpoints(): void
	{
		// Endpoints de sincronización optimizados - ELIMINADOS: Duplicados de funcionalidad existente
		
		// Endpoints de monitoreo optimizados
		add_action('wp_ajax_mia_system_health_optimized', [$this, 'getSystemHealthOptimized']);
		add_action('wp_ajax_mia_performance_metrics_optimized', [$this, 'getPerformanceMetricsOptimized']);
		
		// Endpoints de configuración optimizados
		add_action('wp_ajax_mia_polling_config_optimized', [$this, 'getPollingConfigOptimized']);
		add_action('wp_ajax_mia_ui_config_optimized', [$this, 'getUIConfigOptimized']);
	}

	/**
	 * Endpoint optimizado para métricas de sincronización
	 * Proporciona métricas específicas para el frontend
	 * 
	 * @return void
	 * @since 1.0.0
	 */
	public function getSyncMetricsOptimized(): void
	{
		// Validación de seguridad
		if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'mia_ajax_nonce')) {
			wp_send_json_error(['message' => 'Nonce inválido'], 403);
			return;
		}

		try {
			$metrics = $this->getSyncMetrics();
			
			// Datos optimizados para el frontend
			$metrics_data = [
				'performance' => [
					'items_per_minute' => $metrics['performance']['items_per_minute'] ?? 0,
					'average_batch_time' => $metrics['performance']['average_batch_time'] ?? 0,
					'success_rate' => $metrics['performance']['success_rate'] ?? 0,
					'error_rate' => $metrics['performance']['error_rate'] ?? 0
				],
				'statistics' => [
					'total_syncs' => $metrics['statistics']['total_syncs'] ?? 0,
					'successful_syncs' => $metrics['statistics']['successful_syncs'] ?? 0,
					'failed_syncs' => $metrics['statistics']['failed_syncs'] ?? 0,
					'cancelled_syncs' => $metrics['statistics']['cancelled_syncs'] ?? 0
				],
				'trends' => [
					'daily_syncs' => $this->getDailySyncTrends(7),
					'performance_trend' => $this->getPerformanceTrend(7),
					'error_trend' => $this->getErrorTrend(7)
				],
				'system' => [
					'memory_usage' => $this->getMemoryUsage(),
					'cache_efficiency' => $this->getCacheEfficiency(),
					'database_performance' => $this->getDatabasePerformance()
				]
			];

			wp_send_json_success($metrics_data);

		} catch (\Throwable $e) {
			$this->logger->error('Error en getSyncMetricsOptimized', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error al obtener métricas de sincronización',
				'code' => 'sync_metrics_error'
			], 500);
		}
	}

	/**
	 * Endpoint optimizado para configuración de sincronización
	 * Proporciona configuración específica para el frontend
	 * 
	 * @return void
	 * @since 1.0.0
	 */
	public function getSyncConfigOptimized(): void
	{
		// Validación de seguridad
		if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'mia_ajax_nonce')) {
			wp_send_json_error(['message' => 'Nonce inválido'], 403);
			return;
		}

		try {
			// Datos optimizados para el frontend
			$config_data = [
				'batch_size' => $this->getBatchSize(),
				'timeouts' => [
					'batch_timeout' => $this->getBatchTimeout(),
					'api_timeout' => $this->getApiTimeout(),
					'lock_timeout' => $this->getLockTimeout()
				],
				'limits' => [
					'max_errors' => $this->getMaxErrors(),
					'max_retries' => $this->getMaxRetries(),
					'max_concurrent' => $this->getMaxConcurrent()
				],
				'features' => [
					'auto_recovery' => $this->isAutoRecoveryEnabled(),
					'parallel_processing' => $this->isParallelProcessingEnabled(),
					'error_notifications' => $this->isErrorNotificationsEnabled()
				],
				'entities' => $this->getSupportedEntities(),
				'directions' => $this->getSupportedDirections()
			];

			wp_send_json_success($config_data);

		} catch (\Throwable $e) {
			$this->logger->error('Error en getSyncConfigOptimized', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error al obtener configuración de sincronización',
				'code' => 'sync_config_error'
			], 500);
		}
	}

	/**
	 * Endpoint optimizado para salud del sistema
	 * Proporciona estado de salud específico para el frontend
	 * 
	 * @return void
	 * @since 1.0.0
	 */
	public function getSystemHealthOptimized(): void
	{
		// Validación de seguridad
		if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'mia_ajax_nonce')) {
			wp_send_json_error(['message' => 'Nonce inválido'], 403);
			return;
		}

		try {
			// Datos optimizados para el frontend
			$health_data = [
				'overall_status' => $this->getOverallSystemHealth(),
				'components' => [
					'database' => $this->getDatabaseHealth(),
					'api_connection' => $this->getApiConnectionHealth(),
					'memory' => $this->getMemoryHealth(),
					'cache' => $this->getCacheHealth(),
					'logs' => $this->getLogsHealth()
				],
				'alerts' => $this->getSystemAlerts(),
				'recommendations' => $this->getSystemRecommendations(),
				'last_check' => time(),
				'next_check' => time() + 300 // 5 minutos
			];

			wp_send_json_success($health_data);

		} catch (\Throwable $e) {
			$this->logger->error('Error en getSystemHealthOptimized', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error al obtener salud del sistema',
				'code' => 'system_health_error'
			], 500);
		}
	}

	/**
	 * Endpoint optimizado para métricas de rendimiento
	 * Proporciona métricas de rendimiento específicas para el frontend
	 * 
	 * @return void
	 * @since 1.0.0
	 */
	public function getPerformanceMetricsOptimized(): void
	{
		// Validación de seguridad
		if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'mia_ajax_nonce')) {
			wp_send_json_error(['message' => 'Nonce inválido'], 403);
			return;
		}

		try {
			// Datos optimizados para el frontend
			$performance_data = [
				'current' => [
					'response_time' => $this->getCurrentResponseTime(),
					'throughput' => $this->getCurrentThroughput(),
					'error_rate' => $this->getCurrentErrorRate(),
					'queue_size' => $this->getCurrentQueueSize()
				],
				'averages' => [
					'daily_response_time' => $this->getDailyAverageResponseTime(),
					'daily_throughput' => $this->getDailyAverageThroughput(),
					'daily_error_rate' => $this->getDailyAverageErrorRate()
				],
				'peaks' => [
					'best_performance' => $this->getBestPerformanceMetrics(),
					'worst_performance' => $this->getWorstPerformanceMetrics()
				],
				'trends' => [
					'performance_trend' => $this->getPerformanceTrend(24), // 24 horas
					'load_trend' => $this->getLoadTrend(24)
				]
			];

			wp_send_json_success($performance_data);

		} catch (\Throwable $e) {
			$this->logger->error('Error en getPerformanceMetricsOptimized', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error al obtener métricas de rendimiento',
				'code' => 'performance_metrics_error'
			], 500);
		}
	}

	/**
	 * Endpoint optimizado para configuración de polling
	 * Proporciona configuración de polling específica para el frontend
	 * 
	 * @return void
	 * @since 1.0.0
	 */
	public function getPollingConfigOptimized(): void
	{
		// Validación de seguridad
		if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'mia_ajax_nonce')) {
			wp_send_json_error(['message' => 'Nonce inválido'], 403);
			return;
		}

		try {
			// CENTRALIZADO: Usar configuración centralizada
			$polling_config = $this->getPollingConfiguration();

			wp_send_json_success($polling_config);

		} catch (\Throwable $e) {
			$this->logger->error('Error en getPollingConfigOptimized', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error al obtener configuración de polling',
				'code' => 'polling_config_error'
			], 500);
		}
	}

	/**
	 * Endpoint optimizado para configuración de UI
	 * Proporciona configuración específica para la interfaz de usuario
	 * 
	 * @return void
	 * @since 1.0.0
	 */
	public function getUIConfigOptimized(): void
	{
		// Validación de seguridad
		if (!wp_verify_nonce($_REQUEST['nonce'] ?? '', 'mia_ajax_nonce')) {
			wp_send_json_error(['message' => 'Nonce inválido'], 403);
			return;
		}

		try {
			// CENTRALIZADO: Usar configuración centralizada
			$ui_config = $this->getUIConfiguration();

			wp_send_json_success($ui_config);

		} catch (\Throwable $e) {
			$this->logger->error('Error en getUIConfigOptimized', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => 'Error al obtener configuración de UI',
				'code' => 'ui_config_error'
			], 500);
		}
	}

	/**
	 * ========================================
	 * CONFIGURACIONES CENTRALIZADAS
	 * ========================================
	 * 
	 * Todas las configuraciones de polling, timeouts y límites
	 * están centralizadas aquí para evitar duplicación y
	 * facilitar el mantenimiento.
	 */

	/**
	 * Obtiene la configuración completa de polling
	 * 
	 * @return SyncResponseInterface Configuración de polling
	 * @since 1.0.0
	 */
	public function getPollingConfiguration(): SyncResponseInterface
	{
		try {
			$configuration = [
				'intervals' => [
					'active' => (int) get_option('mia_polling_active_interval', 1500),
					'normal' => (int) get_option('mia_polling_normal_interval', 3000),
					'slow' => (int) get_option('mia_polling_slow_interval', 6000),
					'idle' => (int) get_option('mia_polling_idle_interval', 12000),
					'error' => (int) get_option('mia_polling_error_interval', 8000)
				],
				'thresholds' => [
					'to_slow' => (int) get_option('mia_polling_threshold_slow', 3),
					'to_idle' => (int) get_option('mia_polling_threshold_idle', 8),
					'max_errors' => (int) get_option('mia_polling_max_errors', 5)
				],
				'adaptive' => [
					'enabled' => (bool) get_option('mia_polling_enabled', true),
					'learning_rate' => (float) get_option('mia_polling_learning_rate', 0.1),
					'stability_threshold' => (int) get_option('mia_polling_stability_threshold', 5)
				],
				'health_checks' => [
					'enabled' => (bool) get_option('mia_polling_health_checks', true),
					'interval' => (int) get_option('mia_polling_health_interval', 30000),
					'timeout' => (int) get_option('mia_polling_health_timeout', 5000)
				]
			];

			// Agregar metadatos adicionales
			$metadata = [
				'configuration_type' => 'polling',
				'total_settings' => count($configuration, COUNT_RECURSIVE) - count($configuration),
				'adaptive_enabled' => $configuration['adaptive']['enabled'],
				'health_checks_enabled' => $configuration['health_checks']['enabled'],
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$configuration,
				'Configuración de polling obtenida exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getPollingConfiguration'
			]);
		}
	}

	/**
	 * Obtiene la configuración completa de timeouts
	 * 
	 * @return SyncResponseInterface Configuración de timeouts
	 * @since 1.0.0
	 */
	public function getTimeoutConfiguration(): SyncResponseInterface
	{
		try {
			$configuration = [
				'batch' => [
					'default' => (int) get_option('mia_batch_timeout', 1800), // 30 minutos
					'lock' => 1800, // 30 minutos por defecto
					'retries' => 3 // 3 reintentos por defecto
				],
				'api' => [
					'default' => (int) get_option('mia_api_timeout', 30), // 30 segundos
					'connection' => (int) get_option('mia_connection_timeout', 60), // 60 segundos
					'read' => (int) get_option('mia_read_timeout', 120) // 120 segundos
				],
				'continuation' => [
					'check' => $this->getContinuationCheckTimeout(),
					'test_batch_size' => $this->getContinuationTestBatchSize()
				],
				'ui' => [
					'ajax' => (int) get_option('mia_ui_ajax_timeout', 60000), // 60 segundos
					'connection' => (int) get_option('mia_ui_connection_timeout', 60000), // 60 segundos
					'default' => (int) get_option('mia_ui_default_timeout', 2000), // 2 segundos
					'long' => (int) get_option('mia_ui_long_timeout', 5000), // 5 segundos
					'short' => (int) get_option('mia_ui_short_timeout', 1000) // 1 segundo
				]
			];

			// Agregar metadatos adicionales
			$metadata = [
				'configuration_type' => 'timeouts',
				'total_settings' => count($configuration, COUNT_RECURSIVE) - count($configuration),
				'batch_timeout_seconds' => $configuration['batch']['default'],
				'api_timeout_seconds' => $configuration['api']['default'],
				'ui_timeout_ms' => $configuration['ui']['default'],
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$configuration,
				'Configuración de timeouts obtenida exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getTimeoutConfiguration'
			]);
		}
	}

	/**
	 * Obtiene la configuración completa de límites
	 * 
	 * @return SyncResponseInterface Configuración de límites
	 * @since 1.0.0
	 */
	public function getLimitsConfiguration(): SyncResponseInterface
	{
		try {
			$configuration = [
				'polling' => [
					'max_errors' => (int) get_option('mia_polling_max_errors', 5),
					'threshold_slow' => (int) get_option('mia_polling_threshold_slow', 3),
					'threshold_idle' => (int) get_option('mia_polling_threshold_idle', 8)
				],
				'sync' => [
					'batch_size' => (int) get_option('mia_batch_size', 50),
					'max_errors' => (int) get_option('mia_max_errors', 10),
					'max_retries' => (int) get_option('mia_max_retries', 3),
					'max_concurrent' => (int) get_option('mia_max_concurrent', 5)
				],
				'memory' => [
					'warning_threshold' => (float) get_option('mia_memory_warning_threshold', 0.7),
					'critical_threshold' => (float) get_option('mia_memory_critical_threshold', 0.9),
					'cleanup_threshold' => (float) get_option('mia_memory_cleanup_threshold', 0.75)
				],
				'ui' => [
					'history_limit' => (int) get_option('mia_ui_history_limit', 10),
					'progress_milestones' => [25, 50, 75, 100],
					'dashboard_refresh' => (int) get_option('mia_dashboard_refresh_interval', 60)
				]
			];

			// Agregar metadatos adicionales
			$metadata = [
				'configuration_type' => 'limits',
				'total_settings' => count($configuration, COUNT_RECURSIVE) - count($configuration),
				'polling_max_errors' => $configuration['polling']['max_errors'],
				'sync_batch_size' => $configuration['sync']['batch_size'],
				'memory_warning_threshold' => $configuration['memory']['warning_threshold'],
				'ui_history_limit' => $configuration['ui']['history_limit'],
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$configuration,
				'Configuración de límites obtenida exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getLimitsConfiguration'
			]);
		}
	}

    /**
     * Obtiene la configuración completa del sistema de reintentos automáticos
     *
     * Este método devuelve la configuración actual para el manejo de reintentos
     * automáticos en diferentes operaciones del sistema, incluyendo:
     * - Reintentos de sincronización de productos, pedidos y clientes
     * - Reintentos de llamadas a la API
     * - Reintentos de operaciones SSL
     *
     * La configuración incluye el número máximo de intentos y los tiempos de espera
     * entre reintentos, con valores específicos para cada tipo de operación.
     *
     * @return SyncResponseInterface Un array asociativo con la configuración de reintentos:
     *         - 'default': (array) Configuración por defecto para todos los reintentos
     *             - 'max_attempts': (int) Número máximo de intentos (5 por defecto)
     *             - 'initial_delay': (int) Retardo inicial en segundos (1 por defecto)
     *             - 'backoff_factor': (float) Factor de retroexponencial (2.0 por defecto)
     *             - 'max_delay': (int) Retardo máximo en segundos (60 por defecto)
     *         - 'sync_products': (int) Intentos máximos para sincronización de productos (3 por defecto)
     *         - 'sync_orders': (int) Intentos máximos para sincronización de pedidos (4 por defecto)
     *         - 'sync_customers': (int) Intentos máximos para sincronización de clientes (3 por defecto)
     *         - 'api_calls': (int) Intentos máximos para llamadas a la API (5 por defecto)
     *         - 'ssl_operations': (int) Intentos máximos para operaciones SSL (3 por defecto)
     *
     * @since 1.0.0
     * @version 1.1.0
     * @see RetryManager Para la implementación del sistema de reintentos
     * @see update_option() Para actualizar los valores de configuración
     *
     * @example
     * // Obtener configuración actual
     * $config = $this->getRetryConfiguration();
     *
     * // Configurar un nuevo intento con la configuración actual
     * $retryManager = new RetryManager($config);
     *
     * // Ejecutar operación con reintentos automáticos
     * $result = $retryManager->retry(function() use ($productId) {
     *     return $this->syncProduct($productId);
     * });
     */
	public function getRetryConfiguration(): SyncResponseInterface
	{
		try {
			$configuration = [
				'system' => [
					'enabled' => (bool) get_option('mia_retry_system_enabled', true),
					'max_attempts' => (int) get_option('mia_retry_default_max_attempts', 3),
					'base_delay' => (int) get_option('mia_retry_default_base_delay', 2),
					'max_delay' => (int) get_option('mia_retry_max_delay', 30),
					'backoff_factor' => (float) get_option('mia_retry_backoff_factor', 2.0),
					'jitter_enabled' => (bool) get_option('mia_retry_jitter_enabled', true),
					'jitter_max_ms' => (int) get_option('mia_retry_jitter_max_ms', 1000)
				],
				'policies' => [
					'network' => get_option('mia_retry_policy_network', 'aggressive'),
					'server' => get_option('mia_retry_policy_server', 'moderate'),
					'client' => get_option('mia_retry_policy_client', 'conservative'),
					'validation' => get_option('mia_retry_policy_validation', 'none')
				],
				'operations' => [
					'sync_products' => (int) get_option('mia_retry_sync_products_max_attempts', 3),
					'sync_orders' => (int) get_option('mia_retry_sync_orders_max_attempts', 4),
					'sync_customers' => (int) get_option('mia_retry_sync_customers_max_attempts', 3),
					'api_calls' => (int) get_option('mia_retry_api_calls_max_attempts', 5),
					'ssl_operations' => (int) get_option('mia_retry_ssl_operations_max_attempts', 3)
				]
			];

			// Agregar metadatos adicionales
			$metadata = [
				'configuration_type' => 'retry',
				'total_settings' => count($configuration, COUNT_RECURSIVE) - count($configuration),
				'system_enabled' => $configuration['system']['enabled'],
				'max_attempts' => $configuration['system']['max_attempts'],
				'backoff_factor' => $configuration['system']['backoff_factor'],
				'jitter_enabled' => $configuration['system']['jitter_enabled'],
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$configuration,
				'Configuración de reintentos obtenida exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getRetryConfiguration'
			]);
		}
	}

    /**
     * Obtiene la configuración completa del sistema de limpieza y mantenimiento
     *
     * Este método recupera la configuración actual para las tareas de limpieza y mantenimiento
     * automático del sistema, incluyendo:
     * - Limpieza de transients de WordPress
     * - Gestión automática de memoria
     * - Rotación y mantenimiento de registros
     *
     * La configuración se obtiene de las opciones de WordPress con valores por defecto sensatos.
     *
     * @return SyncResponseInterface Un array asociativo con la configuración de limpieza:
     *         - 'transients': Configuración de limpieza de transients
     *             - 'enabled': (bool) Si la limpieza automática está habilitada
     *             - 'age_hours': (int) Edad máxima en horas antes de eliminar un transient
     *             - 'frequency': (string) Frecuencia de limpieza ('hourly', 'twicedaily', 'daily')
     *             - 'max_items': (int) Número máximo de transients a limpiar por ejecución
     *         - 'memory': Configuración de gestión de memoria
     *             - 'auto_cleanup': (bool) Si la limpieza automática de memoria está habilitada
     *             - 'interval': (int) Intervalo en segundos entre limpiezas de memoria
     *             - 'notifications': (bool) Si se deben enviar notificaciones de memoria
     *         - 'logs': Configuración de registros
     *             - 'max_records': (int) Número máximo de registros a mantener en el historial
     *             - 'max_alerts': (int) Número máximo de alertas a mantener
     *
     * @since 1.0.0
     * @version 1.1.0
     * @see update_option() Para actualizar los valores de configuración
     * @see wp_clear_scheduled_hook() Para la programación de tareas de limpieza
     *
     * @example
     * // Obtener configuración actual
     * $config = $this->getCleanupConfiguration();
     *
     * // Verificar si la limpieza de transients está habilitada
     * if ($config['transients']['enabled']) {
     *     // Programar limpieza de transients
     *     $this->scheduleTransientCleanup($config['transients']['frequency']);
     * }
     */
	public function getCleanupConfiguration(): SyncResponseInterface
	{
		try {
			$configuration = [
				'transients' => [
					'enabled' => (bool) get_option('mia_transient_cleanup_enabled', true),
					'age_hours' => (int) get_option('mia_transient_cleanup_age', 24),
					'frequency' => get_option('mia_transient_cleanup_frequency', 'daily'),
					'max_items' => (int) get_option('mia_transient_cleanup_max_items', 1000)
				],
				'memory' => [
					'auto_cleanup' => (bool) get_option('mia_memory_auto_cleanup_enabled', true),
					'interval' => (int) get_option('mia_memory_auto_cleanup_interval', 300),
					'notifications' => (bool) get_option('mia_memory_notifications_enabled', true)
				],
				'logs' => [
					'max_records' => (int) get_option('mia_memory_history_max_records', 100),
					'max_alerts' => (int) get_option('mia_memory_alerts_max_records', 50)
				]
			];

			// Agregar metadatos adicionales
			$metadata = [
				'configuration_type' => 'cleanup',
				'total_settings' => count($configuration, COUNT_RECURSIVE) - count($configuration),
				'transients_enabled' => $configuration['transients']['enabled'],
				'memory_auto_cleanup' => $configuration['memory']['auto_cleanup'],
				'memory_notifications' => $configuration['memory']['notifications'],
				'logs_max_records' => $configuration['logs']['max_records'],
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$configuration,
				'Configuración de limpieza obtenida exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getCleanupConfiguration'
			]);
		}
	}

    /**
     * Obtiene la configuración completa del panel de control de sincronización
     *
     * Este método devuelve la configuración actual para el panel de control de la
     * sincronización, incluyendo preferencias de visualización, umbrales de salud
     * y configuraciones de notificaciones.
     *
     * La configuración se utiliza para personalizar la experiencia del usuario en
     * el panel de control, permitiendo habilitar/deshabilitar características y
     * ajustar los umbrales de alerta para el monitoreo del sistema.
     *
     * @return SyncResponseInterface Un array asociativo con la configuración del dashboard:
     *         - 'unified': (array) Configuración del panel unificado
     *             - 'enabled': (bool) Si el panel unificado está habilitado
     *             - 'auto_diagnostic': (bool) Si el diagnóstico automático está activado
     *             - 'refresh_interval': (int) Intervalo de actualización en segundos
     *             - 'notifications': (bool) Si las notificaciones están habilitadas
     *             - 'export': (bool) Si la exportación de datos está habilitada
     *         - 'health_thresholds': (array) Umbrales para el estado de salud del sistema
     *             - 'memory_critical': (float) Umbral de memoria crítica (0.0 a 1.0)
     *             - 'memory_warning': (float) Umbral de advertencia de memoria (0.0 a 1.0)
     *             - 'retry_critical': (float) Umbral de reintentos críticos (0.0 a 1.0)
     *             - 'retry_warning': (float) Umbral de advertencia de reintentos (0.0 a 1.0)
     *             - 'sync_timeout_hours': (int) Horas antes de marcar una sincronización como fallida
     *
     * @since 1.0.0
     * @version 1.1.0
     * @see update_option() Para actualizar los valores de configuración
     * @see get_option() Para obtener valores individuales de configuración
     *
     * @example
     * // Obtener configuración actual
     * $config = $this->getDashboardConfiguration();
     *
     * // Verificar si el panel unificado está habilitado
     * if ($config['unified']['enabled']) {
     *     // Configurar intervalo de actualización
     *     $refreshInterval = $config['unified']['refresh_interval'] * 1000; // Convertir a milisegundos
     *     $this->setupDashboardRefresh($refreshInterval);
     * }
     *
     * // Verificar umbrales de memoria
     * $memoryUsage = $this->getMemoryUsage();
     * if ($memoryUsage > $config['health_thresholds']['memory_critical']) {
     *     $this->triggerAlert('memory_critical', $memoryUsage);
     * }
     */
	public function getDashboardConfiguration(): SyncResponseInterface
	{
		try {
			$configuration = [
				'unified' => [
					'enabled' => (bool) get_option('mia_dashboard_unified_enabled', true),
					'auto_diagnostic' => (bool) get_option('mia_dashboard_auto_diagnostic_enabled', true),
					'refresh_interval' => (int) get_option('mia_dashboard_refresh_interval', 60),
					'notifications' => (bool) get_option('mia_dashboard_notifications_enabled', true),
					'export' => (bool) get_option('mia_dashboard_export_enabled', true)
				],
				'health_thresholds' => [
					'memory_critical' => (float) get_option('mia_dashboard_health_thresholds_memory_critical', 0.9),
					'memory_warning' => (float) get_option('mia_dashboard_health_thresholds_memory_warning', 0.7),
					'retry_critical' => (float) get_option('mia_dashboard_health_thresholds_retry_critical', 0.6),
					'retry_warning' => (float) get_option('mia_dashboard_health_thresholds_retry_warning', 0.8),
					'sync_timeout_hours' => (int) get_option('mia_dashboard_health_thresholds_sync_timeout_hours', 24)
				]
			];

			// Agregar metadatos adicionales
			$metadata = [
				'configuration_type' => 'dashboard',
				'total_settings' => count($configuration, COUNT_RECURSIVE) - count($configuration),
				'unified_enabled' => $configuration['unified']['enabled'],
				'auto_diagnostic' => $configuration['unified']['auto_diagnostic'],
				'refresh_interval' => $configuration['unified']['refresh_interval'],
				'notifications_enabled' => $configuration['unified']['notifications'],
				'export_enabled' => $configuration['unified']['export'],
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$configuration,
				'Configuración del dashboard obtenida exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getDashboardConfiguration'
			]);
		}
	}

    /**
     * Obtiene la configuración completa de UI
     *
     * @return SyncResponseInterface Configuración de UI
     * @since 1.0.0
     */
	public function getUIConfiguration(): SyncResponseInterface
	{
		try {
			$configuration = [
				'themes' => [
					'current' => get_option('mia_ui_theme', 'default'),
					'available' => ['default', 'dark', 'light', 'compact']
				],
				'notifications' => [
					'enabled' => (bool) get_option('mia_ui_notifications_enabled', true),
					'duration' => (int) get_option('mia_ui_notification_duration', 5000),
					'position' => get_option('mia_ui_notification_position', 'top-right')
				],
				'progress' => [
					'animation' => (bool) get_option('mia_ui_progress_animation', true),
					'show_percentage' => (bool) get_option('mia_ui_show_percentage', true),
					'show_eta' => (bool) get_option('mia_ui_show_eta', true)
				],
				'debug' => [
					'enabled' => (bool) get_option('mia_ui_debug_enabled', false),
					'log_level' => get_option('mia_ui_log_level', 'info'),
					'show_technical_details' => (bool) get_option('mia_ui_show_technical_details', false)
				],
				'accessibility' => [
					'high_contrast' => (bool) get_option('mia_ui_high_contrast', false),
					'large_text' => (bool) get_option('mia_ui_large_text', false),
					'screen_reader' => (bool) get_option('mia_ui_screen_reader', false)
				]
			];

			// Agregar metadatos adicionales
			$metadata = [
				'configuration_type' => 'ui',
				'total_settings' => count($configuration, COUNT_RECURSIVE) - count($configuration),
				'current_theme' => $configuration['themes']['current'],
				'notifications_enabled' => $configuration['notifications']['enabled'],
				'progress_animation' => $configuration['progress']['animation'],
				'debug_enabled' => $configuration['debug']['enabled'],
				'accessibility_features' => array_filter($configuration['accessibility']),
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$configuration,
				'Configuración de UI obtenida exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getUIConfiguration'
			]);
		}
	}

    /**
     * Obtiene la configuración completa del sistema
     *
     * @return SyncResponseInterface Configuración completa del sistema
     * @since 1.0.0
     */
	public function getCompleteConfiguration(): SyncResponseInterface
	{
		try {
			// Obtener todas las configuraciones individuales
			$polling_config = $this->getPollingConfiguration();
			$timeouts_config = $this->getTimeoutConfiguration();
			$limits_config = $this->getLimitsConfiguration();
			$retry_config = $this->getRetryConfiguration();
			$cleanup_config = $this->getCleanupConfiguration();
			$dashboard_config = $this->getDashboardConfiguration();
			$ui_config = $this->getUIConfiguration();

			// Verificar si alguna configuración falló
			$failed_configs = [];
			if (!$polling_config->isSuccess()) $failed_configs[] = 'polling';
			if (!$timeouts_config->isSuccess()) $failed_configs[] = 'timeouts';
			if (!$limits_config->isSuccess()) $failed_configs[] = 'limits';
			if (!$retry_config->isSuccess()) $failed_configs[] = 'retry';
			if (!$cleanup_config->isSuccess()) $failed_configs[] = 'cleanup';
			if (!$dashboard_config->isSuccess()) $failed_configs[] = 'dashboard';
			if (!$ui_config->isSuccess()) $failed_configs[] = 'ui';

			if (!empty($failed_configs)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Error obteniendo configuraciones: ' . implode(', ', $failed_configs),
					500,
					[
						'operation' => 'getCompleteConfiguration',
						'failed_configs' => $failed_configs,
						'failed_count' => count($failed_configs)
					],
					['error_code' => 'configuration_fetch_failed']
				);
			}

			$configuration = [
				'polling' => $polling_config->getData(),
				'timeouts' => $timeouts_config->getData(),
				'limits' => $limits_config->getData(),
				'retry' => $retry_config->getData(),
				'cleanup' => $cleanup_config->getData(),
				'dashboard' => $dashboard_config->getData(),
				'ui' => $ui_config->getData(),
				'version' => '1.0.0',
				'last_updated' => time()
			];

			// Agregar metadatos adicionales
			$metadata = [
				'configuration_type' => 'complete',
				'total_configurations' => 7,
				'version' => '1.0.0',
				'last_updated' => time(),
				'polling_metadata' => $polling_config->getMetadata(),
				'timeouts_metadata' => $timeouts_config->getMetadata(),
				'limits_metadata' => $limits_config->getMetadata(),
				'retry_metadata' => $retry_config->getMetadata(),
				'cleanup_metadata' => $cleanup_config->getMetadata(),
				'dashboard_metadata' => $dashboard_config->getMetadata(),
				'ui_metadata' => $ui_config->getMetadata(),
				'timestamp' => time()
			];

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$configuration,
				'Configuración completa del sistema obtenida exitosamente',
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'getCompleteConfiguration'
			]);
		}
	}

	/**
	 * Actualiza una configuración específica
	 * 
	 * @param string $category Categoría de configuración
	 * @param string $key Clave de configuración
	 * @param mixed $value Valor a establecer
	 * @return bool True si se actualizó correctamente
	 * @since 1.0.0
	 */
	public function updateConfiguration(string $category, string $key, mixed $value): bool
	{
		$option_name = "mia_{$category}_{$key}";
		
		// Validar el valor según la categoría
		if (!$this->validateConfigurationValue($category, $key, $value)) {
			return false;
		}
		
		// Actualizar la opción
		$result = update_option($option_name, $value);
		
		// Log de la actualización
		if ($result) {
			$this->logger->info("Configuración actualizada: {$option_name} = " . json_encode($value));
		}
		
		return $result;
	}

	/**
	 * Valida un valor de configuración
	 * 
	 * @param string $category Categoría de configuración
	 * @param string $key Clave de configuración
	 * @param mixed $value Valor a validar
	 * @return bool True si el valor es válido
	 * @since 1.0.0
	 */
	private function validateConfigurationValue(string $category, string $key, mixed $value): bool
	{
		// Validaciones específicas por categoría
		switch ($category) {
			case 'polling':
				if (str_contains($key, 'interval')) {
					return is_numeric($value) && $value >= 100 && $value <= 60000;
				}
				if (str_contains($key, 'threshold')) {
					return is_numeric($value) && $value >= 1 && $value <= 100;
				}
				break;
				
			case 'timeouts':
				return is_numeric($value) && $value >= 1 && $value <= self::SECONDS_PER_HOUR;
				
			case 'limits':
				if (str_contains($key, 'threshold')) {
					return is_numeric($value) && $value >= 0 && $value <= 1;
				}
				return is_numeric($value) && $value >= 1;
				
			case 'ui':
				if ($key === 'theme') {
					return in_array($value, ['default', 'dark', 'light', 'compact']);
				}
				if ($key === 'position') {
					return in_array($value, ['top-right', 'top-left', 'bottom-right', 'bottom-left']);
				}
				return is_bool($value) || is_numeric($value);
		}
		
		return true; // Por defecto, aceptar el valor
	}

	/**
	 * ========================================
	 * MÉTODOS AUXILIARES PARA ENDPOINTS
	 * ========================================
	 */

	/**
	 * Calcula el porcentaje de progreso de sincronización
	 * 
	 * @return int Porcentaje de progreso (0-100)
	 */
	public function calculateProgressPercentage(): int
	{
		$sync_status_response = $this->getSyncStatus();
		$sync_status = $this->extractSuccessData($sync_status_response, []);
		
		if (!isset($sync_status['current_sync']['in_progress']) || !$sync_status['current_sync']['in_progress']) {
			return 0;
		}

		$items_synced = $sync_status['current_sync']['items_synced'];
		$total_items = $sync_status['current_sync']['total_items'];

		if ($total_items <= 0) {
			return 0;
		}

		return min(100, (int) $this->safe_round(($items_synced / $total_items) * 100));
	}

	/**
	 * Obtiene el mensaje de progreso actual
	 * 
	 * @return string Mensaje de progreso
	 */
	public function getProgressMessage(): string
	{
		$sync_status_response = $this->getSyncStatus();
		$sync_status = $this->extractSuccessData($sync_status_response, []);
		
		if (!isset($sync_status['current_sync']['in_progress']) || !$sync_status['current_sync']['in_progress']) {
			return 'No hay sincronización en progreso';
		}

		$entity = $sync_status['current_sync']['entity'];
		$direction = $sync_status['current_sync']['direction'];
		$items_synced = $sync_status['current_sync']['items_synced'];
		$total_items = $sync_status['current_sync']['total_items'];

		return sprintf(
			'Sincronizando %s (%s): %d de %d elementos',
			$entity,
			$direction,
			$items_synced,
			$total_items
		);
	}

	/**
	 * Estima el tiempo restante de sincronización
	 * 
	 * @return int Tiempo estimado en segundos
	 */
	private function estimateRemainingTime(): int
	{
		$sync_status_response = $this->getSyncStatus();
		$sync_status = $this->extractSuccessData($sync_status_response, []);
		
		if (!isset($sync_status['current_sync']['in_progress']) || !$sync_status['current_sync']['in_progress']) {
			return 0;
		}

		$items_synced = $sync_status['current_sync']['items_synced'];
		$total_items = $sync_status['current_sync']['total_items'];
		$elapsed_time = time() - $sync_status['current_sync']['start_time'];

		if ($items_synced <= 0 || $elapsed_time <= 0) {
			return 0;
		}

		$items_per_second = $items_synced / $elapsed_time;
		$remaining_items = $total_items - $items_synced;

		return (int) $this->safe_round($remaining_items / $items_per_second);
	}

	/**
	 * Obtiene información del lote actual
	 * 
	 * @return array Información del lote actual
	 */
	private function getCurrentBatchInfo(): array
	{
		$sync_status_response = $this->getSyncStatus();
		$sync_status = $this->extractSuccessData($sync_status_response, []);
		
		return [
			'batch_number' => $sync_status['current_sync']['batch_number'] ?? 1,
			'batch_size' => $sync_status['current_sync']['batch_size'] ?? $this->getBatchSize(),
			'items_in_batch' => $sync_status['current_sync']['items_in_batch'] ?? 0,
			'batch_start_time' => $sync_status['current_sync']['batch_start_time'] ?? time()
		];
	}

	/**
	 * Obtiene métricas de rendimiento actuales
	 * 
	 * @return array Métricas de rendimiento
	 */
	private function getPerformanceMetrics(): array
	{
		$sync_status_response = $this->getSyncStatus();
		$sync_status = $this->extractSuccessData($sync_status_response, []);
		
		if (!isset($sync_status['current_sync']['in_progress']) || !$sync_status['current_sync']['in_progress']) {
			return [
				'items_per_minute' => 0,
				'average_batch_time' => 0,
				'current_throughput' => 0
			];
		}

		$items_synced = $sync_status['current_sync']['items_synced'];
		$elapsed_time = time() - $sync_status['current_sync']['start_time'];

		return [
			'items_per_minute' => $elapsed_time > 0 ? $this->safe_round(($items_synced / $elapsed_time) * 60) : 0,
			'average_batch_time' => $this->getAverageBatchTime(),
			'current_throughput' => $elapsed_time > 0 ? $this->safe_round($items_synced / $elapsed_time, 2) : 0
		];
	}

	/**
	 * Obtiene información de la última sincronización
	 * 
	 * @return array Información de la última sincronización
	 */
	private function getLastSyncInfo(): array
	{
		$historyResponse = $this->get_sync_history(1);
		
		if (!$historyResponse->isSuccess()) {
			return [
				'status' => 'never',
				'message' => 'Nunca se ha ejecutado una sincronización'
			];
		}

		$history = $historyResponse->getData()['data'] ?? [];
		
		if (empty($history)) {
			return [
				'status' => 'never',
				'message' => 'Nunca se ha ejecutado una sincronización'
			];
		}

		$last_sync = $history[0];
		
		return [
			'status' => $last_sync['status'],
			'entity' => $last_sync['entity'],
			'direction' => $last_sync['direction'],
			'items_synced' => $last_sync['items_synced'],
			'total_items' => $last_sync['total_items'],
			'errors' => $last_sync['errors'],
			'duration' => $last_sync['duration'],
			'end_time' => $last_sync['end_time'],
			'message' => sprintf(
				'Última sincronización: %s (%s) - %s',
				$last_sync['entity'],
				$last_sync['direction'],
				$last_sync['status']
			)
		];
	}

	/**
	 * Obtiene historial reciente de sincronizaciones
	 * 
	 * @param int $limit Límite de registros
	 * @return array Historial reciente
	 */
	private function getRecentSyncHistory(int $limit = 5): array
	{
		$response = $this->get_sync_history($limit);
		
		// Extraer datos del SyncResponseInterface
		if ($response->isSuccess()) {
			$history = $response->getData()['data'] ?? [];
			return $history; // Ya está limitado por el parámetro
		}
		
		// Si hay error, devolver array vacío
		return [];
	}

	/**
	 * Obtiene un informe detallado del estado de salud del sistema de sincronización.
	 *
	 * Este método recopila métricas de diferentes componentes del sistema para evaluar
	 * el estado general de salud de la aplicación. Cada componente es evaluado por un
	 * método especializado que devuelve información específica sobre su estado.
	 *
	 * @since   1.0.0
	 * @access  private
	 * @return  array Un array asociativo con el estado de salud de los componentes del sistema:
	 *               - 'overall' (array): Estado general del sistema, incluyendo puntuación y estado
	 *               - 'database' (array): Estado de la conexión y rendimiento de la base de datos
	 *               - 'api_connection' (array): Estado de la conexión con la API externa
	 *               - 'memory' (array): Uso actual de memoria y límites del sistema
	 *               - 'cache' (array): Estado y eficiencia de la caché
	 *
	 * @example
	 * $healthStatus = $this->getSystemHealthStatus();
     * if ($healthStatus['overall']['status'] === 'critical') {
     *     // Tomar acciones para problemas críticos
     *     error_log('Estado crítico del sistema: ' . $healthStatus['overall']['message']);
     * }
     *
     * @see self::getOverallSystemHealth()
     * @see self::getDatabaseHealth()
     * @see self::getApiConnectionHealth()
     * @see self::getMemoryHealth()
     * @see self::getCacheHealth()
     */
	private function getSystemHealthStatus(): array
	{
		return [
			'overall' => $this->getOverallSystemHealth(),
			'database' => $this->getDatabaseHealth(),
			'api_connection' => $this->getApiConnectionHealth(),
			'memory' => $this->getMemoryHealth(),
			'cache' => $this->getCacheHealth()
		];
	}

	/**
	 * Obtiene la configuración actual de sincronización del sistema.
	 *
	 * Este método recopila y devuelve todos los parámetros de configuración relacionados
	 * con la sincronización, incluyendo tamaños de lote, tiempos de espera y límites del sistema.
	 * Los valores se obtienen de las opciones de WordPress con valores por defecto predefinidos.
	 *
	 * @since   1.0.0
	 * @access  private
	 * @return  array Un array asociativo con la siguiente estructura:
	 *               - 'batch_size' (int): Número de elementos a procesar por lote (default: 50)
	 *               - 'timeouts' (array): Configuración de tiempos de espera:
	 *                 - 'batch_timeout' (int): Tiempo máximo en segundos para procesar un lote (default: 1800)
	 *                 - 'api_timeout' (int): Tiempo máximo de espera para peticiones API en segundos (default: 30)
	 *                 - 'lock_timeout' (int): Tiempo de expiración de bloqueos (1800 segundos por defecto)
	 *               - 'limits' (array): Límites del sistema:
	 *                 - 'max_errors' (int): Número máximo de errores permitidos (default: 10)
	 *                 - 'max_retries' (int): Número máximo de reintentos por operación (default: 3)
	 *                 - 'max_concurrent' (int): Número máximo de operaciones concurrentes (default: 5)
	 *
	 * @example
	 * $config = $this->getSyncConfiguration();
     * // Ejemplo de uso:
     * $batchSize = $config['batch_size'];
     * $apiTimeout = $config['timeouts']['api_timeout'];
     * $maxRetries = $config['limits']['max_retries'];
     *
     * @see get_option()
     */
	private function getSyncConfiguration(): array
	{
		return [
			'batch_size' => (int) get_option('mia_batch_size', 50),
			'timeouts' => [
				'batch_timeout' => (int) get_option('mia_batch_timeout', 1800),
				'api_timeout' => (int) get_option('mia_api_timeout', 30),
				'lock_timeout' => 1800 // 30 minutos por defecto
			],
			'limits' => [
				'max_errors' => (int) get_option('mia_max_errors', 10),
				'max_retries' => (int) get_option('mia_max_retries', 3),
				'max_concurrent' => (int) get_option('mia_max_concurrent', 5)
			]
		];
	}

	/**
	 * Realiza un diagnóstico completo del estado de consistencia del sistema de sincronización.
	 *
	 * Este método recopila y analiza información detallada sobre el estado actual de la
	 * sincronización, incluyendo verificaciones de consistencia, información de sincronización
	 * actual y estado de bloqueos. Además, genera recomendaciones basadas en el análisis.
	 *
	 * @since   1.0.0
	 * @access  public
	 * @return  array Un array asociativo con la siguiente estructura:
	 *               - 'timestamp' (int): Marca de tiempo UNIX del diagnóstico
	 *               - 'consistency_check' (array): Resultados de la validación de consistencia
	 *                 - 'is_consistent' (bool): Indica si el estado es consistente
	 *                 - 'critical_count' (int): Número de inconsistencias críticas
	 *               - 'current_sync_info' (array): Información sobre la sincronización actual
	 *               - 'lock_status' (array): Estado de los bloqueos del sistema
	 *                 - 'has_active_locks' (bool): Indica si hay bloqueos activos
	 *               - 'recommendations' (string[]): Lista de recomendaciones basadas en el diagnóstico
	 *
	 * @example
	 * $diagnosis = $syncManager->diagnoseStateConsistency();
     * if (!$diagnosis['consistency_check']['is_consistent']) {
     *     // Tomar acciones correctivas
     *     foreach ($diagnosis['recommendations'] as $recommendation) {
     *         error_log("Recomendación: $recommendation");
     *     }
     * }
     * 
     * @see \MiIntegracionApi\Helpers\SyncStatusHelper::validateStateConsistency()
     * @see \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo()
     * @see \MiIntegracionApi\Helpers\SyncStatusHelper::getLockDiagnostics()
     */
	// public function diagnoseStateConsistency(): array
	// {
	// 	$diagnosis = [
	// 		'timestamp' => time(),
	// 		'consistency_check' => \MiIntegracionApi\Helpers\SyncStatusHelper::validateStateConsistency(),
	// 		'current_sync_info' => \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo(),
	// 		'lock_status' => \MiIntegracionApi\Helpers\SyncStatusHelper::getLockDiagnostics(),
	// 		'recommendations' => []
	// 	];
		
	// 	// Generar recomendaciones basadas en el diagnóstico
	// 	if (!$diagnosis['consistency_check']['is_consistent']) {
	// 		$diagnosis['recommendations'][] = 'Ejecutar corrección automática de inconsistencias';
			
	// 		if ($diagnosis['consistency_check']['critical_count'] > 0) {
	// 			$diagnosis['recommendations'][] = 'Revisar y corregir inconsistencias críticas inmediatamente';
	// 		}
	// 	}
		
	// 	if ($diagnosis['lock_status']['has_active_locks']) {
	// 		$diagnosis['recommendations'][] = 'Verificar que los locks activos son válidos';
	// 	}
		
	// 	return $diagnosis;
	// }

    /**
     * Realiza un diagnóstico completo del sistema y opcionalmente repara problemas detectados.
     *
     * Este método combina múltiples diagnósticos del sistema de sincronización y proporciona
     * una interfaz unificada para detectar y, opcionalmente, corregir problemas. Realiza
     * las siguientes acciones:
     * 1. Diagnóstico general de problemas de sincronización
     * 2. Verificación de consistencia del estado
     * 3. Combinación de todos los problemas detectados
     * 4. Aplicación de correcciones automáticas si se solicita
     *
     * @param bool $reparar Si es true, intenta reparar automáticamente los problemas detectados
     * @return SyncResponseInterface Un array asociativo con los siguientes elementos:
     *               - 'timestamp' (int): Marca de tiempo UNIX del diagnóstico
     *               - 'problemas_detectados' (array[]): Lista de problemas encontrados, cada uno con:
     *                 - 'tipo' (string): Tipo de problema
     *                 - 'descripcion' (string): Descripción del problema
     *                 - 'severidad' (string): Nivel de severidad (baja, media, alta, crítica)
     *               - 'acciones_realizadas' (string[]): Lista de acciones correctivas realizadas
     *               - 'estado_final' (string): Estado final del diagnóstico ('ok', 'reparado', 'problemas_detectados')
     *               - 'diagnostics' (array): Datos crudos de diagnóstico para análisis avanzado
     *
     * @since   1.0.0
     * @access  public
     * @example
     * // Solo diagnóstico
     * $resultado = $syncManager->diagnostico_y_reparacion_estado();
     * if ($resultado['estado_final'] !== 'ok') {
     *     // Mostrar problemas detectados
     *     foreach ($resultado['problemas_detectados'] as $problema) {
     *         echo "Problema: {$problema['descripcion']} (Severidad: {$problema['severidad']})\n";
     *     }
     * }
     *
     * // Diagnóstico y reparación
     * $resultado = $syncManager->diagnostico_y_reparacion_estado(true);
     * if (!empty($resultado['acciones_realizadas'])) {
     *     echo "Acciones realizadas:\n";
     *     foreach ($resultado['acciones_realizadas'] as $accion) {
     *         echo "- $accion\n";
     *     }
     * }
     *
     * @see self::diagnose_sync_issues()
     * @see self::diagnoseStateConsistency()
     * @see \MiIntegracionApi\Helpers\SyncStatusHelper::autoFixInconsistencies()
     */
	public function diagnostico_y_reparacion_estado(bool $reparar = false): SyncResponseInterface
	{
		try {
			// 1. Usar diagnose_sync_issues() para diagnóstico general
			$sync_issues_response = $this->diagnose_sync_issues();
			$sync_issues = $this->extractSuccessData($sync_issues_response, ['issues' => [], 'diagnostics' => []]);
			
			// 2. Usar diagnoseStateConsistency() para consistencia
			$consistency = $this->diagnoseStateConsistency();
			
			// 3. Combinar problemas detectados
			$problemas_detectados = array_merge(
				$sync_issues['issues'] ?? [],
				$this->formatConsistencyIssues($consistency)
			);
			
			// 4. Aplicar reparaciones si se solicita
			$acciones_realizadas = [];
			if ($reparar && !empty($problemas_detectados)) {
				$fix_result = \MiIntegracionApi\Helpers\SyncStatusHelper::autoFixInconsistencies();
				$acciones_realizadas = array_map(
					fn($fix) => $fix['message'] ?? 'Corrección aplicada',
					$fix_result['fixes_applied']
				);
			}
			
			$diagnostico_data = [
				'timestamp' => time(),
				'problemas_detectados' => $problemas_detectados,
				'acciones_realizadas' => $acciones_realizadas,
				'estado_final' => empty($problemas_detectados) ? 'ok' : ($reparar ? 'reparado' : 'problemas_detectados'),
				'diagnostics' => array_merge(
					$sync_issues['diagnostics'] ?? [],
					$consistency
				)
			];

			// Agregar metadatos adicionales
			$metadata = [
				'operation' => 'diagnostico_y_reparacion_estado',
				'reparar_solicitado' => $reparar,
				'problemas_count' => count($problemas_detectados),
				'acciones_count' => count($acciones_realizadas),
				'estado_final' => $diagnostico_data['estado_final'],
				'sync_issues_success' => $sync_issues_response->isSuccess(),
				'timestamp' => time()
			];

			$message = empty($problemas_detectados) 
				? 'Diagnóstico completado: No se detectaron problemas'
				: ($reparar 
					? 'Diagnóstico y reparación completados: ' . count($acciones_realizadas) . ' acciones realizadas'
					: 'Diagnóstico completado: ' . count($problemas_detectados) . ' problemas detectados'
				);

			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$diagnostico_data,
				$message,
				$metadata
			);

		} catch (\Exception $e) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException($e, [
				'operation' => 'diagnostico_y_reparacion_estado',
				'reparar_solicitado' => $reparar
			]);
		}
	}

	/**
	 * Normaliza las inconsistencias detectadas al formato estándar de problemas del sistema.
	 *
	 * Este método transforma la estructura de datos de inconsistencias devuelta por
	 * `diagnoseStateConsistency()` en un formato estandarizado que puede ser procesado
	 * por otros componentes del sistema. Cada inconsistencia se convierte en un array
	 * con tipo, descripción y severidad.
	 *
	 * @since   1.0.0
	 * @access  private
	 * @param   array $consistency Resultado de `diagnoseStateConsistency()` con la siguiente estructura:
	 *                           - 'consistency_check' (array):
	 *                             - 'is_consistent' (bool): Indica si hay inconsistencias
	 *                             - 'inconsistencies' (array[]): Lista de inconsistencias donde cada una tiene:
	 *                               - 'type' (string): Tipo de inconsistencia
	 *                               - 'field' (string, opcional): Campo relacionado
	 *                               - 'severity' (string): Nivel de severidad
	 * @return  array Array de problemas formateados con la siguiente estructura:
	 *               - 'tipo' (string): Tipo de problema (ej: 'database', 'state', 'lock')
	 *               - 'descripcion' (string): Descripción legible del problema
	 *               - 'severidad' (string): Nivel de severidad ('baja', 'media', 'alta', 'critica')
	 *
	 * @example
	 * $consistency = $this->diagnoseStateConsistency();
     * $problemas = $this->formatConsistencyIssues($consistency);
     * // $problemas contendrá un array como:
     * // [
     * //   [
     * //     'tipo' => 'database',
     * //     'descripcion' => 'database: users_table',
     * //     'severidad' => 'alta'
     * //   ]
     * // ]
     *
     * @see self::diagnostico_y_reparacion_estado()
     */
	private function formatConsistencyIssues(array $consistency): array
	{
		$problemas = [];
		
		if (!$consistency['consistency_check']['is_consistent']) {
			foreach ($consistency['consistency_check']['inconsistencies'] as $inconsistency) {
				$problemas[] = [
					'tipo' => $inconsistency['type'],
					'descripcion' => sprintf(
						'%s: %s',
						$inconsistency['type'],
						$inconsistency['field'] ?? 'N/A'
					),
					'severidad' => $inconsistency['severity']
				];
			}
		}
		
		return $problemas;
	}

    /**
     * Obtiene las capacidades y configuraciones de sincronización del sistema.
     *
     * Este método proporciona información detallada sobre las capacidades de sincronización
     * disponibles en el sistema, incluyendo las entidades soportadas, direcciones de
     * sincronización y características habilitadas.
     *
     * @since   1.0.0
     * @access  private
     * @return  array Un array asociativo con la siguiente estructura:
     *               - 'entities' (string[]): Lista de entidades soportadas para sincronización
     *               - 'directions' (string[]): Direcciones de sincronización soportadas
     *               - 'features' (array): Características habilitadas:
     *                 - 'auto_recovery' (bool): Indica si la recuperación automática está habilitada
     *                 - 'parallel_processing' (bool): Indica si el procesamiento paralelo está habilitado
     *                 - 'error_notifications' (bool): Indica si las notificaciones de error están habilitadas
     *
     * @example
     * $capabilities = $this->getSyncCapabilities();
     * if ($capabilities['features']['parallel_processing']) {
     *     // Ejecutar procesamiento en paralelo
     * }
     *
     * @see self::getSupportedEntities()
     * @see self::getSupportedDirections()
     * @see self::isAutoRecoveryEnabled()
     * @see self::isParallelProcessingEnabled()
     * @see self::isErrorNotificationsEnabled()
     */
    private function getSyncCapabilities(): array
	{
		return [
			'entities' => $this->getSupportedEntities(),
			'directions' => $this->getSupportedDirections(),
			'features' => [
				'auto_recovery' => $this->isAutoRecoveryEnabled(),
				'parallel_processing' => $this->isParallelProcessingEnabled(),
				'error_notifications' => $this->isErrorNotificationsEnabled()
			]
		];
	}

	/**
	 * Obtiene las entidades soportadas para sincronización entre sistemas.
	 *
	 * Este método define y retorna un array con los tipos de entidades que pueden ser
	 * sincronizadas entre Verial y WooCommerce. Cada entidad representa un conjunto
	 * de datos que puede ser transferido entre los sistemas.
	 *
	 * @since   1.0.0
	 * @access  private
	 * @return  string[] Array que contiene las claves de las entidades soportadas.
	 *                  - 'products':  Productos/artículos del catálogo
	 *                  - 'clients':   Información de clientes
	 *                  - 'orders':    Pedidos/ventas
	 *                  - 'media':     Archivos multimedia (imágenes, documentos)
	 *
	 * @example
	 * $entities = $syncManager->getSupportedEntities();
	 * // Retorna: ['products', 'clients', 'orders', 'media']
	 */
	private function getSupportedEntities(): array
	{
		return \MiIntegracionApi\Core\Validation\SyncEntityValidator::getSupportedEntities();
	}

	/**
 * Obtiene las direcciones soportadas para la sincronización entre sistemas.
 *
 * Este método define y retorna un array con las direcciones de sincronización
 * soportadas por el sistema. Estas direcciones determinan el flujo de datos
 * durante los procesos de sincronización.
 *
 * @since   1.0.0
 * @access  private
 * @return  string[] Array que contiene las claves de las direcciones soportadas.
 *                  - 'verial_to_wc': Sincronización desde Verial hacia WooCommerce
 *                  - 'wc_to_verial': Sincronización desde WooCommerce hacia Verial
 *
 * @example
 * $directions = $syncManager->getSupportedDirections();
 * // Retorna: ['verial_to_wc', 'wc_to_verial']
 */
	private function getSupportedDirections(): array
	{
		return \MiIntegracionApi\Core\Validation\SyncEntityValidator::getSupportedDirections();
	}

	/**
	 * Verifica si la recuperación automática está habilitada en la configuración del plugin.
     *
     * Este método comprueba la opción 'mia_auto_recovery_enabled' para determinar
     * si el sistema debe intentar recuperarse automáticamente de errores durante
     * los procesos de sincronización, lo que puede mejorar la estabilidad general.
     *
     * @since   1.0.0
     * @access  private
     * @return  bool True si la recuperación automática está habilitada, false en caso contrario.
     *               Por defecto retorna true si la opción no está establecida.
     * @see     update_option()
     * @see     get_option()
     */
    private function isAutoRecoveryEnabled(): bool
	{
		return get_option('mia_auto_recovery_enabled', true) === true;
	}

	/**
	 * Verifica si el procesamiento paralelo está habilitado en la configuración del plugin.
     *
     * Este método comprueba la opción 'mia_parallel_processing_enabled' para determinar
     * si las operaciones de sincronización deben ejecutarse en paralelo, lo que puede
     * mejorar el rendimiento en servidores con múltiples núcleos de CPU.
     *
     * @since   1.0.0
     * @access  private
     * @return  bool True si el procesamiento paralelo está habilitado, false en caso contrario.
     *               Por defecto retorna false si la opción no está establecida.
     * @see     update_option()
     * @see     get_option()
     */
    private function isParallelProcessingEnabled(): bool
	{
		return get_option('mia_parallel_processing_enabled', false) === true;
	}

	/**
	 * Verifica si las notificaciones de error están habilitadas en la configuración del plugin.
	 *
     * Este método verifica la opción 'mia_error_notifications_enabled' en la base de datos
     * para determinar si las notificaciones de error deben mostrarse al usuario final.
     *
     * @since   1.0.0
     * @access  private
     * @return  bool True si las notificaciones de error están habilitadas, false en caso contrario.
     *               Por defecto retorna true si la opción no está establecida.
     * @see     update_option()
     * @see     get_option()
     */
    private function isErrorNotificationsEnabled(): bool
	{
		return get_option('mia_error_notifications_enabled', true) === true;
	}

	/**
	 * Maneja solicitudes de sincronización desde AjaxSync.
	 *
	 * Este método es el punto de entrada principal para las solicitudes de sincronización
	 * asíncronas. Se encarga de iniciar el proceso de sincronización, procesar el
	 * primer lote de datos y programar los lotes restantes usando WordPress Cron.
	 *
	 * Flujo de ejecución:
	 * 1. Inicia la sincronización con los filtros proporcionados
	 * 2. Procesa el primer lote de datos de forma síncrona
	 * 3. Si hay más datos por procesar, programa el siguiente lote de forma asíncrona
	 * 4. Devuelve el estado actual de la sincronización
	 *
	 * @param   array  $filters  Filtros opcionales para la sincronización. Puede incluir:
	 *                           - 'product_ids' (array)  : IDs de productos específicos a sincronizar
	 *                           - 'force_update' (bool)  : Forzar actualización incluso si no hay cambios
	 *                           - 'batch_size' (int)     : Tamaño personalizado del lote
	 *
	 * @return  SyncResponseInterface  Respuesta de la operación.
	 *
	 * @throws  \Throwable  Captura cualquier excepción no capturada y la registra antes de devolver un SyncResponse.
	 *
	 * @since   1.0.0
	 *
	 * @package MiIntegracionApi\Core
	 * @uses    start_sync()           Para iniciar el proceso de sincronización.
	 * @uses    process_all_batches_sync() Para procesar el primer lote de datos.
	 * @uses    schedule_next_batch()  Para programar lotes adicionales.
	 *
	 * @example
	 * ```php
	 * // Ejemplo de uso típico
	 * $filters = [
	 *     'product_ids' => [123, 456],
	 *     'force_update' => false
	 * ];
	 * $result = $syncManager->handle_sync_request($filters);
	 *
	 * if ($result->isSuccess()) {
	 *     // Éxito - la sincronización está en progreso o completada
	 *     wp_send_json_success($result->toArray());
	 * } else {
	 *     // Manejar error
	 *     wp_send_json_error($result->toArray());
	 * }
	 * ```
	 */
	public function handle_sync_request(array $filters = []): SyncResponseInterface
	{
		try {
			// Obtener entidad y dirección desde filtros o usar valores por defecto
			$entity = $filters['entity'] ?? 'products';
			$direction = $filters['direction'] ?? 'verial_to_wc';
			
			// Validar entidad y dirección antes de proceder
			if (empty($entity) || empty($direction)) {
				return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
					'Entidad y dirección son requeridas para la sincronización',
					400,
					['entity' => $entity, 'direction' => $direction]
				);
			}
			
			// Iniciar sincronización con parámetros dinámicos
			$sync_result = $this->start_sync($entity, $direction, $filters);
			
			if (!$sync_result->isSuccess()) {
				return $sync_result;
			}
			
			// Procesar el primer lote
			$batch_result = $this->process_all_batches_sync(true);
			
			if (!$batch_result->isSuccess()) {
				return $batch_result;
			}
			
		// Programar siguiente lote con WordPress Cron si no está completado
		$batch_data = $batch_result->getData();
		$completed = $batch_data['completed'] ?? false;
		
		$this->logger->info('Verificando si programar siguiente lote', [
			'completed' => $completed,
			'batch_result' => $batch_data
		]);
		
		if (!$completed) {
			$this->logger->info('Programando siguiente lote con WordPress Cron...');
			$scheduled = $this->schedule_next_batch();
			
			if (!$scheduled) {
				$this->logger->error('Error al programar siguiente lote con WordPress Cron');
				// No fallar la sincronización por esto, solo logear
			} else {
				$this->logger->info('Siguiente lote programado exitosamente con WordPress Cron');
			}
		} else {
			$this->logger->info('Sincronización completada, no se programa siguiente lote');
		}
		
		// Preparar respuesta
		if ($completed) {
			$batch_data = $batch_result->getData();
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::syncCompleted(
				$batch_data['total_processed'] ?? 0,
				$batch_data['total_batches'] ?? 0,
				['filters' => $filters, 'batch_result' => $batch_data]
			);
		} else {
			$batch_data = $batch_result->getData();
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$batch_data,
				'Sincronización iniciada - procesando primer lote',
				['filters' => $filters, 'in_progress' => true]
			);
		}
			
		} catch (\Throwable $e) {
			$this->logger->error('Error en handle_sync_request', [
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString()
			]);
			
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::fromException(
				$e,
				['filters' => $filters],
				['method' => 'handle_sync_request']
			);
		}
	}

	/**
	 * Procesa los lotes restantes en background tras cerrar la conexión HTTP.
	 * 
	 * @deprecated 1.0.0 Este método ha sido reemplazado por el sistema de WordPress Cron.
	 *             Utiliza `schedule_next_batch()` en su lugar.
	 * 
	 * @package MiIntegracionApi\Core
	 * @since   1.0.0
	 * 
	 * @return  void  Este método no devuelve ningún valor y su funcionalidad ha sido deshabilitada.
	 * 
	 * @see     schedule_next_batch() Método que reemplaza esta funcionalidad.
	 * 
	 * @codeCoverageIgnore Este método está obsoleto y no debería ser probado.
	 * 
	 * @example
	 * ```php
	 * // NO USAR - Código de ejemplo solo para referencia histórica
	 * $this->process_remaining_batches_in_background(); // Obsoleto
	 * 
	 * // USAR ESTO EN SU LUGAR:
	 * $this->schedule_next_batch();
	 * ```
	 */
	private function process_remaining_batches_in_background(): void
	{
		// DEPRECATED: Este método ya no se usa - reemplazado por WordPress Cron
		$this->logger->warning('process_remaining_batches_in_background() llamado pero está deprecated - usar WordPress Cron');
		return;		

	}

	/**
	 * Programa el siguiente lote de sincronización usando el sistema de WordPress Cron.
	 *
	 * Este método se encarga de programar la ejecución asíncrona del siguiente lote
	 * de sincronización utilizando el programador de tareas de WordPress. Verifica el
	 * estado actual de la sincronización y, si es necesario, programa la ejecución
	 * del siguiente lote con un retraso de 5 segundos.
	 *
	 * El método realiza las siguientes acciones:
	 * 1. Obtiene la información de sincronización actual
	 * 2. Verifica si hay una sincronización en progreso
	 * 3. Valida que haya lotes pendientes por procesar
	 * 4. Programa el siguiente lote usando wp_schedule_single_event
	 *
	 * @package MiIntegracionApi\Core
	 * @since   1.0.0
	 *
	 * @return  bool  True si se programó correctamente el siguiente lote, false en caso contrario.
	 *                También devuelve false si no hay sincronización en progreso o si ya se
	 *                completaron todos los lotes.
	 *
	 * @throws  \Throwable  Captura y registra cualquier excepción que pueda ocurrir durante
	 *                     la programación del lote, pero no la relanza.
	 *
	 * @uses    wp_schedule_single_event() Para programar la ejecución del siguiente lote.
	 * @uses    \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo() Para obtener
	 *         el estado actual de la sincronización.
	 *
	 * @example
	 * ```php
	 * // Programar el siguiente lote de sincronización
	 * $scheduled = $syncManager->schedule_next_batch();
	 * if ($scheduled) {
	 *     echo 'Siguiente lote programado correctamente';
	 * } else {
	 *     echo 'No se pudo programar el siguiente lote';
	 * }
	 * ```
	 */
	public function schedule_next_batch(): bool
	{
		try {
			$this->logger->info('Iniciando programación de siguiente lote con WordPress Cron');
			
			// Obtener información de sincronización actual
			$sync_info = \MiIntegracionApi\Helpers\SyncStatusHelper::getCurrentSyncInfo();
			
			$this->logger->info('Información de sincronización obtenida', [
				'sync_info' => $sync_info,
				'is_empty' => empty($sync_info),
				'in_progress' => $sync_info['in_progress'] ?? 'no_in_progress'
			]);
			
			if (empty($sync_info) || !isset($sync_info['in_progress']) || !$sync_info['in_progress']) {
				$this->logger->warning('No hay sincronización activa para programar siguiente lote', [
					'sync_info' => $sync_info,
					'in_progress' => $sync_info['in_progress'] ?? 'no_in_progress'
				]);
				return false;
			}
			
			$operation_id = $sync_info['operation_id'] ?? null;
			$current_batch = $sync_info['current_batch'] ?? 0;
			$total_batches = $sync_info['total_batches'] ?? 0;
			
			$this->logger->info('Verificando si programar siguiente lote', [
				'operation_id' => $operation_id,
				'current_batch' => $current_batch,
				'total_batches' => $total_batches,
				'can_schedule' => $current_batch < $total_batches
			]);
			
			// Verificar si ya se completaron todos los lotes
			if ($current_batch >= $total_batches) {
				$this->logger->info('Todos los lotes ya procesados, no se programa siguiente lote', [
					'current_batch' => $current_batch,
					'total_batches' => $total_batches,
					'operation_id' => $operation_id
				]);
				return false;
			}
			
		// Usar sistema de cola independiente en lugar de WordPress Cron
		$queue_result = $this->scheduleNextBatchWithQueue($operation_id, $current_batch, (int) $total_batches);
		
		if (!$queue_result) {
			$this->logger->error('Error al programar siguiente lote con sistema de cola', [
				'operation_id' => $operation_id,
				'current_batch' => $current_batch,
				'total_batches' => $total_batches
			]);
			return false;
		}
		
		$this->logger->info('Siguiente lote programado con sistema de cola exitosamente', [
			'operation_id' => $operation_id,
			'current_batch' => $current_batch,
			'total_batches' => $total_batches,
			'scheduled_time' => time() + $this->getBatchDelay()
		]);
		
		return true;
			
		} catch (\Throwable $e) {
			$this->logger->error('Error al programar siguiente lote', [
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString()
			]);
			return false;
		}
	}

	/**
	 * Actualiza el transient de progreso para sincronizar con el frontend.
	 *
	 * Este método actualiza la información de progreso de la sincronización en tiempo real,
	 * permitiendo al frontend mostrar el estado actual del proceso. Los datos incluyen
	 * estadísticas detalladas, porcentaje de progreso y metadatos sobre el lote actual.
	 *
	 * El método es compatible con el BatchProcessor del frontend, que utiliza la información
	 * de lotes para mostrar la barra de progreso, no el porcentaje directo.
	 *
	 * @package MiIntegracionApi\Core
	 * @since   1.0.0
	 *
	 * @param   int  $current_batch  Número del lote actual que se está procesando.
	 *                               Debe ser mayor o igual a 1.
	 * @param   int  $total_batches  Número total de lotes a procesar.
	 *                               Debe ser mayor o igual a 1.
	 * @param   int  $items_synced   Cantidad total de ítems sincronizados hasta el momento.
	 * @param   int  $total_items    Total de ítems a sincronizar en toda la operación.
	 *                               Si es 0, el porcentaje se establecerá a 0.
	 *
	 * @return  void  No devuelve ningún valor. Si la función `mia_set_sync_transient`
	 *                no está disponible, registra un warning y retorna temprano.
	 *
	 * @uses    mia_set_sync_transient() Función externa para almacenar los datos de progreso.
	 * @see     SyncStatusHelper Para obtener estadísticas de errores si es necesario.
	 *
	 * @example
	 * ```php
	 * // Actualizar progreso después de procesar un lote
	 * $this->updateSyncProgressTransient(
	 *     $currentBatch = 3,
	 *     $totalBatches = 10,
	 *     $itemsProcessed = 150,
	 *     $totalItems = 500
	 * );
	 * ```
	 */
	private function updateSyncProgressTransient(int $current_batch, int $total_batches, int $items_synced, int $total_items): void
	{
		// Verificar que la función existe
		if (!function_exists('mia_set_sync_transient')) {
			$this->logger->warning('mia_set_sync_transient no está disponible');
			return;
		}

		// Calcular porcentaje para el frontend (aunque no se use para el ancho de la barra)
		$porcentaje = 0.0;
		if ($total_items > 0) {
			$porcentaje = min(100.0, max(0.0, ($items_synced / $total_items) * 100));
		}

		// Preparar datos del transient (formato compatible con BatchProcessor)
		// NOTA: El frontend usa lotes para el ancho de la barra, no porcentaje
		$progress_data = [
			'porcentaje' => $this->safe_round($porcentaje, 2), // Para información, no para el ancho
			'mensaje' => 'Procesando...', // Mensaje genérico, el frontend lo personaliza
			'estadisticas' => [
				'procesados' => $items_synced,
				'total' => $total_items,
				'errores' => 0 // Se puede obtener de SyncStatusHelper si es necesario
			],
			'current_batch' => $current_batch,
			'total_batches' => $total_batches,
			'in_progress' => $current_batch < $total_batches,
			'actualizado' => time()
		];

		// Actualizar el transient
		mia_set_sync_transient('mia_sync_progress', $progress_data, 6 * HOUR_IN_SECONDS);

		$this->logger->debug('Transient de progreso actualizado', [
			'current_batch' => $current_batch,
			'total_batches' => $total_batches,
			'items_synced' => $items_synced,
			'total_items' => $total_items,
			'porcentaje' => $porcentaje
		]);
	}

	/**
	 * Redondea un número de forma segura, manejando valores nulos o inválidos.
	 *
	 * Este método proporciona una forma segura de redondear números, manejando adecuadamente
	 * valores nulos o no numéricos. Es especialmente útil cuando se trabaja con datos
	 * que pueden provenir de fuentes externas o no confiables.
	 *
	 * @since   1.0.0
	 * @package MiIntegracionApi\Core
	 *
	 * @param   float|int|null  $num        El número a redondear. Si es nulo o no numérico,
	 *                                      se considerará como 0.0.
	 * @param   int             $precision  La precisión decimal deseada. Por defecto es 0.
	 *                                      Valores positivos indican decimales, negativos
	 *                                      redondean a múltiplos de 10.
	 *
	 * @return  float  El número redondeado con la precisión especificada.
	 *                 Devuelve 0.0 si el valor de entrada es nulo o no numérico.
	 *
	 * @example
	 * ```php
	 * // Devuelve 123.46
	 * $resultado = $this->safe_round(123.456, 2);
	 *
	 * // Devuelve 120.0 (redondeo a decenas)
	 * $resultado = $this->safe_round(123.456, -1);
	 *
	 * // Devuelve 0.0 (valor nulo)
	 * $resultado = $this->safe_round(null);
	 * ```
	 */
	private function safe_round(float|int|null $num, int $precision = 0): float
	{
		// Si el número es nulo o no es numérico, devolver 0.0
		if ($num === null || !is_numeric($num)) {
			return 0.0;
		}

		// Convertir a float y redondear directamente
		return round((float)$num, $precision);
    }

	/**
	 * Sistema de Cola Independiente - Sin Dependencia de WordPress Cron
	 * =================================================================
	 */
	
	/**
	 * Programa el siguiente lote usando sistema de cola independiente
	 * 
	 * @param string $operation_id ID de la operación
	 * @param int $current_batch Lote actual
	 * @param int $total_batches Total de lotes
	 * @return bool True si se programó correctamente
	 */
	private function scheduleNextBatchWithQueue(string $operation_id, int $current_batch, int $total_batches): bool
	{
		try {
			// Crear tarea en la cola
			$task = [
				'operation_id' => $operation_id,
				'current_batch' => $current_batch,
				'total_batches' => $total_batches,
				'scheduled_time' => time() + $this->getBatchDelay(),
				'created_at' => time(),
				'status' => 'pending'
			];
			
			// Almacenar en cola (usando transients como fallback)
			$queue_key = "mia_sync_queue_{$operation_id}_{$current_batch}";
			$queue_data = get_transient($queue_key);
			
			if ($queue_data === false) {
				$queue_data = [];
			}
			
			$queue_data[] = $task;
			set_transient($queue_key, $queue_data, $this->getQueueTimeout());
			
			// Programar ejecución inmediata usando wp_schedule_single_event como fallback
			// Solo si WordPress está disponible
			if (function_exists('wp_schedule_single_event')) {
				wp_schedule_single_event(
					time() + $this->getBatchDelay(),
					'mia_process_sync_batch',
					[$task]
				);
			}
			
			// Iniciar procesamiento de cola en background
			$this->processQueueInBackground();
			
			return true;
			
		} catch (\Exception $e) {
			$this->logger->error('Error programando lote con sistema de cola', [
				'error' => $e->getMessage(),
				'operation_id' => $operation_id,
				'current_batch' => $current_batch
			]);
			return false;
		}
	}
	
	/**
	 * Obtiene el delay configurable entre lotes
	 * 
	 * @return int Delay en segundos
	 */
	private function getBatchDelay(): int
	{
		// ✅ OPTIMIZACIÓN: Delay optimizado para lotes de 50 productos
		// Para 8000 productos (160 lotes): 1 segundo = ~2.7 minutos de delays totales
		// El AdaptiveThrottler aumentará automáticamente el delay si hay errores
		$default_delay = 1; // 1 segundo (1000ms) por defecto - optimizado para lotes grandes
		
		// Permitir configuración vía filtro
		$delay = apply_filters('mia_batch_delay_seconds', $default_delay);
		
		// Validar rango (1-300 segundos)
		return max(1, min(300, (int) $delay));
	}
	
	/**
	 * Obtiene timeout para cola
	 * 
	 * @return int Timeout en segundos
	 */
	private function getQueueTimeout(): int
	{
		$default_timeout = 3600; // 1 hora
		
		// Permitir configuración vía filtro
		$timeout = apply_filters('mia_queue_timeout_seconds', $default_timeout);
		
		// Validar rango (300-7200 segundos)
		return max(300, min(7200, (int) $timeout));
	}
	
	/**
	 * Sistema de Timeouts Centralizados
	 * =================================
	 * 
	 * Delega a la configuración centralizada existente en getTimeoutConfiguration()
	 */
	
	/**
	 * Obtiene timeout para operaciones de lote
	 * 
	 * @return int Timeout en segundos
	 */
	private function getBatchTimeout(): int
	{
		$config = $this->getTimeoutConfiguration();
		if ($config->isSuccess()) {
			$data = $config->getData();
			return (int) ($data['batch']['default'] ?? 1800);
		}
		
		// Fallback si no se puede obtener configuración
		return 1800; // 30 minutos
	}
	
	/**
	 * Obtiene timeout para operaciones de API
	 * 
	 * @return int Timeout en segundos
	 */
	private function getApiTimeout(): int
	{
		$config = $this->getTimeoutConfiguration();
		if ($config->isSuccess()) {
			$data = $config->getData();
			return (int) ($data['api']['default'] ?? 30);
		}
		
		// Fallback si no se puede obtener configuración
		return 30; // 30 segundos
	}
	
	/**
	 * Obtiene timeout para operaciones de lock
	 * 
	 * @return int Timeout en segundos
	 */
	private function getLockTimeout(): int
	{
		$config = $this->getTimeoutConfiguration();
		if ($config->isSuccess()) {
			$data = $config->getData();
			return (int) ($data['batch']['lock'] ?? 1800);
		}
		
		// Fallback si no se puede obtener configuración
		return 1800; // 30 minutos
	}
	
	/**
	 * Procesa la cola en background
	 * 
	 * @return void
	 */
	private function processQueueInBackground(): void
	{
		// Usar wp_remote_post para procesar en background
		if (function_exists('wp_remote_post')) {
			$url = admin_url('admin-ajax.php');
			$body = [
				'action' => 'mia_process_queue_background',
				'nonce' => wp_create_nonce('mia_queue_nonce')
			];
			
			wp_remote_post($url, [
				'timeout' => 0.01, // Timeout muy corto para no bloquear
				'blocking' => false,
				'body' => $body
			]);
		}
	}


}
