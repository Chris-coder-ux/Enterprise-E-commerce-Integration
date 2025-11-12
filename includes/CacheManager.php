<?php /** @noinspection PhpUndefinedConstantInspection */
declare(strict_types=1);
/**
 * Gestiona el sistema de caché para la integración con la API de Verial.
 *
 * Esta clase proporciona métodos para almacenar, recuperar y gestionar datos en caché
 * de manera eficiente, mejorando el rendimiento de la integración con la API de Verial.
 *
 * @category   Cache
 * @package    MiIntegracionApi
 * @author     Christian <christian@example.com>
 * @copyright  2025 Tu Empresa
 * @license    GPL-2.0+
 * @version    1.0.0
 * @since      File available since Release 1.0.0
 * @link       https://tudominio.com
 */

namespace MiIntegracionApi;

use Exception;
use InvalidArgumentException;
use MiIntegracionApi\Core\CompressionManager;
use MiIntegracionApi\Core\MemoryManager;
use MiIntegracionApi\Logging\Core\LoggerBasic;
use ReflectionObject;
use RuntimeException;
use Throwable;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Clase principal para la gestión de caché del plugin de integración con Verial.
 *
 * Implementa un sistema de caché robusto que mejora el rendimiento mediante:
 * - Almacenamiento en memoria (transients de WordPress)
 * - Almacenamiento en disco para datos persistentes
 * - Invalidación automática de caché basada en eventos
 * - Sistema de limpieza programada
 *
 * La clase sigue el patrón Singleton para garantizar una única instancia
 * y mantener la consistencia de los datos en caché.
 *
 * @category   Cache
 * @package    MiIntegracionApi
 * @author     Christian <christian@example.com>
 * @copyright  2025 Tu Empresa
 * @license    GPL-2.0+
 * @version    1.0.0
 * @since      Class available since Release 1.0.0
 * @link       https://tudominio.com/documentacion/cache-manager
 */
class CacheManager {

	/**
	 * Almacena la única instancia de esta clase (patrón Singleton).
	 *
     * Esta propiedad estática mantiene la referencia a la única instancia de la clase
     * CacheManager que será creada durante la ejecución del plugin. El patrón Singleton
     * garantiza que solo exista una instancia de esta clase en todo el sistema.
     *
     * @since 1.0.0
     * @access private
     * @static
     * @var CacheManager|null $instance La única instancia de la clase o null si no se ha inicializado.
     */
    private static ?CacheManager $instance = null;

	/**
	 * Prefijo utilizado para todas las claves de caché generadas por esta clase.
     *
     * Este prefijo se utiliza para evitar colisiones con otras claves de caché en el sistema.
     * Las claves de caché generadas seguirán el formato: `{cache_prefix}_{identificador_unico}`.
     *
     * @example 'mia_cache_products_list'
     * @example 'mia_cache_user_42_profile'
     *
     * @since 1.0.0
     * @access protected
     * @var string $cache_prefix Prefijo para identificar las claves de caché de este plugin.
     *                          Por defecto: 'mia_cache_'
     */
    protected string $cache_prefix = 'mia_cache_';

	/**
     * Tiempo de vida predeterminado (Time To Live) para los elementos en caché, en segundos.
     *
     * Este valor determina cuánto tiempo se mantendrán los datos en caché antes de considerarse
     * obsoletos. Se puede configurar a través de las opciones del plugin y se aplica a todos
     * los elementos almacenados en caché a menos que se especifique lo contrario.
     *
     * @example 3600 // 1 hora
     * @example 86400 // 1 día
     *
     * @since 1.0.0
     * @access protected
     * @var int $default_ttl Tiempo de vida predeterminado en segundos.
     *                      Valor por defecto: 3600 (1 hora)
     *                      Se obtiene de la opción 'mia_cache_ttl' o usa el valor por defecto.
     *
     * @see CacheManager::__construct() Donde se inicializa este valor.
     * @see get_option() Función de WordPress utilizada para obtener el valor configurado.
     */
    protected int $default_ttl;

	/**
     * Instancia del logger utilizada para registrar eventos y depuración.
     *
     * Esta propiedad almacena una referencia al logger del sistema, que se utiliza para:
     * - Registrar eventos importantes del sistema de caché
     * - Registrar errores y advertencias
     * - Depuración durante el desarrollo
     * - Auditoría de operaciones
     *
     * @since 1.0.0
     * @access protected
     * @var Helpers\Logger|MiIntegracionApi\Helpers\Logger $logger Instancia del logger del sistema.
     *
     * @see MiIntegracionApi\Helpers\Logger Clase Logger utilizada para el registro de eventos.
     * @see CacheManager::__construct() Donde se inicializa esta instancia.
     *
     * @example
     * // Ejemplo de uso dentro de la clase:
     * $this->logger->info('Elemento almacenado en caché', ['key' => $key, 'ttl' => $ttl]);
     */
    protected \MiIntegracionApi\Logging\Core\LoggerBasic $logger;

	/**
     * Indica si el sistema de caché está actualmente habilitado.
     *
     * Este valor controla si el sistema de caché está activo o no. Cuando es `false`,
     * todas las operaciones de caché se omitirán, forzando a que los datos se obtengan
     * directamente de la fuente original.
     *
     * - `true`: La caché está habilitada (valor por defecto)
     * - `false`: La caché está deshabilitada
     *
     * Se puede modificar a través de la opción 'mia_enable_cache' en la configuración
     * del plugin o mediante filtros de WordPress.
     *
     * @since 1.0.0
     * @access protected
     * @var bool $enabled Estado actual de la caché.
     *                   Por defecto: `true`
     *
     * @see CacheManager::__construct() Donde se inicializa este valor.
     * @see get_option() Función utilizada para obtener el valor configurado.
     */
    protected bool $enabled;

	/**
     * Ruta completa al directorio seguro para almacenamiento de archivos de caché.
     *
     * Este directorio se utiliza para almacenar de forma persistente los datos en caché
     * en el sistema de archivos del servidor. Los datos almacenados aquí sobreviven entre
     * diferentes ejecuciones del plugin y reinicios del servidor.
     *
     * Características importantes:
     * - Se crea automáticamente si no existe (con permisos seguros)
     * - Se ubica en el directorio de subidas de WordPress por defecto
     * - Incluye un archivo .htaccess para mayor seguridad
     * - Los archivos se organizan en subdirectorios según el tipo de dato
     *
     * @example '/var/www/wordpress/wp-content/uploads/mi_plugin/cache/'
     *
     * @since 1.0.0
     * @access protected
     * @var ?string $cache_dir Ruta absoluta al directorio de caché (null si solo usa transients).
     *
     * @see CacheManager::init_secure_cache_filesystem() Donde se inicializa este valor.
     * @see wp_upload_dir() Función de WordPress utilizada para obtener la ruta base.
     */
    protected ?string $cache_dir;

    /**
     * Instancia del gestor de compresión
     *
     * @var CompressionManager|null
     * @since 2.3.0
     */
    protected ?CompressionManager $compressionManager = null;

	/**
	 * Constructor privado para implementar el patrón Singleton.
     *
     * Inicializa el sistema de caché con la configuración necesaria:
     * 1. Carga la configuración básica (estado habilitado/deshabilitado, TTL)
     * 2. Inicializa el sistema de logging
     * 3. Configura el sistema de archivos seguro para caché
     * 4. Registra los hooks necesarios para la gestión del ciclo de vida de la caché
     *
     * Este constructor es privado para forzar el uso del patrón Singleton a través
     * del método estático `get_instance()`.
     *
     * @since 1.0.0
     * @access private
     *
     * @see CacheManager::get_instance() Para obtener la instancia de la clase.
     * @see CacheManager::init_secure_cache_filesystem() Para la configuración del sistema de archivos.
     * @see CacheManager::init_cache_hooks() Para el registro de acciones y filtros.
     *
     * @throws RuntimeException Si hay un error crítico durante la inicialización.
     */
    private function __construct() {
        try {
            // 1. Cargar configuración básica
            $this->enabled = (bool) get_option( MiIntegracionApi_OPTION_PREFIX . 'enable_cache', true );
            $this->default_ttl = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_ttl', 3600 );

            // 2. Inicializar logger primero para registrar posibles errores
            if ( class_exists( 'MiIntegracionApi\\Helpers\\Logger' ) ) {
                $this->logger = new LoggerBasic( 'cache_manager' );
            }

            // 3. Inicializar CompressionManager
            if ( class_exists( 'MiIntegracionApi\\Core\\CompressionManager' ) ) {
                $this->compressionManager = new CompressionManager();
            }

            // 4. Configurar sistema de archivos seguro
            $this->init_secure_cache_filesystem();

            // 4. Registrar hooks y acciones
            $this->init_cache_hooks();

            if ( $this->logger instanceof Helpers\Logger ) {
                $this->logger->debug( 'CacheManager inicializado correctamente', [
                    'enabled' => $this->enabled,
                    'default_ttl' => $this->default_ttl,
                    'cache_dir' => $this->cache_dir
                ] );
            }
        } catch ( Exception $e ) {
            // Intentar registrar el error si el logger está disponible
            if ( isset( $this->logger ) && $this->logger instanceof Helpers\Logger ) {
                $this->logger->error( 'Error al inicializar CacheManager: ' . $e->getMessage(), [
                    'exception' => get_class( $e ),
                    'code' => $e->getCode(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ] );
            }
            
            throw new RuntimeException( 'No se pudo inicializar el sistema de caché: ' . $e->getMessage(), 0, $e );
        }
    }

	/**
     * Obtiene la instancia única de la clase CacheManager (patrón Singleton).
     *
     * Este método estático es la única forma de obtener una instancia de la clase,
     * asegurando que solo exista una instancia en todo el ciclo de vida de la aplicación.
     *
     * Ejemplo de uso:
     * @example
     * $cache_manager = CacheManager::get_instance();
     * $cache_manager->set('mi_clave', $mis_datos);
     *
     * @since 1.0.0
     * @static
     * @access public
     * @return CacheManager La instancia única de la clase CacheManager.
     *
     * @see CacheManager::__construct() Para más detalles sobre la inicialización.
     * @see CacheManager::$instance Para la propiedad que almacena la instancia.
     */
	public static function get_instance(): CacheManager {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
     * Registra todos los hooks de WordPress necesarios para la gestión del ciclo de vida de la caché.
     *
     * Este método se encarga de configurar las acciones que permiten mantener la caché
     * sincronizada con los cambios en el sistema. Se ejecuta durante la inicialización
     * de la clase.
     *
     * Los hooks configurados incluyen:
     * - Limpieza programada de caché expirada
     * - Invalidación de caché al actualizar productos
     * - Invalidación de caché al modificar términos de taxonomía
     * - Limpieza selectiva al actualizar opciones del plugin
     * - Limpieza completa en activación/desactivación
     *
     * Nota: Los cron jobs se manejan centralmente en RobustnessHooks para optimizar
     * el rendimiento y evitar múltiples cargas del plugin.
     *
     * @since 1.0.0
     * @access protected
     *
     * @see CacheManager::clean_expired_cache() Para la limpieza de caché expirada.
     * @see CacheManager::clear_product_cache() Para la limpieza de caché de productos.
     * @see CacheManager::clear_term_cache() Para la limpieza de caché de términos.
     * @see CacheManager::maybe_clear_cache_on_option_update() Para la limpieza selectiva.
     * @see CacheManager::clear_all_cache() Para la limpieza completa.
     * @see CacheManager::cleanup_cache_files() Para la limpieza de archivos.
     *
     * @return void
     */
	protected function init_cache_hooks(): void {
		// CORRECCIÓN: Los cron jobs se manejan centralmente en RobustnessHooks
		// para evitar múltiples cargas del plugin
		// La programación se hace automáticamente desde RobustnessHooks

		add_action( 'mi_integracion_api_clean_expired_cache', array( $this, 'clean_expired_cache' ) );

		// Limpiar caché cuando se actualiza un producto
		add_action( 'save_post_product', array( $this, 'clear_product_cache' ), 10, 3 );

		// Limpiar caché cuando se actualiza una categoría
		add_action( 'edit_term', array( $this, 'clear_term_cache' ), 10, 3 );

		// Limpiar caché cuando se actualiza una opción del plugin
		add_action( 'update_option', array( $this, 'maybe_clear_cache_on_option_update' ), 10, 3 );

		// Limpiar caché al activar/desactivar el plugin
		register_activation_hook( MiIntegracionApi_PLUGIN_FILE, array( $this, 'clear_all_cache' ) );
		
		// Limpiar archivos de caché al desactivar el plugin
		register_deactivation_hook( MiIntegracionApi_PLUGIN_FILE, array( $this, 'cleanup_cache_files' ) );
	}

	/**
     * Configura y verifica el sistema de archivos seguro para el almacenamiento en caché.
     *
     * Este método se encarga de:
     * 1. Establecer la ruta del directorio de caché dentro del directorio de subidas de WordPress
     * 2. Crear el directorio si no existe, con los permisos adecuados
     * 3. Verificar que el directorio sea escribible
     * 4. Crear archivos de seguridad (.htaccess e index.php)
     * 5. Verificar la integridad del directorio de caché
     *
     * En caso de error, desactiva el almacenamiento en archivos y registra el problema.
     * El sistema hará fallback a usar solo transients de WordPress si hay problemas con el sistema de archivos.
     *
     * @since 1.0.0
     * @access protected
     *
     * @throws Exception Si ocurre algún error durante la inicialización:
     *                   - Si no se puede determinar el directorio de subidas de WordPress
     *                   - Si no se puede crear el directorio de caché
     *                   - Si el directorio no es escribible
     *                   - Si hay problemas al crear los archivos de seguridad
     *
     * @see wp_upload_dir() Para determinar la ubicación del directorio de subidas.
     * @see wp_mkdir_p() Para la creación segura de directorios.
     * @see CacheManager::create_security_files() Para la creación de archivos de seguridad.
     * @see CacheManager::verify_cache_directory_integrity() Para la verificación de integridad.
     *
     * @return void
     */
	protected function init_secure_cache_filesystem(): void {
		try {
			// Usar directorio de uploads de WordPress (más seguro)
			$upload_dir = wp_upload_dir();
			
			// Verificar si wp_upload_dir() retornó un array válido
			if (!$upload_dir || !is_array($upload_dir) || !isset($upload_dir['basedir'])) {
				throw new Exception('wp_upload_dir() no retornó un directorio válido');
			}
			
			$this->cache_dir = $upload_dir['basedir'] . '/mi-integracion-api-cache';
			
			// Crear directorio con permisos seguros usando WordPress
			        if (!wp_mkdir_p($this->cache_dir)) {
				throw new Exception( 'No se pudo crear el directorio de caché: ' . $this->cache_dir );
			}
			
			// Verificar que el directorio sea escribible
			        if (!is_writable($this->cache_dir)) {
				throw new Exception( 'El directorio de caché no es escribible: ' . $this->cache_dir );
			}
			
			// Crear archivos de seguridad
			$this->create_security_files();
			
			// Verificar integridad del directorio
			$this->verify_cache_directory_integrity();
			
			
		} catch ( Exception $e ) {
			// Log del error y deshabilitar caché de archivos
            $this->logger->error(
                'Error inicializando sistema de archivos de caché',
                [
                    'error' => $e->getMessage(),
                    'fallback_to_transients' => true
                ]
            );
			
			// Fallback: usar solo transients de WordPress
			$this->cache_dir = null;
		}
	}

	/**
     * Crea archivos de seguridad en el directorio de caché para proteger los archivos almacenados.
     *
     * Este método genera tres archivos de seguridad en el directorio de caché:
     * 1. `.htaccess` - Bloquea el acceso directo a los archivos de caché
     * 2. `index.php` - Previene el listado de directorios
     * 3. `.gitignore` - Evita el seguimiento de archivos de caché en control de versiones
     *
     * Los archivos se crean solo si no existen previamente, lo que permite personalizaciones
     * manuales sin que sean sobrescritas en inicializaciones posteriores.
     *
     * @since 1.0.0
     * @access protected
     *
     * @see https://developer.wordpress.org/apis/security/htaccess/ Para más información sobre seguridad con .htaccess
     * @see CacheManager::init_secure_cache_filesystem() Donde se llama a este método
     *
     * @return void
     *
     * @throws RuntimeException Si ocurre un error al crear los archivos de seguridad.
     *                          El error se registra en el log si está disponible.
     *
     * @example
     * // Ejemplo de uso interno:
     * $this->create_security_files();
     */
	protected function create_security_files(): void {
		// Crear .htaccess para bloquear acceso directo
		$htaccess_content = "# Bloquear acceso directo a archivos de caché\n";
		$htaccess_content .= "Order Deny,Allow\n";
		$htaccess_content .= "Deny from all\n";
		$htaccess_content .= "\n# Permitir solo acceso desde WordPress\n";
		$htaccess_content .= "<Files \"*.php\">\n";
		$htaccess_content .= "    Allow from 127.0.0.1\n";
		$htaccess_content .= "    Allow from ::1\n";
		$htaccess_content .= "</Files>\n";
		
		$htaccess_file = $this->cache_dir . '/.htaccess';
		        if (!file_exists($htaccess_file)) {
			file_put_contents( $htaccess_file, $htaccess_content );
		}
		
		// Crear index.php para evitar listado de directorios
		$index_content = "<?php\n// Silence is golden.\n";
		$index_file = $this->cache_dir . '/index.php';
		        if (!file_exists($index_file)) {
			file_put_contents( $index_file, $index_content );
		}
		
		// Crear .gitignore para evitar commit de archivos de caché
		$gitignore_content = "# Archivos de caché\n";
		$gitignore_content .= "*.cache\n";
		$gitignore_content .= "*.tmp\n";
		$gitignore_content .= "*.log\n";
		$gitignore_content .= "!*.htaccess\n";
		$gitignore_content .= "!index.php\n";
		$gitignore_content .= "!.gitignore\n";
		
		$gitignore_file = $this->cache_dir . '/.gitignore';
		        if (!file_exists($gitignore_file)) {
			file_put_contents( $gitignore_file, $gitignore_content );
		}
	}

	/**
	 * Verifica la integridad del directorio de caché.
	 * 
	 * @since 1.0.0
	 * @access   protected
	 * @throws   Exception Si hay problemas de seguridad
	 */
	protected function verify_cache_directory_integrity(): void {
		// Verificar que el directorio esté dentro de uploads
		$upload_dir = wp_upload_dir();
		$real_cache_dir = realpath( $this->cache_dir );
		$real_upload_dir = realpath( $upload_dir['basedir'] );
		
		if ( !str_starts_with( $real_cache_dir, $real_upload_dir ) ) {
			throw new Exception( 'El directorio de caché está fuera del directorio de uploads permitido' );
		}
		
		// Verificar permisos del directorio (debe ser 0755 o más restrictivo)
		$permissions = fileperms( $this->cache_dir );
		$permissions_octal = substr( sprintf( '%o', $permissions ), -4 );
		
		// Convertir a octal para comparación correcta
		$permissions_decimal = octdec( $permissions_octal );
		$max_permissions_decimal = octdec( '0755' );
		
		if ( $permissions_decimal > $max_permissions_decimal ) {
			// Cambiar permisos a 0755 si son demasiado permisivos
			chmod( $this->cache_dir, 0755 );

            $this->logger->warning(
                'Permisos del directorio de caché corregidos automáticamente',
                [
                    'old_permissions' => $permissions_octal,
                    'new_permissions' => '0755',
                    'user_id' => get_current_user_id(),
                    'user_role' => wp_get_current_user()->roles[0] ?? 'unknown',
                    'request_url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                    'trace' => __FILE__ . ':' . __LINE__,
                    'memory_usage' => $this->safe_round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                    'transaction_id' => uniqid('tx_' . time() . '_', true)
                ]
            );
		}
		
		// Verificar que los archivos de seguridad existan
		$security_files = [ '.htaccess', 'index.php', '.gitignore' ];
		foreach ( $security_files as $file ) {
			$file_path = $this->cache_dir . '/' . $file;
			        if (!file_exists($file_path)) {
				throw new Exception( 'Archivo de seguridad faltante: ' . $file );
			}
		}
	}

	/**
	 * Guarda un valor en caché.
	 *
	 * @param    string $key Clave para identificar los datos.
	 * @param    mixed  $value    Valor a almacenar en caché.
	 * @param int|null $ttl      Tiempo de vida en segundos. Usar 0 para no expirar.
	 * @return   boolean             True si se guardó correctamente, false en caso contrario.
	 *@since 1.0.0
	 */
	public function set(string $key, mixed $value, ?int $ttl = null ): bool
    {
		if (!$this->enabled) {
			return false;
		}

		// Usar TTL predeterminado si no se especifica
		if ( $ttl === null ) {
			$ttl = $this->default_ttl;
		}

		// Sanitizar y preparar la clave
		$cache_key = $this->prepare_key( $key );

		// ✅ NUEVO: Verificar límite global antes de almacenar y evictar si es necesario
		$this->checkAndEvictIfNeeded($cache_key);

		// ✅ NUEVO: Determinar si debe almacenarse en hot o cold cache
		$shouldUseHotCache = $this->shouldUseHotCache($cache_key);

		// Guardar metadata para gestión de caché
		$this->set_cache_metadata( $cache_key, $ttl );

		// ✅ NUEVO: Registrar timestamp de creación para tracking de edad
		update_option('mia_transient_created_' . $cache_key, time(), false);

		// ✅ NUEVO: Almacenar en hot o cold cache según uso
		if ($shouldUseHotCache) {
			// Almacenar en hot cache (transients)
			$result = set_transient( $cache_key, $value, $ttl );
			
			// ✅ NUEVO: Eliminar de cold cache si existe (migración cold→hot)
			$this->removeFromColdCache($cache_key);
		} else {
			// Almacenar en cold cache (archivo comprimido)
			$result = $this->storeInColdCache($cache_key, $value, $ttl);
			
			// ✅ NUEVO: Eliminar de hot cache si existe (migración hot→cold)
			delete_transient($cache_key);
		}
		
		// ✅ NUEVO: Registrar acceso inicial al crear
		if ($result) {
			$this->recordTransientAccess($cache_key);
		}
		
		return $result;
	}

	/**
	 * Recupera un valor de la caché.
	 *
	 * @since 1.0.0
	 * @param    string  $key           Clave para identificar los datos.
	 * @param    mixed   $default       Valor por defecto si no existe en caché.
	 * @param    boolean $refresh_ttl   Si debe refrescar el TTL al recuperar.
	 * @return   mixed                    Valor almacenado o valor por defecto.
	 */
	public function get(string $key, mixed $default = false, bool $refresh_ttl = false): mixed
    {
		if (!$this->enabled) {
			return $default;
		}

		// Sanitizar y preparar la clave
		$cache_key = $this->prepare_key( $key );

		// ✅ NUEVO: Intentar obtener de hot cache (transients) primero
		$value = get_transient( $cache_key );

		if ( $value !== false ) {
			// ✅ NUEVO: Registrar acceso para tracking de uso y LRU
			$this->recordTransientAccess($cache_key);
			
			// ✅ NUEVO: Verificar límite global y evictar si es necesario
			$this->checkAndEvictIfNeeded();
			
			if ( $refresh_ttl ) {
				$metadata = $this->get_cache_metadata( $cache_key );
				if ( $metadata && isset( $metadata['ttl'] ) && $metadata['ttl'] > 0 ) {
					$this->set( $key, $value, $metadata['ttl'] );
				}
			}

			return $value;
		}

		// ✅ NUEVO: Si no está en hot cache, intentar obtener de cold cache (archivo comprimido)
		$coldValue = $this->getFromColdCache($cache_key);
		if ($coldValue !== false) {
			// ✅ NUEVO: Promover a hot cache si se accede a un dato cold
			$this->promoteToHotCache($cache_key, $coldValue);
			
			// Registrar acceso
			$this->recordTransientAccess($cache_key);
			
			return $coldValue;
		}

		return $default;
	}

	/**
	 * Elimina un valor específico de la caché.
	 *
	 * @param    string $key Clave para identificar los datos.
	 * @return   boolean           True si se eliminó, false en caso contrario.
	 *@since 1.0.0
	 */
	public function delete( string $key ): bool
    {
		// Sanitizar y preparar la clave
		$cache_key = $this->prepare_key( $key );

		// Eliminar metadata
		$this->delete_cache_metadata( $cache_key );

		// Eliminar transient
		return delete_transient( $cache_key );
	}

	/**
	 * Comprueba si una clave existe en la caché.
	 *
	 * @param    string $key Clave para identificar los datos.
	 * @return   boolean           True si existe, false en caso contrario.
	 *@since 1.0.0
	 */
	public function exists( string $key ): bool
    {
		if (!$this->enabled) {
			return false;
		}

		// Sanitizar y preparar la clave
		$cache_key = $this->prepare_key( $key );

		// Verificar existencia
		return get_transient( $cache_key ) !== false;
	}

	/**
	 * Limpia toda la caché del plugin.
	 *
	 * Elimina todos los transients y metadata de caché asociados con el prefijo
	 * del plugin. Incluye tanto los transients como sus timeouts correspondientes.
	 *
	 * ✅ MEJORADO: Utiliza flush inteligente por segmentos cuando hay muchos transients
	 * para evitar bloqueos y timeouts en sistemas con grandes cantidades de caché.
	 *
	 * @since 1.0.0
	 * 
	 * @global wpdb $wpdb Instancia de la base de datos de WordPress.
	 * 
	 * @return int Número de elementos eliminados (transients únicos, sin contar timeouts).
	 */
	public function clear_all_cache(): int
	{
		global $wpdb;

		// Obtener todas las claves de transient con nuestro prefijo
		$sql = $wpdb->prepare(
			"SELECT option_name FROM $wpdb->options 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
			'_transient_' . $this->cache_prefix . '%',
			'_transient_timeout_' . $this->cache_prefix . '%'
		);

		$transients = $wpdb->get_col( $sql );
		$totalTransients = count( $transients );

		// ✅ NUEVO: Usar flush segmentado si hay muchos transients (umbral configurable)
		$segmentThreshold = get_option( 'mia_cache_segment_flush_threshold', 1000 );
		
		if ( $totalTransients > $segmentThreshold ) {
			// Usar flush inteligente por segmentos
			return $this->clear_all_cache_segmented( $transients );
		}

		// Flush tradicional para cantidades pequeñas (compatibilidad hacia atrás)
		$count = 0;

		// Eliminar cada transient
		foreach ( $transients as $transient ) {
			$key = str_replace( array( '_transient_', '_transient_timeout_' ), '', $transient );

			// Eliminar metadata y transient
			$this->delete_cache_metadata( $key );
			delete_option( $transient );
			++$count;
		}

		// Limpiar metadata
		$this->clear_all_metadata();

		return $count / 2; // Dividir por 2 porque contamos transient y su timeout
	}

	/**
	 * ✅ NUEVO: Limpia toda la caché del plugin usando flush inteligente por segmentos.
	 *
	 * Este método divide la limpieza en segmentos más pequeños para evitar bloqueos,
	 * timeouts y problemas de memoria cuando hay grandes cantidades de transients.
	 *
	 * Características:
	 * - Procesa transients en lotes configurables
	 * - Control de tiempo máximo por segmento
	 * - Verificación de memoria entre segmentos
	 * - Logging detallado del progreso
	 * - Compatible con cold cache (también limpia archivos)
	 *
	 * @param   array   $transients    Array de nombres de transients a eliminar.
	 * @return  int     Número de elementos eliminados (transients únicos, sin contar timeouts).
	 * @since   1.0.0
	 * 
	 * @global  wpdb    $wpdb          Instancia de la base de datos de WordPress.
	 * 
	 * @see     CacheManager::clear_all_cache() Para el método principal que decide cuándo usar segmentación.
	 * @see     CacheManager::getSegmentFlushConfig() Para obtener configuración de segmentación.
	 */
	private function clear_all_cache_segmented( array $transients ): int
	{
		global $wpdb;

		$config = $this->getSegmentFlushConfig();
		$segmentSize = $config['segment_size'];
		$maxExecutionTime = $config['max_execution_time'];
		$startTime = time();
		$totalCleared = 0;
		$segmentsProcessed = 0;
		$totalSegments = (int) ceil( count( $transients ) / $segmentSize );

		$this->logger->info( 'Iniciando flush inteligente por segmentos', [
			'total_transients' => count( $transients ),
			'segment_size' => $segmentSize,
			'total_segments' => $totalSegments,
			'max_execution_time' => $maxExecutionTime
		] );

		// Dividir transients en segmentos
		$segments = array_chunk( $transients, $segmentSize );

		foreach ( $segments as $segment ) {
			// ✅ Fail Fast: Verificar tiempo de ejecución antes de procesar cada segmento
			$elapsedTime = time() - $startTime;
			if ( $elapsedTime > $maxExecutionTime ) {
				$this->logger->warning( 'Tiempo máximo de ejecución alcanzado durante flush segmentado', [
					'segments_processed' => $segmentsProcessed,
					'total_segments' => $totalSegments,
					'elapsed_time' => $elapsedTime,
					'max_execution_time' => $maxExecutionTime,
					'transients_cleared' => $totalCleared
				] );
				break;
			}

			// ✅ Verificar memoria antes de procesar segmento
			if ( class_exists( '\\MiIntegracionApi\\Core\\MemoryManager' ) ) {
				$memoryStats = \MiIntegracionApi\Core\MemoryManager::getMemoryStats();
				$memoryUsagePercent = $memoryStats['usage_percentage'] ?? 0;

				if ( $memoryUsagePercent > 90 ) {
					$this->logger->warning( 'Uso de memoria crítico durante flush segmentado, pausando', [
						'memory_usage_percent' => $memoryUsagePercent,
						'segments_processed' => $segmentsProcessed
					] );
					
					// Forzar garbage collection antes de continuar
					if ( function_exists( 'gc_collect_cycles' ) ) {
						gc_collect_cycles();
					}
				}
			}

			// Procesar segmento
			$segmentCleared = 0;
			foreach ( $segment as $transient ) {
				$key = str_replace( array( '_transient_', '_transient_timeout_' ), '', $transient );

				// Eliminar metadata y transient
				$this->delete_cache_metadata( $key );
				delete_option( $transient );
				++$segmentCleared;
			}

			$totalCleared += $segmentCleared;
			++$segmentsProcessed;

			// Logging periódico (cada 10 segmentos o en el último)
			if ( $segmentsProcessed % 10 === 0 || $segmentsProcessed === $totalSegments ) {
				$progressPercent = round( ( $segmentsProcessed / $totalSegments ) * 100, 1 );
				$this->logger->debug( 'Progreso de flush segmentado', [
					'segments_processed' => $segmentsProcessed,
					'total_segments' => $totalSegments,
					'progress_percent' => $progressPercent,
					'transients_cleared' => $totalCleared,
					'elapsed_time' => time() - $startTime
				] );
			}

			// ✅ Graceful Degradation: Liberar memoria periódicamente
			if ( $segmentsProcessed % 5 === 0 && function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		// Limpiar metadata al final
		$this->clear_all_metadata();

		// ✅ NUEVO: Limpiar también cold cache si existe
		if ( $this->cache_dir !== null && is_dir( $this->cache_dir . '/cold' ) ) {
			try {
				$coldCleaned = $this->cleanExpiredColdCache();
				if ( $coldCleaned > 0 ) {
					$this->logger->debug( 'Cold cache limpiado durante flush segmentado', [
						'cold_files_cleaned' => $coldCleaned
					] );
				}
			} catch ( Exception $e ) {
				$this->logger->warning( 'Error limpiando cold cache durante flush segmentado', [
					'error' => $e->getMessage()
				] );
			}
		}

		$finalCount = $totalCleared / 2; // Dividir por 2 porque contamos transient y su timeout
		$totalTime = time() - $startTime;

		$this->logger->info( 'Flush segmentado completado', [
			'total_transients_cleared' => $finalCount,
			'segments_processed' => $segmentsProcessed,
			'total_segments' => $totalSegments,
			'total_time_seconds' => $totalTime,
			'avg_time_per_segment' => $segmentsProcessed > 0 ? round( $totalTime / $segmentsProcessed, 2 ) : 0
		] );

		return (int) $finalCount;
	}

	/**
	 * ✅ NUEVO: Obtiene la configuración para flush segmentado.
	 *
	 * Retorna los parámetros configurables para el flush inteligente por segmentos,
	 * con valores por defecto optimizados para sistemas con grandes cantidades de caché.
	 *
	 * @return  array   Configuración con:
	 *                  - 'segment_size': Tamaño de cada segmento (default: 500)
	 *                  - 'max_execution_time': Tiempo máximo en segundos (default: 30)
	 * @since   1.0.0
	 * 
	 * @see     CacheManager::clear_all_cache_segmented() Para uso de esta configuración.
	 */
	private function getSegmentFlushConfig(): array
	{
		$defaultSegmentSize = 500;
		$defaultMaxTime = 30;

		// Obtener configuración desde opciones de WordPress
		$segmentSize = (int) get_option( 'mia_cache_segment_flush_size', $defaultSegmentSize );
		$maxExecutionTime = (int) get_option( 'mia_cache_segment_flush_max_time', $defaultMaxTime );

		// ✅ Fail Fast: Validar valores para evitar problemas
		if ( $segmentSize < 10 || $segmentSize > 2000 ) {
			$segmentSize = $defaultSegmentSize;
			$this->logger->warning( 'Tamaño de segmento inválido, usando valor por defecto', [
				'invalid_value' => get_option( 'mia_cache_segment_flush_size' ),
				'default' => $defaultSegmentSize
			] );
		}

		if ( $maxExecutionTime < 5 || $maxExecutionTime > 300 ) {
			$maxExecutionTime = $defaultMaxTime;
			$this->logger->warning( 'Tiempo máximo de ejecución inválido, usando valor por defecto', [
				'invalid_value' => get_option( 'mia_cache_segment_flush_max_time' ),
				'default' => $defaultMaxTime
			] );
		}

		return [
			'segment_size' => $segmentSize,
			'max_execution_time' => $maxExecutionTime
		];
	}

	/**
	 * Limpia la caché expirada.
	 *
	 * @since 1.0.0
	 * @return   int       Número de elementos expirados eliminados.
	 */
	public function clean_expired_cache(): int {
		// Los transients expirados son eliminados automáticamente por WordPress
		// Solo necesitamos limpiar la metadata
		$metadata_cleaned = $this->clean_expired_metadata();
		
		// ✅ NUEVO: Limpiar lotes antiguos por rotación de ventana de tiempo
		// Esto previene acumulación de caché cuando sincronizaciones largas cruzan múltiples horas
		try {
			// Obtener configuración desde opciones de WordPress (default: 3 horas)
			$max_age_hours = get_option('mia_batch_cache_max_age_hours', 3);
			$batch_cleanup_result = $this->cleanupOldBatchCache($max_age_hours);
			if ($batch_cleanup_result['success'] && $batch_cleanup_result['cleaned_count'] > 0) {
				$this->logger->debug('Lotes antiguos limpiados durante limpieza de caché expirada', [
					'batches_cleaned' => $batch_cleanup_result['cleaned_count']
				]);
			}
		} catch (\Exception $e) {
			// No fallar la limpieza general si falla la limpieza de lotes
			$this->logger->warning('Error limpiando lotes antiguos durante limpieza de caché expirada', [
				'error' => $e->getMessage()
			]);
		}
		
		// ✅ NUEVO: Limpiar cold cache expirado
		$coldCleaned = $this->cleanExpiredColdCache();
		if ($coldCleaned > 0) {
			$this->logger->debug('Cold cache expirado limpiado', [
				'cleaned_count' => $coldCleaned
			]);
		}
		
		// ✅ NUEVO: Ejecutar migración hot→cold si está habilitada
		$autoMigrationEnabled = get_option('mia_enable_hot_cold_migration', true);
		if ($autoMigrationEnabled) {
			try {
				$migrationResult = $this->performHotToColdMigration();
				if ($migrationResult['migrated_count'] > 0) {
					$this->logger->info('Migración automática hot→cold ejecutada', [
						'migrated_count' => $migrationResult['migrated_count']
					]);
				}
			} catch (\Exception $e) {
				$this->logger->warning('Error durante migración automática hot→cold', [
					'error' => $e->getMessage()
				]);
			}
		}
		
		return $metadata_cleaned;
	}

	/**
	 * ✅ NUEVO: Limpia archivos de cold cache expirados.
	 * 
	 * @return  int     Número de archivos limpiados
	 * @since   1.0.0
	 */
	public function cleanExpiredColdCache(): int
	{
		if ($this->cache_dir === null) {
			return 0;
		}

		$cleanedCount = 0;
		$coldDir = $this->cache_dir . '/cold';

		if (!is_dir($coldDir)) {
			return 0;
		}

		try {
			$files = glob($coldDir . '/*.cache');
			$currentTime = time();

			foreach ($files as $file) {
				// Buscar metadata correspondiente
				$basename = basename($file, '.cache');
				
				// Buscar en opciones
				global $wpdb;
				$metaOptions = $wpdb->get_results(
					"SELECT option_name, option_value FROM {$wpdb->options} 
					WHERE option_name LIKE 'mia_cold_cache_meta_%'",
					ARRAY_A
				);

				$expired = false;
				$cacheKey = null;

				foreach ($metaOptions as $metaOption) {
					$meta = maybe_unserialize($metaOption['option_value']);
					if (is_array($meta) && isset($meta['expires_at'])) {
						$expectedFile = $this->getColdCacheFilePath($meta['cache_key']);
						if ($expectedFile === $file) {
							$cacheKey = $meta['cache_key'];
							if ($meta['expires_at'] < $currentTime) {
								$expired = true;
								break;
							}
						}
					}
				}

				if ($expired && $cacheKey !== null) {
					$this->removeFromColdCache($cacheKey);
					$cleanedCount++;
				}
			}
		} catch (Exception $e) {
			$this->logger->error('Error limpiando cold cache expirado', [
				'error' => $e->getMessage()
			]);
		}

		return $cleanedCount;
	}

	/**
	 * Limpia la caché relacionada con un producto.
	 *
	 * @param    int     $post_id ID del post.
	 * @param WP_Post $post         Objeto post.
	 * @param boolean $update       Si es una actualización.
	 * @return   void
	 *@since 1.0.0
	 */
	public function clear_product_cache(int $post_id, \WP_Post $post, bool $update ): void
    {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Limpiar caché específica del producto
		$this->delete( 'product_' . $post_id );
		$this->delete( 'product_data_' . $post_id );

		// Limpiar caches relacionadas
		$this->delete( 'products_list' );
		$this->delete( 'recently_updated_products' );

		// Buscar y eliminar cualquier caché que contenga el ID del producto
		$this->delete_by_pattern( '*product*' . $post_id . '*' );

	}

	/**
	 * Limpia la caché relacionada con un término/categoría.
	 *
	 * @param    int    $term_id ID del término.
	 * @param int $tt_id        ID de la taxonomía del término.
	 * @param string $taxonomy     Taxonomía.
	 * @return   void
	 *@since 1.0.0
	 */
	public function clear_term_cache(int $term_id, int $tt_id, string $taxonomy ): void
    {
		// Solo procesar términos relevantes
		if (!in_array($taxonomy, array('product_cat', 'product_tag', 'category'))) {
			return;
		}

		// Limpiar caché específica del término
		$this->delete( 'term_' . $term_id );
		$this->delete( 'taxonomy_' . $taxonomy );

		// Limpiar caches relacionadas
		$this->delete( 'categories_list' );
		$this->delete( 'categories_tree' );

	}

	/**
	 * Verifica si debe limpiar la caché cuando se actualiza una opción.
	 *
	 * @param    string $option_name Nombre de la opción.
	 * @param    mixed  $old_value       Valor anterior.
	 * @param    mixed  $new_value       Nuevo valor.
	 * @return   void
	 *@since 1.0.0
	 */
	public function maybe_clear_cache_on_option_update(string $option_name, mixed $old_value, mixed $new_value ): void
    {
		// Solo procesar opciones del plugin
		if ( !str_starts_with( $option_name, MiIntegracionApi_OPTION_PREFIX ) ) {
			return;
		}

		// Opciones que afectan a la caché global
		$global_cache_options = array(
			MiIntegracionApi_OPTION_PREFIX . 'api_url',
			MiIntegracionApi_OPTION_PREFIX . 'api_key',
			MiIntegracionApi_OPTION_PREFIX . 'api_secret',
			MiIntegracionApi_OPTION_PREFIX . 'sync_settings',
		);

		// Opciones que afectan a la configuración de caché
		$cache_config_options = array(
			MiIntegracionApi_OPTION_PREFIX . 'enable_cache',
			MiIntegracionApi_OPTION_PREFIX . 'cache_ttl',
		);

		// Si cambia una opción que afecta a la caché global
		if ( in_array( $option_name, $global_cache_options ) ) {
			// ✅ VERIFICAR: No limpiar caché durante sincronización activa
			if ( !mi_integracion_api_is_sync_in_progress() ) {
				$this->clear_all_cache();
			} else {
				// Log: Caché no limpiada durante sincronización
                $this->logger->info('Caché no limpiada automáticamente - sincronización en progreso', [
                    'option_name' => $option_name,
                    'reason' => 'sync_in_progress',
                    'action' => 'deferred_cleanup'
                ]);
			}
		}
		// Si cambia la configuración de caché
		elseif ( in_array( $option_name, $cache_config_options ) ) {
			// Actualizar variables internas
			if ( $option_name === MiIntegracionApi_OPTION_PREFIX . 'enable_cache' ) {
				$this->enabled = (bool) $new_value;

				// Si se desactiva la caché, limpiarla toda
				if (!$this->enabled) {
					// ✅ VERIFICAR: No limpiar caché durante sincronización activa
					if ( !mi_integracion_api_is_sync_in_progress() ) {
						$this->clear_all_cache();
					} else {
						// Log: Caché no limpiada durante sincronización
                        $this->logger->info('Caché no limpiada automáticamente - sincronización en progreso', [
                            'option_name' => $option_name,
                            'action' => 'disable_cache',
                            'reason' => 'sync_in_progress',
                            'deferred_cleanup' => true
                        ]);
					}
				}
			} elseif ( $option_name === MiIntegracionApi_OPTION_PREFIX . 'cache_ttl' ) {
				$this->default_ttl = (int) $new_value;
			}
		}
	}

	/**
	 * Elimina elementos de la caché por patrón.
	 *
	 * @param    string $pattern Patrón para las claves (acepta * como comodín).
	 * @return   int                   Número de elementos eliminados.
	 *@since 1.0.0
	 */
	public function delete_by_pattern( string $pattern ): int
    {
		global $wpdb;

		// Convertir patrón con * a formato SQL LIKE
		$sql_pattern = str_replace( '*', '%', $pattern );
		$sql_pattern = $this->cache_prefix . $sql_pattern;

		// Buscar transients que coincidan
		$sql = $wpdb->prepare(
			"SELECT option_name FROM $wpdb->options 
             WHERE option_name LIKE %s 
             AND option_name LIKE %s",
			'_transient_' . $sql_pattern,
			'_transient_%'
		);

		$transients = $wpdb->get_col( $sql );
		$count      = 0;

		// Eliminar cada transient
		foreach ( $transients as $transient ) {
			$key = str_replace( '_transient_', '', $transient );

			// Eliminar metadata y transient
			$this->delete_cache_metadata( $key );
			delete_option( $transient );
			delete_option( '_transient_timeout_' . $key );
			++$count;
		}


		return $count;
	}

	/**
	 * Obtiene estadísticas de uso de la caché.
	 *
	 * @since 1.0.0
	 * @return   array     Estadísticas de la caché.
	 */
	public function get_stats(): array {
		global $wpdb;

		// Contar elementos en caché
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->options 
             WHERE option_name LIKE %s 
             AND option_name NOT LIKE %s",
			'_transient_' . $this->cache_prefix . '%',
			'_transient_timeout_%'
		);

		$count = (int) $wpdb->get_var( $sql );

		// Calcular tamaño aproximado
		$sql = $wpdb->prepare(
			"SELECT SUM(LENGTH(option_value)) FROM $wpdb->options 
             WHERE option_name LIKE %s 
             AND option_name NOT LIKE %s",
			'_transient_' . $this->cache_prefix . '%',
			'_transient_timeout_%'
		);

		$size = (int) $wpdb->get_var( $sql );

		// Obtener estadísticas de hit/miss si están disponibles
		$hits   = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_hits', 0 );
		$misses = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_misses', 0 );

		// Calcular ratio de aciertos
		$total_requests = $hits + $misses;
		$hit_ratio      = $total_requests > 0 ? ( $hits / $total_requests ) * 100 : 0;
		
		// Asegurar que el ratio es un número válido para $this->safe_round()
		$hit_ratio = is_numeric($hit_ratio) ? (float) $hit_ratio : 0.0;

		return array(
			'enabled'        => $this->enabled,
			'count'          => $count,
			'size_bytes'     => $size,
			'size_formatted' => $this->format_size( $size ),
			'ttl'            => $this->default_ttl,
			'hits'           => $hits,
			'misses'         => $misses,
			'hit_ratio'      => $this->safe_round($hit_ratio, 2),
			'last_cleared'   => get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_last_cleared', '' ),
			'last_check'     => current_time( 'mysql' ),
		);
	}

	/**
	 * Prepara una clave de caché, sanitizándola y añadiendo el prefijo.
	 *
	 * @param    string $key    Clave original.
	 * @return   string            Clave preparada.
	 *@since 1.0.0
	 * @access   protected
	 */
	protected function prepare_key( string $key ): string
    {
		// Sanitizar clave
		$key = sanitize_key( str_replace( array( ' ', '.' ), '_', $key ) );

		// Añadir prefijo si no lo tiene ya
		if ( !str_starts_with( $key, $this->cache_prefix ) ) {
			$key = $this->cache_prefix . $key;
		}

		return $key;
	}

	/**
	 * Guarda metadata de un elemento en caché.
	 *
	 * @param    string $key         Clave de caché.
     * @param int $ttl         Tiempo de vida en segundos.
	 * @return   boolean                True si se guardó correctamente.
	 *@since 1.0.0
	 * @access   protected
	 */
	protected function set_cache_metadata(string $key, int $ttl ): bool
    {
		$metadata = get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array() );

		$metadata[ $key ] = array(
			'created' => time(),
			'expires' => $ttl > 0 ? time() + $ttl : 0,
			'ttl'     => $ttl,
		);

		// Evitar que metadata crezca demasiado
		if ( count( $metadata ) > 1000 ) {
			// Eliminar entradas antiguas
			$metadata = array_slice( $metadata, -500, 500, true );
		}

		return update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', $metadata, false );
	}

	/**
	 * Obtiene metadata de un elemento en caché.
	 *
	 * @param    string $key         Clave de caché.
     * @return   array|false            Metadata o false si no existe.
	 *@since 1.0.0
	 * @access   protected
	 */
	protected function get_cache_metadata( string $key ): bool|array
    {
		$metadata = get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array() );

		return $metadata[ $key ] ?? false;
	}

	/**
	 * Elimina metadata de un elemento en caché.
	 *
	 * @param    string $key         Clave de caché.
     * @return   boolean                True si se eliminó correctamente.
	 *@since 1.0.0
	 * @access   protected
	 */
	protected function delete_cache_metadata( string $key ): bool
    {
		$metadata = get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array() );

		if ( isset( $metadata[ $key ] ) ) {
			unset( $metadata[ $key ] );
			return update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', $metadata, false );
		}

		return false;
	}

	/**
	 * Limpia toda la metadata de caché.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @return   boolean                True si se limpió correctamente.
	 */
	protected function clear_all_metadata(): bool {
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_last_cleared', current_time( 'mysql' ) );
		return update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array(), false );
	}

	/**
	 * Limpia metadata expirada.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @return   int                    Número de elementos eliminados.
	 */
	protected function clean_expired_metadata(): int {
		$metadata = get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array() );
		
		// Verificar que metadata sea un array
		if (!is_array($metadata)) {
			$metadata = array();
		}
		
		$now      = time();
		$count    = 0;

		foreach ( $metadata as $key => $data ) {
			if (!empty($data['expires']) && $data['expires'] < $now) {
				unset( $metadata[ $key ] );
				++$count;
			}
		}

		if ( $count > 0 ) {
			update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', $metadata, false );
		}

		return $count;
	}

	/**
	 * Estima el tamaño en bytes de un valor.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    mixed $value    Valor a medir.
	 * @return   int                 Tamaño aproximado en bytes.
	 */
	protected function estimate_size(mixed $value ): int
    {
		$serialized = serialize( $value );
		return strlen( $serialized );
	}

	/**
	 * Formatea un tamaño en bytes a una unidad legible.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    int $bytes       Tamaño en bytes.
	 * @param    int $precision   Precisión decimal.
	 * @return   string                 Tamaño formateado.
	 */
	protected function format_size(int $bytes, int $precision = 2): string
    {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	
		// Convertir a float para cálculos
		$bytes = (float) $bytes;
		$bytes = max( $bytes, 0 );
		
		// Calcular potencia de forma segura
		$pow = $bytes > 0 ? floor( log( $bytes ) / log( 1024 ) ) : 0;
		$pow = (int) $pow;
		$pow = min( $pow, count( $units ) - 1 );
	
		$bytes /= pow( 1024, $pow );
		
		// Usar tu helper seguro
		return $this->safe_round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Redondea un número de forma segura, manejando valores nulos o inválidos.
	 *
	 * @param   float|int|null  $num        El número a redondear.
	 * @param   int             $precision  La precisión decimal (por defecto 0).
	 * @return  float                       El número redondeado o 0.0 si es inválido.
	 */
	private function safe_round(float|int|null $num, int $precision = 0): float
	{
		// Si el número es nulo o no es numérico, devolver 0.0
		if ($num === null || !is_numeric($num)) {
			return 0.0;
		}

		// Convertir a float y redondear
		return round((float) $num, $precision);
	}


	/**
	 * Incrementa el contador de aciertos de caché.
	 *
	 * @since 1.0.0
	 * @return   void
	 */
	public function increment_hit_count(): void {
		$hits = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_hits', 0 );
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_hits', $hits + 1, false );
	}

	/**
	 * Incrementa el contador de fallos de caché.
	 *
	 * @since 1.0.0
	 * @return   void
	 */
	public function increment_miss_count(): void {
		$misses = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_misses', 0 );
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_misses', $misses + 1, false );
	}

	/**
	 * Resetea los contadores de estadísticas.
	 *
	 * @since 1.0.0
	 * @return   void
	 */
	public function reset_stats(): void {
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_hits', 0, false );
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_misses', 0, false );
	}

	/**
	 * Guarda datos del batch en caché con TTL diferenciado por tipo de dato.
	 * Utiliza el método genérico set() para evitar duplicación.
	 * 
	 * @param string $batch_id ID único del batch
	 * @param array $batch_data Datos del batch a almacenar
	 * @param array $rango Rango de productos (inicio, fin)
	 * @return bool True si se guardó correctamente
	 *@since 1.0.0
	 */
	public function store_batch_data(string $batch_id, array $batch_data, array $rango ): bool
    {
		if (!$this->enabled) {
			return false;
		}

		// TTL diferenciado por tipo de dato
		$ttl_config = [
			'productos' => 1800,           // 30 minutos - datos específicos del lote
			'imagenes_productos' => 1800,  // 30 minutos - imágenes del lote
			'stock_productos' => 1800,     // 30 minutos - stock del lote
			'condiciones_tarifa' => 1800,  // 30 minutos - condiciones del lote
			'batch_prices' => 1800,        // 30 minutos - precios procesados del lote
			'total_productos' => 3600,     // 1 hora - cantidad total de productos (crítico)
			'categorias_indexed' => 3600,  // 1 hora - categorías indexadas (optimizado)
			'categorias_web_indexed' => 3600, // 1 hora - categorías web indexadas (optimizado)
			'fabricantes_indexed' => 7200, // 2 horas - fabricantes indexados (optimizado)
			'colecciones_indexed' => 7200, // 2 horas - colecciones indexadas (optimizado)
			'cursos_indexed' => 7200,      // 2 horas - cursos indexados (optimizado)
			'asignaturas_indexed' => 7200, // 2 horas - asignaturas indexadas (optimizado)
			'campos_configurables' => 7200, // 2 horas - datos de referencia
			'arboles_campos' => 7200,      // 2 horas - datos de referencia
			'valores_validados' => 7200,   // 2 horas - datos de referencia
			'completion_time' => 7200,     // 2 horas - tiempo de finalización del batch
			'error' => 7200,               // 2 horas - mensaje de error (si falla)
			'error_time' => 7200,          // 2 horas - tiempo del error (si falla)
		];

		// Usar método genérico set() para almacenar cada tipo de dato
		$stored_count = 0;
		foreach ( $ttl_config as $data_type => $ttl ) {
			if ( isset( $batch_data[ $data_type ] ) ) {
				$cache_key = "batch_{$batch_id}_$data_type";
				$compressed_data = $this->compressionManager ? 
					$this->compressionManager->compressForCache( $batch_data[ $data_type ] ) : 
					serialize( $batch_data[ $data_type ] );
				
				if ( $this->set( $cache_key, $compressed_data, $ttl ) ) {
					$stored_count++;
				}
			}
		}

		// Almacenar metadatos del batch usando método genérico
		$metadata = [
			'batch_id' => $batch_id,
			'rango' => $rango,
			'timestamp' => time(),
			'data_types' => array_keys( $ttl_config ),
			'stored_count' => $stored_count,
			'status' => 'completed'
		];

		$metadata_key = "batch_{$batch_id}_metadata";
		$this->set( $metadata_key, $metadata, 7200 ); // 2 horas para metadatos


		return $stored_count > 0;
	}

	/**
	 * Recupera datos del batch desde caché.
	 * Utiliza el método genérico get() para evitar duplicación.
	 * 
	 * @param string $batch_id ID único del batch
	 * @return array|false Datos del batch o false si no existe
	 *@since 1.0.0
	 */
	public function get_batch_data( string $batch_id ): bool|array
    {
		if (!$this->enabled) {
			return false;
		}

		// Obtener metadatos del batch usando método genérico
		$metadata_key = "batch_{$batch_id}_metadata";
		$metadata = $this->get( $metadata_key );

		if (!$metadata || !isset($metadata['data_types'])) {
			return false;
		}

		// Reconstruir datos del batch desde caché usando método genérico
		$batch_data = [
			'batch_id' => $batch_id,
			'rango' => $metadata['rango'],
			'timestamp' => $metadata['timestamp'],
			'status' => 'completed'
		];

		$recovered_count = 0;
		foreach ( $metadata['data_types'] as $data_type ) {
			$cache_key = "batch_{$batch_id}_$data_type";
			$compressed_data = $this->get( $cache_key );

			if ( $compressed_data !== false ) {
				$batch_data[ $data_type ] = $this->compressionManager ? 
					$this->compressionManager->decompressFromCache( $compressed_data ) : 
					unserialize( $compressed_data );
				$recovered_count++;
			}
		}

		// Solo retornar si se recuperaron la mayoría de los datos
		if ( $recovered_count >= ( count( $metadata['data_types'] ) * 0.8 ) ) {
			return $batch_data;
		}

		return false;
	}


	/**
	 * Verifica si existe un batch en caché.
	 * Utiliza el método genérico exists() para evitar duplicación.
	 * 
	 * @param string $batch_id ID único del batch
	 * @return bool True si existe
	 *@since 1.0.0
	 */
	public function batch_exists( string $batch_id ): bool
    {
		if (!$this->enabled) {
			return false;
		}

		$metadata_key = "batch_{$batch_id}_metadata";
		return $this->exists( $metadata_key );
	}

	/**
	 * Limpia datos de un batch específico.
	 * Utiliza el método genérico delete_by_pattern() para evitar duplicación.
	 * 
	 * @param string $batch_id ID único del batch
	 * @return bool True si se limpió correctamente
	 *@since 1.0.0
	 */
	public function clear_batch_data( string $batch_id ): bool
    {
		if (!$this->enabled) {
			return false;
		}

		// Usar método genérico delete_by_pattern() para limpiar todo el batch
		$pattern = "batch_{$batch_id}_*";
		$cleared_count = $this->delete_by_pattern( $pattern );


		return $cleared_count > 0;
	}

	/**
	 * Limpia lotes de caché antiguos basándose en la ventana de tiempo (time_bucket).
	 * 
	 * Este método implementa rotación automática de caché por ventana de tiempo,
	 * eliminando lotes que son más antiguos que el umbral configurado. El formato
	 * del batch_id es: `batch_data_{inicio}_{fin}_{time_bucket}` donde time_bucket
	 * es `date('Y-m-d-H')` (año-mes-día-hora).
	 * 
	 * **Propósito**: Prevenir acumulación de caché cuando sincronizaciones largas
	 * cruzan múltiples horas, especialmente útil para sistemas con ~8000 productos.
	 * 
	 * **Integración**: Se ejecuta automáticamente desde `clean_expired_cache()` y
	 * puede ser llamado manualmente cuando sea necesario.
	 * 
	 * @param   int     $max_age_hours   Edad máxima en horas para mantener lotes (default: 3)
	 * @return  array                    Resultado de la limpieza con estadísticas
	 * @throws  \InvalidArgumentException Si max_age_hours es inválido
	 * @since   1.0.0
	 * @see     CacheManager::clean_expired_cache() Para limpieza general de caché expirada
	 * @see     CacheManager::clear_batch_data() Para limpiar un batch específico
	 * @see     BatchProcessor::prepare_complete_batch_data() Para el formato del batch_id
	 */
	public function cleanupOldBatchCache(int $max_age_hours = 3): array
	{
		if (!$this->enabled) {
			return [
				'success' => false,
				'reason' => 'cache_disabled',
				'cleaned_count' => 0
			];
		}

		// ✅ VALIDACIÓN: Protección contra valores inválidos
		if ($max_age_hours < 1 || $max_age_hours > 24) {
			throw new \InvalidArgumentException(
				'max_age_hours debe estar entre 1 y 24 horas. Valor recibido: ' . $max_age_hours
			);
		}

		global $wpdb;

		// ✅ CALCULAR: Hora de corte basada en time_bucket (formato: Y-m-d-H)
		$current_time_bucket = date('Y-m-d-H');
		$cutoff_datetime = date_create($current_time_bucket);
		if ($cutoff_datetime === false) {
			$this->logger->error('Error creando datetime para time_bucket', [
				'current_time_bucket' => $current_time_bucket
			]);
			return [
				'success' => false,
				'reason' => 'datetime_creation_failed',
				'cleaned_count' => 0
			];
		}

		// Restar horas al datetime de corte
		$cutoff_datetime->modify("-{$max_age_hours} hours");
		$cutoff_time_bucket = $cutoff_datetime->format('Y-m-d-H');

		// ✅ BUSCAR: Transients con patrón batch_data_* que coincidan con nuestro prefijo
		$pattern = $this->cache_prefix . 'batch_data_%';
		$sql = $wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} 
			WHERE option_name LIKE %s 
			AND option_name LIKE %s",
			'_transient_' . $pattern,
			'_transient_%'
		);

		$transients = $wpdb->get_col($sql);
		$cleaned_count = 0;
		$skipped_count = 0;
		$error_count = 0;
		$batches_by_hour = [];

		// ✅ PROCESAR: Cada transient y extraer time_bucket del batch_id
		foreach ($transients as $transient) {
			$key = str_replace('_transient_', '', $transient);

			// Extraer batch_id del formato: {prefix}batch_{batch_id}_*
			// El batch_id tiene formato: batch_data_{inicio}_{fin}_{time_bucket}
			// Ejemplo: mia_cache_batch_batch_data_1_50_2025-01-15-14_metadata
			// El patrón busca: batch_batch_data_{inicio}_{fin}_{time_bucket}
			if (preg_match('/batch_(batch_data_\d+_\d+_(\d{4}-\d{2}-\d{2}-\d{2}))(?:_|$)/', $key, $matches)) {
				$batch_id = $matches[1];
				$time_bucket = $matches[2];

				// Agrupar por hora para estadísticas
				if (!isset($batches_by_hour[$time_bucket])) {
					$batches_by_hour[$time_bucket] = 0;
				}
				$batches_by_hour[$time_bucket]++;

				// ✅ COMPARAR: Si el time_bucket es anterior al de corte, limpiar
				if ($time_bucket < $cutoff_time_bucket) {
					// Limpiar todo el batch usando el método existente
					$cleared = $this->clear_batch_data($batch_id);
					if ($cleared) {
						$cleaned_count++;
					} else {
						$error_count++;
					}
				} else {
					$skipped_count++;
				}
			} else {
				// No coincide con el formato esperado, saltar
				$skipped_count++;
			}
		}

		// ✅ LOGGING: Registrar resultados de la limpieza
		if ($cleaned_count > 0) {
			$this->logger->info('Lotes de caché antiguos limpiados por rotación de ventana de tiempo', [
				'cleaned_count' => $cleaned_count,
				'skipped_count' => $skipped_count,
				'error_count' => $error_count,
				'max_age_hours' => $max_age_hours,
				'cutoff_time_bucket' => $cutoff_time_bucket,
				'current_time_bucket' => $current_time_bucket,
				'batches_by_hour' => $batches_by_hour
			]);
		}

		return [
			'success' => true,
			'cleaned_count' => $cleaned_count,
			'skipped_count' => $skipped_count,
			'error_count' => $error_count,
			'max_age_hours' => $max_age_hours,
			'cutoff_time_bucket' => $cutoff_time_bucket,
			'current_time_bucket' => $current_time_bucket,
			'batches_by_hour' => $batches_by_hour
		];
	}

	/**
	 * Optimiza el tamaño de un transient específico
	 * 
	 * @since 1.0.0
	 * @param string $cacheKey Clave del caché a optimizar
	 * @return array Resultado de la optimización
	 */
	public function optimizeTransientSize(string $cacheKey): array {
		try {
			$cacheData = get_transient($cacheKey);
			
			if ($cacheData === false) {
				return [
					'success' => false,
					'message' => 'Transient no encontrado',
					'cache_key' => $cacheKey
				];
			}
			
			$originalSize = $this->estimate_size($cacheData);
			$optimizedData = $this->compressData($cacheData);
			$optimizedSize = $this->estimate_size($optimizedData);
			
			// Solo actualizar si hay reducción significativa
			if ($optimizedSize < $originalSize * 0.9) { // 10% de reducción
				set_transient($cacheKey, $optimizedData, HOUR_IN_SECONDS);
				
				// Calcular porcentaje de reducción de forma segura
				$reduction_calc = (($originalSize - $optimizedSize) / $originalSize) * 100;
				$reduction_percentage = $this->safe_round($reduction_calc, 2);
				
				return [
					'success' => true,
					'original_size' => $this->format_size($originalSize),
					'optimized_size' => $this->format_size($optimizedSize),
					'reduction_percentage' => $reduction_percentage,
					'message' => 'Transient optimizado correctamente'
				];
			}
			
			return [
				'success' => true,
				'message' => 'No se requiere optimización',
				'original_size' => $this->format_size($originalSize),
				'optimized_size' => $this->format_size($optimizedSize)
			];
			
		} catch (Exception $e) {
			return [
				'success' => false,
				'error' => $e->getMessage(),
				'message' => 'Error optimizando transient'
			];
		}
	}

	/**
	 * Comprime datos para optimizar tamaño
	 * 
	 * @since 1.0.0
	 * @param mixed $data Datos a comprimir
	 * @return mixed Datos comprimidos
	 */
	private function compressData(mixed $data): mixed
    {
		// Delegar a CompressionManager si está disponible
		if ( $this->compressionManager instanceof CompressionManager ) {
			$compressed = $this->compressionManager->compressData( $data, 'auto' );
			return $compressed !== false ? $compressed : $data;
		}
		
		// Fallback: implementación básica
		if (is_string($data) && strlen($data) > 1024) {
			// Comprimir strings largos
			return gzcompress($data, 1);
		}
		
		if (is_array($data)) {
			// Optimizar arrays eliminando valores vacíos
			$data = array_filter($data, function($value) {
				return $value !== null && $value !== '' && $value !== [];
			});
		}
		
		return $data;
	}

	/**
	 * Obtiene métricas del caché
	 * 
	 * @since 1.0.0
	 * @return array Métricas del caché
	 */
	public function getCacheMetrics(): array {
		try {
			$total_transients = 0;
			$total_size = 0;
			$expired_count = 0;
			
			// Obtener métricas básicas
			$hits = (int) get_option(MiIntegracionApi_OPTION_PREFIX . 'cache_hits', 0);
			$misses = (int) get_option(MiIntegracionApi_OPTION_PREFIX . 'cache_misses', 0);
			
			// Calcular hit rate
			$hit_rate_calc = ($hits + $misses) > 0 ? ($hits / ($hits + $misses)) * 100 : 0;
			$hit_rate = $this->safe_round($hit_rate_calc, 2);
			
			return [
				'success' => true,
				'total_transients' => $total_transients,
				'total_size_mb' => $this->safe_round($total_size / 1024 / 1024, 2),
				'expired_count' => $expired_count,
				'hit_rate' => $hit_rate,
				'cache_hits' => $hits,
				'cache_misses' => $misses,
				'cache_enabled' => $this->enabled,
				'cache_directory' => $this->cache_dir
			];
		} catch (Exception $e) {
			return [
				'success' => false,
				'error' => $e->getMessage(),
				'message' => 'Error obteniendo métricas de caché'
			];
		}
	}

	/**
	 * Limpia todos los caches (transients y archivos)
	 * 
	 * @since 1.0.0
	 * @return array Resultado de la limpieza
	 */
	public function cleanAllCaches(): array {
		try {
			// Limpiar transients de WordPress
			$transient_result = $this->clean_expired_cache();
			
			// Limpiar archivos de caché
			$file_result = $this->cleanup_cache_files();
			
			
			return [
				'success' => true,
				'transients_cleaned' => $transient_result,
				'files_cleaned' => $file_result,
				'message' => 'Todos los caches limpiados correctamente'
			];
		} catch (Exception $e) {
            $this->logger->error('Error limpiando todos los caches', [
                'error' => $e->getMessage()
            ]);
			return [
				'success' => false,
				'error' => $e->getMessage(),
				'message' => 'Error limpiando caches'
			];
		}
	}

	/**
	 * Limpia archivos de caché al desactivar el plugin.
	 * 
	 * @since 1.0.0
	 * @return bool True si se limpió correctamente
	 */
	public function cleanup_cache_files(): bool {
		if (!$this->cache_dir || !is_dir($this->cache_dir)) {
			return false;
		}
		
		try {
			// Eliminar archivos de caché pero mantener archivos de seguridad
			$files = glob( $this->cache_dir . '/*.cache' );
			$files = array_merge( $files, glob( $this->cache_dir . '/*.tmp' ) );
			$files = array_merge( $files, glob( $this->cache_dir . '/*.log' ) );
			
			$deleted_count = 0;
			foreach ( $files as $file ) {
				if ( is_file( $file ) && unlink( $file ) ) {
					$deleted_count++;
				}
			}
			
			// Limpiar directorios vacíos (excepto el directorio principal)
			$this->cleanup_empty_directories( $this->cache_dir );
			
			
			return true;
			
		} catch ( Exception $e ) {
            $this->logger->error(
                'Error limpiando archivos de caché',
                [
                    'error' => $e->getMessage(),
                    'cache_dir' => $this->cache_dir
                ]
            );
			return false;
		}
	}

	/**
	 * Limpia directorios vacíos recursivamente.
	 * 
	 * @param string $dir Directorio a limpiar
	 *@since 1.0.0
	 * @access   protected
	 */
	protected function cleanup_empty_directories( string $dir ): void
    {
		if (!is_dir($dir)) {
			return;
		}
		
		$items = scandir( $dir );
		foreach ( $items as $item ) {
			if ( $item === '.' || $item === '..' ) {
				continue;
			}
			
			$path = $dir . '/' . $item;
			
			if ( is_dir( $path ) ) {
				// Limpiar subdirectorios recursivamente
				$this->cleanup_empty_directories( $path );
				
				// Verificar si el directorio está vacío después de la limpieza
				$sub_items = scandir( $path );
				$sub_items = array_diff( $sub_items, [ '.', '..' ] );
				
				if ( empty( $sub_items ) ) {
					rmdir( $path );
				}
			}
		}
	}

	/**
	 * Destructor. Limpia recursos cuando se destruye la instancia.
	 *
	 * @since 1.0.0
	 */
	public function __destruct() {
		// Nada que hacer por ahora
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Limpieza de emergencia de caché
	 * Elimina caché no esencial inmediatamente
	 * 
	 * @return void
	 */
	public function emergencyCacheCleanup(): void {
		// Eliminar caché no esencial inmediatamente
		$non_essential_cache = [
			'debug_cache',
			'temp_data_cache',
			'old_sync_data_cache'
		];
		
		foreach ($non_essential_cache as $cache_key) {
			wp_cache_delete($cache_key, 'mi_integration');
		}
		
		// Limpiar transients antiguos
		$this->cleanupOldTransients();
		
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Limpieza preventiva de caché
	 * Elimina caché que no sea crítico
	 * 
	 * @return void
	 */
	public function preventiveCacheCleanup(): void {
		// Eliminar caché que no sea crítico
		$non_critical_cache = [
			'old_debug_cache',
			'temp_export_cache',
			'old_analytics_cache'
		];
		
		foreach ($non_critical_cache as $cache_key) {
			wp_cache_delete($cache_key, 'mi_integration');
		}
		
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Limpia transients antiguos
	 * 
	 * @return void
	 */
	private function cleanupOldTransients(): void {
		global $wpdb;
		
		// Limpiar transients de progreso antiguos (más de 1 hora)
		$cutoff_time = time() - 3600; // 1 hora
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
		
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene la política de retención para una clave de caché
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return array Política de retención
	 */
	public function getRetentionPolicy(string $cacheKey): array
	{
		$config = $this->getCustomTTLConfiguration($cacheKey);
		
		$policy = $config['retention_policy'] ?? [
			'type' => 'temporary',
			'strategy' => 'immediate_cleanup',
			'keep_always' => false,
			'cleanup_after_sync' => false,
			'cleanup_by_age' => false,
			'cleanup_by_inactivity' => false,
			'cleanup_immediate' => true,
			'max_age_hours' => 1,
			'inactivity_threshold_hours' => 2
		];
		
		// Validar y normalizar la política
		$validTypes = ['critical', 'sync', 'cache', 'state', 'temporary'];
		$validStrategies = ['keep_always', 'sync_complete', 'age_based', 'inactivity_based', 'immediate_cleanup'];
		
		if (!in_array($policy['type'], $validTypes)) {
			$policy['type'] = 'temporary';
		}
		
		if (!in_array($policy['strategy'], $validStrategies)) {
			$policy['strategy'] = 'immediate_cleanup';
		}
		
		// Asegurar valores booleanos
		$policy['keep_always'] = (bool) ($policy['keep_always'] ?? false);
		$policy['cleanup_after_sync'] = (bool) ($policy['cleanup_after_sync'] ?? false);
		$policy['cleanup_by_age'] = (bool) ($policy['cleanup_by_age'] ?? false);
		$policy['cleanup_by_inactivity'] = (bool) ($policy['cleanup_by_inactivity'] ?? false);
		$policy['cleanup_immediate'] = (bool) ($policy['cleanup_immediate'] ?? false);
		
		// Asegurar valores numéricos
		$policy['max_age_hours'] = (int) ($policy['max_age_hours'] ?? 1);
		$policy['inactivity_threshold_hours'] = (int) ($policy['inactivity_threshold_hours'] ?? 2);
		
		return $policy;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene la configuración personalizada de TTL
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return array Configuración de TTL
	 */
	public function getCustomTTLConfiguration(string $cacheKey): array
	{
		// Configuración por defecto
		$default_config = [
			'retention_policy' => [
				'type' => 'temporary',
				'strategy' => 'immediate_cleanup',
				'keep_always' => false,
				'cleanup_after_sync' => false,
				'cleanup_by_age' => false,
				'cleanup_by_inactivity' => false,
				'cleanup_immediate' => true,
				'max_age_hours' => 1,
				'inactivity_threshold_hours' => 2
			]
		];
		
		// Configuraciones específicas por tipo de clave
		$specific_configs = [
			'mia_sync_current_batch_offset' => [
				'retention_policy' => [
					'type' => 'sync',
					'strategy' => 'sync_complete',
					'keep_always' => false,
					'cleanup_after_sync' => true,
					'cleanup_by_age' => true,
					'cleanup_by_inactivity' => false,
					'cleanup_immediate' => false,
					'max_age_hours' => 24,
					'inactivity_threshold_hours' => 2
				]
			],
			'mia_sync_current_batch_limit' => [
				'retention_policy' => [
					'type' => 'sync',
					'strategy' => 'sync_complete',
					'keep_always' => false,
					'cleanup_after_sync' => true,
					'cleanup_by_age' => true,
					'cleanup_by_inactivity' => false,
					'cleanup_immediate' => false,
					'max_age_hours' => 24,
					'inactivity_threshold_hours' => 2
				]
			],
			'mia_sync_current_batch_time' => [
				'retention_policy' => [
					'type' => 'sync',
					'strategy' => 'sync_complete',
					'keep_always' => false,
					'cleanup_after_sync' => true,
					'cleanup_by_age' => true,
					'cleanup_by_inactivity' => false,
					'cleanup_immediate' => false,
					'max_age_hours' => 24,
					'inactivity_threshold_hours' => 2
				]
			]
		];
		
		// Retornar configuración específica si existe, o la por defecto
		return $specific_configs[$cacheKey] ?? $default_config;
	}

	/**
	 * @param string $cacheKey Clave del caché
	 * @return bool True si es crítico
	 */
	public function isCriticalTransient(string $cacheKey): bool
	{
		// Obtener configuración del transient
		$config = $this->getCustomTTLConfiguration($cacheKey);		

		// Reglas adicionales: claves esenciales del sistema
		$critical_keys = [
			'mia_sync_lock',
			'mia_snapshot_in_progress',
			'mia_last_successful_sync',
		];

		foreach ($critical_keys as $key_prefix) {
			if (str_starts_with($cacheKey, $key_prefix)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene el horario de limpieza configurado para un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return array Horario de limpieza
	 */
	public function getCleanupSchedule(string $cacheKey): array
	{
		$config = $this->getCustomTTLConfiguration($cacheKey);
		
		$schedule = $config['cleanup_schedule'] ?? [
			'frequency' => 'daily',
			'time' => '05:00',
			'priority' => 'low'
		];
		
		// Validar y normalizar el horario
		$validFrequencies = ['never', 'hourly', 'daily', 'weekly'];
		$validPriorities = ['low', 'medium', 'high', 'critical'];
		
		if (!in_array($schedule['frequency'], $validFrequencies)) {
			$schedule['frequency'] = 'daily';
		}
		
		if (!in_array($schedule['priority'], $validPriorities)) {
			$schedule['priority'] = 'low';
		}
		
		// Validar formato de tiempo (HH:MM)
		if ($schedule['time'] && !preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $schedule['time'])) {
			$schedule['time'] = '05:00';
		}
		
		return $schedule;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Ejecuta limpieza progresiva de caché por chunks
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param int $chunkSize Tamaño del chunk en bytes
	 * @return array Resultado de la limpieza
	 */
	public function executeProgressiveCleanup(string $cacheKey, int $chunkSize = 10 * 1024 * 1024): array
	{
		// VALIDACIÓN CRÍTICA - Protección contra valores maliciosos
		if (empty($cacheKey) || strlen($cacheKey) > 255) {
			throw new InvalidArgumentException(
				'cacheKey debe ser una cadena válida entre 1 y 255 caracteres. Longitud recibida: ' . strlen($cacheKey)
			);
		}
		
		if ($chunkSize < 1024 * 1024 || $chunkSize > 100 * 1024 * 1024) {
			$chunk_size_mb = $this->safe_round($chunkSize / (1024 * 1024), 2);
			throw new InvalidArgumentException(
				'chunkSize debe estar entre 1MB y 100MB. Valor recibido: ' . $chunk_size_mb . 'MB'
			);
		}
		
		$result = [
			'success' => false,
			'original_size_mb' => 0,
			'final_size_mb' => 0,
			'space_freed_mb' => 0,
			'chunks_processed' => 0,
			'execution_time' => 0,
			'errors' => []
		];
		
		$startTime = time();
		
		try {
			$cacheData = get_transient($cacheKey);
			if ($cacheData === false) {
				$result['errors'][] = 'Transient no encontrado';
				return $result;
			}
			
			$originalSize = $this->calculateTransientSize($cacheData);
			$result['original_size_mb'] = $this->safe_round($originalSize / (1024 * 1024), 2);
			
			// Solo procesar si es muy grande (>50MB)
			if ($originalSize < 50 * 1024 * 1024) {
				$result['errors'][] = 'Transient no es suficientemente grande para limpieza progresiva';
				return $result;
			}
			
			// Verificar si es crítico
			if ($this->isCriticalTransient($cacheKey)) {
				$result['errors'][] = 'Transient crítico, no se puede limpiar progresivamente';
				return $result;
			}
			
			// Ejecutar limpieza progresiva
			$cleanupResult = $this->progressiveCleanupChunks($cacheData, $chunkSize);
			
			if ($cleanupResult['success']) {
				// Actualizar transient con datos limpios
				$updated = set_transient($cacheKey, $cleanupResult['cleaned_data'], $this->getTransientTTL($cacheKey));
				
				if ($updated) {
					$finalSize = $this->calculateTransientSize($cleanupResult['cleaned_data']);
					$result['final_size_mb'] = $this->safe_round($finalSize / (1024 * 1024), 2);
					$space_freed_calc = ($originalSize - $finalSize) / (1024 * 1024);
					$result['space_freed_mb'] = $this->safe_round($space_freed_calc, 2);
					$result['chunks_processed'] = $cleanupResult['chunks_processed'];
					$result['success'] = true;
					
					// Registrar en historial
					$this->recordProgressiveCleanupHistory($cacheKey, $originalSize, $finalSize);
				} else {
					$result['errors'][] = 'No se pudo actualizar el transient';
				}
			} else {
				$result['errors'] = $cleanupResult['errors'];
			}
			
		} catch (Exception $e) {
			$result['errors'][] = 'Excepción durante limpieza progresiva: ' . $e->getMessage();
		}
		
		$result['execution_time'] = time() - $startTime;
		
		return $result;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Procesa limpieza progresiva por chunks
	 * 
	 * @param mixed $data Datos del transient
	 * @param int $chunkSize Tamaño del chunk en bytes
	 * @return array Resultado del procesamiento
	 */
	private function progressiveCleanupChunks(mixed $data, int $chunkSize): array
	{
		$result = [
			'success' => false,
			'cleaned_data' => null,
			'chunks_processed' => 0,
			'errors' => []
		];
		
		try {
			if (is_array($data)) {
				$result['cleaned_data'] = $this->cleanupArrayChunks($data, $chunkSize);
			} elseif (is_string($data)) {
				$result['cleaned_data'] = $this->cleanupStringChunks($data, $chunkSize);
			} elseif (is_object($data)) {
				$result['cleaned_data'] = $this->cleanupObjectChunks($data, $chunkSize);
			} else {
				$result['cleaned_data'] = $data; // No se puede limpiar progresivamente
			}
			
			$result['success'] = true;
			$result['chunks_processed'] = $this->countProcessedChunks($result['cleaned_data']);
			
		} catch (Exception $e) {
			$result['errors'][] = 'Error en procesamiento por chunks: ' . $e->getMessage();
		}
		
		return $result;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Limpia arrays por chunks para evitar agotar memoria
	 * 
	 * @param array $data Array a limpiar
	 * @param int $chunkSize Tamaño del chunk en bytes
	 * @return array Array limpio
	 */
	private function cleanupArrayChunks(array $data, int $chunkSize): array
	{
		$cleanedData = [];
		$currentChunkSize = 0;
		$chunkIndex = 0;
		
		foreach ($data as $key => $value) {
			$itemSize = $this->calculateTransientSize($value);
			
			// Si el item actual excede el chunk, procesarlo por separado
			if ($itemSize > $chunkSize) {
				$cleanedValue = $this->cleanupLargeItem($value, $chunkSize);
				$cleanedData[$key] = $cleanedValue;
				$currentChunkSize += $this->calculateTransientSize($cleanedValue);
			} else {
				// Verificar si agregar este item excedería el chunk
				if (($currentChunkSize + $itemSize) > $chunkSize) {
					// Pausa para liberar memoria
					$this->memoryManagementPause();
					$currentChunkSize = 0;
					$chunkIndex++;
				}
				
				$cleanedData[$key] = $value;
				$currentChunkSize += $itemSize;
			}
		}
		
		return $cleanedData;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Limpia strings largos por chunks
	 * 
	 * @param string $data String a limpiar
	 * @param int $chunkSize Tamaño del chunk en bytes
	 * @return string String limpio
	 */
	public function cleanupStringChunks(string $data, int $chunkSize): string
	{
		if (strlen($data) <= $chunkSize) {
			return $data;
		}
		
		// Dividir string en chunks y procesar cada uno
		$chunks = str_split($data, $chunkSize);
		$cleanedChunks = [];
		
		foreach ($chunks as $index => $chunk) {
			// Pausa entre chunks para gestión de memoria
			if ($index > 0) {
				$this->memoryManagementPause();
			}
			
			$cleanedChunks[] = $chunk;
		}
		
		return implode('', $cleanedChunks);
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Limpia objetos por chunks
	 * 
	 * @param object $data Objeto a limpiar
	 * @param int $chunkSize Tamaño del chunk en bytes
	 * @return object Objeto limpio
	 */
	private function cleanupObjectChunks(object $data, int $chunkSize): object
	{
		// Para objetos, intentar limpiar propiedades grandes
		$reflection = new ReflectionObject($data);
		$properties = $reflection->getProperties();
		
		foreach ($properties as $property) {
			if (!$property->isPublic()) {
				$property->setAccessible(true);
			}
			$value = $property->getValue($data);
			
			$valueSize = $this->calculateTransientSize($value);
			if ($valueSize > $chunkSize) {
				$cleanedValue = $this->cleanupLargeItem($value, $chunkSize);
				$property->setValue($data, $cleanedValue);
			}
		}
		
		return $data;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Limpia items grandes individualmente
	 * 
	 * @param mixed $item Item a limpiar
	 * @param int $chunkSize Tamaño del chunk en bytes
	 * @return mixed Item limpio
	 */
	private function cleanupLargeItem(mixed $item, int $chunkSize): mixed
    {
		if (is_array($item)) {
			return $this->cleanupArrayChunks($item, $chunkSize);
		} elseif (is_string($item)) {
			return $this->cleanupStringChunks($item, $chunkSize);
		} elseif (is_object($item)) {
			return $this->cleanupObjectChunks($item, $chunkSize);
		}
		
		return $item;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Pausa para gestión de memoria
	 * 
	 * @return void
	 */
	private function memoryManagementPause(): void
	{
		// Pausa breve para permitir garbage collection
		usleep(10000); // 10ms
		
		// Forzar garbage collection si está disponible
		if (function_exists('gc_collect_cycles')) {
			gc_collect_cycles();
		}
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Cuenta chunks procesados
	 * 
	 * @param mixed $data Datos procesados
	 * @return int Número de chunks
	 */
	private function countProcessedChunks(mixed $data): int
	{
		if (is_array($data)) {
			return count($data);
		} elseif (is_string($data)) {
			return (int) ceil(strlen($data) / 1024); // Aproximación por KB
		}
		
		return 1;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene TTL de un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return int TTL en segundos
	 */
	private function getTransientTTL(string $cacheKey): int
	{
		$timeout_key = '_transient_timeout_' . $cacheKey;
		$timeout = get_option($timeout_key);
		
		if ($timeout === false) {
			return $this->default_ttl;
		}
		
		$remaining = $timeout - time();
		return max(0, $remaining);
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Registra historial de limpieza progresiva
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param int $originalSize Tamaño original en bytes
	 * @param int $finalSize Tamaño final en bytes
	 * @return void
	 */
	private function recordProgressiveCleanupHistory(string $cacheKey, int $originalSize, int $finalSize): void
	{
		$history_key = 'mia_progressive_cleanup_history_' . $cacheKey;
		$history = get_option($history_key, []);
		
		// Calcular valores de forma segura
		$original_mb = $this->safe_round($originalSize / (1024 * 1024), 2);
		$final_mb = $this->safe_round($finalSize / (1024 * 1024), 2);
		$space_freed_calc = ($originalSize - $finalSize) / (1024 * 1024);
		$space_freed_mb = $this->safe_round($space_freed_calc, 2);
		$efficiency_calc = (($originalSize - $finalSize) / $originalSize) * 100;
		$efficiency_percentage = $this->safe_round($efficiency_calc, 2);
		
		$cleanup_record = [
			'timestamp' => current_time('timestamp'),
			'original_size_mb' => $original_mb,
			'final_size_mb' => $final_mb,
			'space_freed_mb' => $space_freed_mb,
			'efficiency_percentage' => $efficiency_percentage
		];
		
		// Mantener solo los últimos 10 registros
		$history[] = $cleanup_record;
		if (count($history) > 10) {
			$history = array_slice($history, -10);
		}
		
		update_option($history_key, $history);
		
	}

	/**
	 * OPTIMIZADO: Calcula el tamaño de un transient con caché y límites de recursión
	 * 
	 * @param mixed $data Datos a medir
	 * @param bool $includeOverhead Si incluir overhead de PHP
	 * @param int $depth Nivel de recursión actual (para evitar stack overflow)
	 * @param array $cache Caché de tamaños calculados
	 * @return int Tamaño en bytes
	 */
	private function calculateTransientSize(mixed $data, bool $includeOverhead = true, int $depth = 0, array &$cache = []): int
	{
		// LÍMITE DE RECURSIÓN PARA EVITAR STACK OVERFLOW
		if ($depth > 10) {
			return 1024; // Valor seguro por defecto
		}
		
		// GENERAR CLAVE DE CACHÉ ÚNICA
		$cacheKey = $this->generateCacheKey($data, $depth);
		
		// VERIFICAR CACHÉ PRIMERO
		if (isset($cache[$cacheKey])) {
			return $cache[$cacheKey];
		}
		
		// OPTIMIZACIÓN: USAR MATCH PARA MEJOR RENDIMIENTO Y LEGIBILIDAD
		$size = match (gettype($data)) {
			'string' => strlen($data),
			'array' => $this->calculateArraySize($data, $depth, $cache),
			'object' => $this->calculateObjectSize($data, $depth, $cache),
			'integer', 'double' => strlen((string) $data),
			'boolean' => 1,
			default => 64 // Tamaño seguro para tipos desconocidos
		};
		
		// AÑADIR OVERHEAD DE PHP SI SE SOLICITA
		if ($includeOverhead) {
			$size += $this->calculatePHPSizeOverhead($data);
		}
		
		// GUARDAR EN CACHÉ
		$cache[$cacheKey] = $size;
		
		return $size;
	}
	
	/**
	 * OPTIMIZADO: Calcula tamaño de arrays con límites de memoria
	 * 
	 * @param array $data Array a medir
	 * @param int $depth Nivel de recursión
	 * @param array $cache Caché de tamaños
	 * @return int Tamaño en bytes
	 */
	private function calculateArraySize(array $data, int $depth, array &$cache): int
	{
		$size = 0;
		$maxItems = 1000; // Límite para evitar procesamiento excesivo
		$itemCount = 0;
		
		foreach ($data as $key => $value) {
			// LÍMITE DE ITEMS PARA EVITAR TIEMPO EXCESIVO
			if ($itemCount++ >= $maxItems) {
				$size += 1024; // Estimación para items restantes
				break;
			}
			
			// OPTIMIZACIÓN: CALCULAR TAMAÑO DE CLAVE UNA SOLA VEZ
			$keySize = strlen($key);
			$valueSize = $this->calculateTransientSize($value, false, $depth + 1, $cache);
			
			$size += $keySize + $valueSize;
			
			// VERIFICAR LÍMITE DE MEMORIA (1MB)
			if ($size > 1024 * 1024) {
				$size = 1024 * 1024; // Límite máximo
				break;
			}
		}
		
		return $size;
	}
	
	/**
	 * OPTIMIZADO: Calcula tamaño de objetos sin serialización costosa
	 * 
	 * @param object $data Objeto a medir
	 * @param int $depth Nivel de recursión
	 * @param array $cache Caché de tamaños
	 * @return int Tamaño en bytes
	 */
	private function calculateObjectSize(object $data, int $depth, array &$cache): int
	{
		// OPTIMIZACIÓN: EVITAR SERIALIZACIÓN COSTOSA
		try {
			// INTENTAR REFLECTION PRIMERO (más eficiente)
			if (class_exists('ReflectionObject')) {
				$reflection = new ReflectionObject($data);
				$properties = $reflection->getProperties();
				
				$size = 0;
				$maxProperties = 100; // Límite para evitar procesamiento excesivo
				$propertyCount = 0;
				
				foreach ($properties as $property) {
					if ($propertyCount++ >= $maxProperties) {
						$size += 512; // Estimación para propiedades restantes
						break;
					}
					
					try {
						if (!$property->isPublic()) {
							$property->setAccessible(true);
						}
						$value = $property->getValue($data);
						$size += $this->calculateTransientSize($value, false, $depth + 1, $cache);
					} catch (Exception $e) {
						$size += 64; // Tamaño seguro para propiedades inaccesibles
					}
				}
				
				return $size;
			}
		} catch (Exception $e) {
			// FALLBACK: Usar serialización solo si es necesario
			try {
				return strlen(serialize($data));
			} catch (Exception $e2) {
				return 1024; // Tamaño seguro por defecto
			}
		}
		
		return 1024; // Tamaño seguro por defecto
	}
	
	/**
	 * OPTIMIZADO: Genera clave de caché única para datos
	 * 
	 * @param mixed $data Datos
	 * @param int $depth Nivel de recursión
	 * @return string Clave de caché
	 */
	private function generateCacheKey(mixed $data, int $depth): string
	{
		// OPTIMIZACIÓN: CLAVE SIMPLE PARA TIPOS BÁSICOS
		if (is_scalar($data) || is_null($data)) {
			return gettype($data) . '_' . md5(serialize($data)) . '_' . $depth;
		}
		
		// OPTIMIZACIÓN: CLAVE COMPLEJA PARA ESTRUCTURAS
		if (is_array($data)) {
			return 'array_' . count($data) . '_' . md5(implode('', array_keys($data))) . '_' . $depth;
		}
		
		if (is_object($data)) {
			return 'object_' . get_class($data) . '_' . spl_object_hash($data) . '_' . $depth;
		}
		
		return 'unknown_' . md5(serialize($data)) . '_' . $depth;
	}
	
	/**
	 * OPTIMIZADO: Calcula overhead de PHP de forma inteligente
	 * 
	 * @param mixed $data Datos
	 * @return int Overhead en bytes
	 */
	private function calculatePHPSizeOverhead(mixed $data): int
	{
		// OVERHEAD ADAPTATIVO BASADO EN TIPO Y TAMAÑO
		$baseOverhead = 64; // Overhead base
		
		if (is_array($data)) {
			$baseOverhead += count($data) * 8; // 8 bytes por elemento del array
		} elseif (is_object($data)) {
			$baseOverhead += 128; // Overhead adicional para objetos
		} elseif (is_string($data)) {
			$baseOverhead += strlen($data) * 0.1; // 10% adicional para strings
		}
		
		return (int) min($baseOverhead, 1024); // Límite máximo de 1KB
	}

    /**
	 * @since 1.0.0
	 * @access private
	 * @param string $cacheKey Clave del caché a limpiar
	 * @param array $cacheData Datos del caché que necesitan limpieza
	 * @param int $maxSize Tamaño máximo permitido en bytes
	 * @return array Datos limpios después de aplicar la estrategia de limpieza
	 */
	public function performIntelligentCleanup(string $cacheKey, array $cacheData, int $maxSize): array
	{
		//  Determinar estrategia de limpieza según el tipo de transient
		$cleanupStrategy = $this->getCleanupStrategy($cacheKey);
		$self = $this; // Importación local para evitar repetir $this->
		
		return match($cleanupStrategy) {
			'priority_based'   => $self->cleanupByPriority($cacheData, $maxSize),
			'time_based'       => $self->cleanupByTime($cacheData, $maxSize),
			'size_based'       => $self->cleanupBySize($cacheData, $maxSize),
			'usage_based'      => $self->cleanupByUsage($cacheData, $maxSize),
			'dependency_based' => $self->cleanupByDependencies($cacheData, $maxSize),
			default            => $self->cleanupRecentFirst($cacheData, $maxSize)
		};
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Estrategia de limpieza basada en prioridad
	 * 
	 * @param array $cacheData Datos del caché
	 * @param int $maxSize Tamaño máximo
	 * @return array Datos limpios
	 */
	private function cleanupByPriority(array $cacheData, int $maxSize): array
	{
		//  Mantener elementos con prioridad alta
		$priorityData = [];
		$regularData = [];
		
		foreach ($cacheData as $key => $value) {
			if ($this->isHighPriorityData($key, $value)) {
				$priorityData[$key] = $value;
			} else {
				$regularData[$key] = $value;
			}
		}
		
		//  Si hay muchos datos prioritarios, mantener solo los más importantes
		if (count($priorityData) > $maxSize) {
			$priorityData = array_slice($priorityData, -$maxSize, $maxSize, true);
		}
		
		//  Completar con datos regulares si hay espacio
		$remainingSpace = $maxSize - count($priorityData);
		if ($remainingSpace > 0 && !empty($regularData)) {
			$regularData = array_slice($regularData, -$remainingSpace, $remainingSpace, true);
			return array_merge($priorityData, $regularData);
		}
		
		return $priorityData;
	}

	/**
	 * 
	 * @param array $cacheData Datos del caché
	 * @param int $maxSize Tamaño máximo
	 * @return array Datos limpios
	 */
	private function cleanupByTime(array $cacheData, int $maxSize): array
	{
		//  Ordenar por timestamp si está disponible
		$timedData = [];
		foreach ($cacheData as $key => $value) {
			$timestamp = $this->extractTimestamp($key, $value);
			$timedData[$key] = $timestamp;
		}
		
		//  Ordenar por tiempo (más reciente primero)
		arsort($timedData);
		
		//  Mantener solo los más recientes
		$recentKeys = array_slice(array_keys($timedData), 0, $maxSize, true);
		$cleanedData = [];
		
		foreach ($recentKeys as $key) {
			$cleanedData[$key] = $cacheData[$key];
		}
		
		return $cleanedData;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Estrategia de limpieza basada en tamaño
	 * 
	 * @param array $cacheData Datos del caché
	 * @param int $maxSize Tamaño máximo
	 * @return array Datos limpios
	 */
	private function cleanupBySize(array $cacheData, int $maxSize): array
	{
		//  Calcular tamaño de cada elemento
		$sizedData = [];
		foreach ($cacheData as $key => $value) {
			$size = $this->estimateTransientSize($value);
			$sizedData[$key] = $size;
		}
		
		//  Ordenar por tamaño (más pequeños primero)
		asort($sizedData);
		
		//  Mantener elementos pequeños hasta alcanzar el límite
		$cleanedData = [];
		$currentSize = 0;
		
		foreach ($sizedData as $key => $size) {
			if ($currentSize + $size <= $maxSize * 1024) { // Convertir a bytes
				$cleanedData[$key] = $cacheData[$key];
				$currentSize += $size;
			} else {
				break;
			}
		}
		
		return $cleanedData;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Estrategia de limpieza por defecto (más recientes primero)
	 * 
	 * @param array $cacheData Datos del caché
	 * @param int $maxSize Tamaño máximo
	 * @return array Datos limpios
	 */
	private function cleanupRecentFirst(array $cacheData, int $maxSize): array
	{
		//  Mantener solo los elementos más recientes
		return array_slice($cacheData, -$maxSize, $maxSize, true);
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Estrategia de limpieza basada en uso
	 * 
	 * @param array $cacheData Datos del caché
	 * @param int $maxSize Tamaño máximo
	 * @return array Datos limpios
	 */
	private function cleanupByUsage(array $cacheData, int $maxSize): array
	{
		// Obtener métricas de uso para cada elemento
		$usageData = [];
		foreach ($cacheData as $key => $value) {
			$usageMetrics = get_option('mia_transient_usage_metrics_' . $key, []);
			$usageScore = $usageMetrics['usage_score'] ?? 0;
			$accessFrequency = $usageMetrics['access_frequency'] ?? 'very_low';
			
			// Convertir frecuencia a score numérico (0-100)
			$frequencyScore = $this->convertFrequencyToScore($accessFrequency);
			
			// Normalizar frecuencia a 0-1 para el cálculo combinado
			$normalizedFrequency = $frequencyScore / 100;
			
			// Score combinado: 70% uso + 30% frecuencia
			$combinedScore = ($usageScore * 0.7) + ($normalizedFrequency * 0.3);
			
			$usageData[$key] = $combinedScore;
		}
		
		// Ordenar por score de uso (más alto primero)
		arsort($usageData);
		
		// Mantener solo los elementos con mayor uso
		$highUsageKeys = array_slice(array_keys($usageData), 0, $maxSize, true);
		$cleanedData = [];
		
		foreach ($highUsageKeys as $key) {
			$cleanedData[$key] = $cacheData[$key];
		}
		
		
		return $cleanedData;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Estrategia de limpieza basada en dependencias
	 * 
	 * @param array $cacheData Datos del caché
	 * @param int $maxSize Tamaño máximo
	 * @return array Datos limpios
	 */
    public function cleanupByDependencies(array $cacheData, int $maxSize): array
    {
        // Mapear dependencias entre transients
        $transientDependencies = $this->getTransientDependencies();

        // Analizar dependencias entre elementos y construir grafo
        $dependencyGraph = $this->buildDependencyGraph($cacheData);

        // Calcular scores de dependencia para cada elemento usando ambos métodos
        $dependencyScores = [];
        foreach ($cacheData as $key => $value) {
            // Combinar scores de ambos métodos de cálculo
            $score1 = $this->calculateDependencyScore($key, $transientDependencies);
            $score2 = $this->calculateDependencyScores($dependencyGraph)[$key] ?? 0;

            // Usar el score más alto o combinarlos según la lógica deseada
            $dependencyScores[$key] = max($score1, $score2);
        }

        // Ordenar por score de dependencia (más dependientes primero)
        arsort($dependencyScores);

        // Mantener elementos con mayor dependencia
        $highDependencyKeys = array_slice(array_keys($dependencyScores), 0, $maxSize, true);
        $cleanedData = [];

        foreach ($highDependencyKeys as $key) {
            $cleanedData[$key] = $cacheData[$key];
        }

        $this->logger->info("Limpieza basada en dependencias completada", [
            'strategy' => 'dependency_based',
            'original_size' => count($cacheData),
            'cleaned_size' => count($cleanedData),
            'max_size' => $maxSize,
            'dependency_scores' => array_slice($dependencyScores, 0, 5, true) // Top 5 scores
        ]);

        return $cleanedData;
    }

		/**
	 * NUEVO: Obtiene el mapeo de dependencias entre transients
	 * 
	 * @return array Mapeo de dependencias
	 */
	private function getTransientDependencies(): array
	{
		// Dependencias conocidas entre transients
		return [
			'mia_sync_current_batch_offset' => [
				'depends_on' => ['mia_sync_batch_start_time'],
				'dependent_by' => ['mia_sync_current_batch_limit', 'mia_sync_current_batch_time'],
				'critical_level' => 'high'
			],
			'mia_sync_current_batch_limit' => [
				'depends_on' => ['mia_sync_current_batch_offset'],
				'dependent_by' => ['mia_sync_current_product_sku', 'mia_sync_current_product_name'],
				'critical_level' => 'high'
			],
			'mia_sync_current_product_sku' => [
				'depends_on' => ['mia_sync_current_batch_limit'],
				'dependent_by' => ['mia_sync_last_product', 'mia_sync_processed_skus'],
				'critical_level' => 'medium'
			],
			'mia_sync_current_product_name' => [
				'depends_on' => ['mia_sync_current_batch_limit'],
				'dependent_by' => ['mia_sync_last_product', 'mia_sync_processed_skus'],
				'critical_level' => 'medium'
			],
			'mia_sync_batch_start_time' => [
				'depends_on' => [],
				'dependent_by' => ['mia_sync_current_batch_offset', 'mia_sync_completed_batches'],
				'critical_level' => 'low'
			],
			'mia_sync_completed_batches' => [
				'depends_on' => ['mia_sync_batch_start_time'],
				'dependent_by' => [],
				'critical_level' => 'low'
			],
			'mia_sync_processed_skus' => [
				'depends_on' => ['mia_sync_current_product_sku'],
				'dependent_by' => [],
				'critical_level' => 'low'
			],
			'mia_sync_last_product' => [
				'depends_on' => ['mia_sync_current_product_sku'],
				'dependent_by' => [],
				'critical_level' => 'low'
			],
			'mia_sync_last_product_time' => [
				'depends_on' => ['mia_sync_last_product'],
				'dependent_by' => [],
				'critical_level' => 'low'
			],
			'mia_category_names_cache' => [
				'depends_on' => [],
				'dependent_by' => [],
				'critical_level' => 'low'
			],
			'mia_current_sync_operation_id' => [
				'depends_on' => [],
				'dependent_by' => ['mia_sync_current_batch_offset', 'mia_sync_current_batch_limit'],
				'critical_level' => 'high'
			]
		];
	}


		/**
	 * NUEVO: Calcula el score de dependencia de un transient
	 * 
	 * @param string $cacheKey Clave del transient
	 * @param array $dependencies Mapeo de dependencias
	 * @return float Score de dependencia
	 */
	private function calculateDependencyScore(string $cacheKey, array $dependencies): float
	{
		$dependency = $dependencies[$cacheKey] ?? [];
		if (empty($dependency)) {
			return 0;
		}
		
		$score = 0;
		
		// Score por nivel crítico
		$criticalScores = ['high' => 100, 'medium' => 60, 'low' => 20];
		$score += $criticalScores[$dependency['critical_level'] ?? 'low'] ?? 0;
		
		// Score por dependencias (transients de los que depende)
		$dependsOn = $dependency['depends_on'] ?? [];
		$score += count($dependsOn) * 30;
		
		// Score por dependientes (transients que dependen de este)
		$dependentBy = $dependency['dependent_by'] ?? [];
		$score += count($dependentBy) * 50;
		
		// Score por estado de sincronización activa
		if ($this->isCriticalTransient($cacheKey)) {
			$score += 200; // Bonus por ser crítico
		}
		
		return $score;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene la estrategia de limpieza para un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return string Estrategia de limpieza
	 */
	public function getCleanupStrategy(string $cacheKey): string
	{
		// REFACTORIZADO: Configuración específica de estrategias por tipo de transient
		$specificStrategies = [
			'mia_sync_batch_times' => 'time_based',
			'mia_sync_completed_batches' => 'priority_based',
			'mia_category_names_cache' => 'size_based',
			'mia_sync_processed_skus' => 'usage_based',
			'mia_current_sync_operation_id' => 'dependency_based',
			'mia_sync_current_batch_offset' => 'dependency_based',
			'mia_sync_current_batch_limit' => 'dependency_based',
			'mia_sync_current_product_sku' => 'usage_based',
			'mia_sync_current_product_name' => 'usage_based',
			'mia_sync_last_product' => 'usage_based',
			'mia_sync_last_product_time' => 'time_based',
			'mia_sync_batch_start_time' => 'dependency_based'
		];
		
		// REFACTORIZADO: Verificar estrategia específica primero
		if (isset($specificStrategies[$cacheKey])) {
			return $specificStrategies[$cacheKey];
		}
		
		// REFACTORIZADO: Fallback a configuración TTL personalizada
		$config = $this->getCustomTTLConfiguration($cacheKey);
		$strategy = $config['cleanup_strategy'] ?? 'recent_first';
		
		// Validar estrategia
		$validStrategies = [
			'priority_based',
			'time_based',
			'size_based',
			'usage_based',
			'dependency_based',
			'recent_first'
		];
		
		if (!in_array($strategy, $validStrategies)) {
			$strategy = 'recent_first';
		}
		
		return $strategy;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Verifica si los datos son de alta prioridad
	 * 
	 * @param string $key Clave del caché
	 * @param mixed $value Valor del caché
	 * @return bool True si es de alta prioridad
	 */
	private function isHighPriorityData(string $key, mixed $value): bool
	{
		// Claves críticas siempre son de alta prioridad
		$criticalKeys = [
			'mia_sync_current_batch_offset',
			'mia_sync_current_batch_limit',
			'mia_sync_current_batch_time',
			'mia_current_sync_operation_id'
		];
		
		if (in_array($key, $criticalKeys)) {
			return true;
		}
		
		// Verificar si contiene datos críticos
		if (is_string($value) && (
			str_contains($value, 'critical') ||
			str_contains($value, 'essential') ||
			str_contains($value, 'required')
		)) {
			return true;
		}
		
		return false;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Extrae timestamp de clave o valor
	 * 
	 * @param string $key Clave del caché
	 * @param mixed $value Valor del caché
	 * @return int Timestamp
	 */
	private function extractTimestamp(string $key, mixed $value): int
	{
		// Intentar extraer timestamp de la clave
		if (preg_match('/_(\d{10,13})_?$/', $key, $matches)) {
			$timestamp = (int) $matches[1];
			if ($timestamp > 1000000000) { // Después de 2001
				return $timestamp;
			}
		}
		
		// Intentar extraer timestamp del valor
		if (is_array($value) && isset($value['timestamp'])) {
			return (int) $value['timestamp'];
		}
		
		if (is_object($value) && isset($value->timestamp)) {
			return (int) $value->timestamp;
		}
		
		// Timestamp por defecto (actual)
		return time();
	}

	/**
	 *
	 * @param mixed $value Valor del transient
	 * @return int Tamaño estimado en bytes
	 */
	public function estimateTransientSize(mixed $value): int
	{
		if (is_string($value)) {
			return strlen($value);
		} elseif (is_array($value)) {
			$size = 0;
			foreach ($value as $k => $v) {
				$size += strlen($k) + $this->estimateTransientSize($v);
			}
			return $size;
		} elseif (is_object($value)) {
			return strlen(serialize($value));
		} elseif (is_numeric($value)) {
			return strlen((string) $value);
		}
		
		return 64; // Tamaño por defecto
	}


	/**
	 * MIGRADO DESDE Sync_Manager: Construye grafo de dependencias
	 * 
	 * @param array $cacheData Datos del caché
	 * @return array Grafo de dependencias
	 */
	private function buildDependencyGraph(array $cacheData): array
	{
		$graph = [];
		
		foreach ($cacheData as $key => $value) {
			$dependencies = $this->extractDependencies($key, $value);
			$graph[$key] = $dependencies;
		}
		
		return $graph;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Extrae dependencias de clave o valor
	 * 
	 * @param string $key Clave del caché
	 * @param mixed $value Valor del caché
	 * @return array Lista de dependencias
	 */
	private function extractDependencies(string $key, mixed $value): array
	{
		$dependencies = [];
		
		// Buscar referencias a otras claves en el valor
		if (is_string($value)) {
			preg_match_all('/mia_[a-z_]+/', $value, $matches);
			$dependencies = array_unique($matches[0]);
		} elseif (is_array($value)) {
			foreach ($value as $v) {
				$dependencies = array_merge($dependencies, $this->extractDependencies('', $v));
			}
		}
		
		return array_unique($dependencies);
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Calcula scores de dependencias
	 * 
	 * @param array $dependencyGraph Grafo de dependencias
	 * @return array Scores de dependencias
	 */
	private function calculateDependencyScores(array $dependencyGraph): array
	{
		$scores = [];
		
		foreach ($dependencyGraph as $key => $dependencies) {
			$score = count($dependencies); // Más dependencias = mayor score
			
			// Bonus por ser dependencia de otros
			foreach ($dependencyGraph as $otherKey => $otherDeps) {
				if (in_array($key, $otherDeps)) {
					$score += 2; // Bonus por ser referenciado
				}
			}
			
			$scores[$key] = $score;
		}
		
		return $scores;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Logging optimizado de resultados de limpieza
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param int $originalSize Tamaño original
	 * @param int $cleanedSize Tamaño después de limpieza
	 * @param int $maxSize Tamaño máximo permitido
	 * @param int $expiration Tiempo de expiración
	 */
	private function logCleanupResults(string $cacheKey, int $originalSize, int $cleanedSize, int $maxSize, int $expiration): void
	{
		//  Solo loggear si hay cambios significativos
		$reduction = $originalSize - $cleanedSize;
		$reduction_calc = $reduction > 0 ? ($reduction / $originalSize) * 100 : 0;
		$reductionPercentage = $this->safe_round($reduction_calc, 1);
		
		//  Nivel de log según la importancia del cambio
		if ($reductionPercentage > 50) {
            $this->logger->warning("Limpieza drástica de caché", [
                'cache_key' => $cacheKey,
                'original_size' => $originalSize,
                'cleaned_size' => $cleanedSize,
                'max_size' => $maxSize,
                'reduction_percentage' => $reductionPercentage,
                'expiration' => $expiration
            ]);
		}
    }

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene el último tiempo de limpieza para un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return int|null Timestamp del último cleanup o null si no hay historial
	 */
	private function getLastCleanupTime(string $cacheKey): ?int
	{
		$history_key = 'mia_cleanup_history_' . $cacheKey;
		$history = get_option($history_key, []);
		
		if (empty($history)) {
			return null;
		}
		
		// Obtener el timestamp más reciente
		$latest = end($history);
		return $latest['timestamp'] ?? null;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene el número total de limpiezas para un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return int Número total de limpiezas
	 */
	private function getCleanupCount(string $cacheKey): int
	{
		$history_key = 'mia_cleanup_history_' . $cacheKey;
		$history = get_option($history_key, []);
		
		return count($history);
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene el historial completo de limpiezas para un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return array Historial de limpiezas
	 */
	private function getCleanupHistory(string $cacheKey): array
	{
		$history_key = 'mia_cleanup_history_' . $cacheKey;
		$history = get_option($history_key, []);
		
		// Ordenar por timestamp (más reciente primero)
		usort($history, function($a, $b) {
			return $b['timestamp'] - $a['timestamp'];
		});
		
		return $history;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Calcula la frecuencia de limpieza basada en el historial
	 * 
	 * @param array $cleanupHistory Historial de limpiezas
	 * @return string Frecuencia de limpieza
	 */
	private function calculateCleanupFrequency(array $cleanupHistory): string
	{
		if (count($cleanupHistory) < 2) {
			return 'unknown';
		}
		
		// Calcular intervalos entre limpiezas
		$intervals = [];
		for ($i = 1; $i < count($cleanupHistory); $i++) {
			$current = $cleanupHistory[$i - 1]['timestamp'];
			$previous = $cleanupHistory[$i]['timestamp'];
			$intervals[] = $current - $previous;
		}
		
		// Calcular intervalo promedio en horas
		$averageInterval = array_sum($intervals) / count($intervals);
		$averageHours = $averageInterval / 3600;
		
		if ($averageHours < 1) {
			return 'very_frequent';
		} elseif ($averageHours < 6) {
			return 'frequent';
		} elseif ($averageHours < 24) {
			return 'daily';
		} elseif ($averageHours < 168) { // 7 días
			return 'weekly';
		} else {
			return 'infrequent';
		}
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Analiza la efectividad de la limpieza para un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $cacheMetrics Métricas del caché
	 * @return array|null Análisis de efectividad o null si no hay datos suficientes
	 */
	private function analyzeCleanupEffectiveness(string $cacheKey, array $cacheMetrics): ?array
	{
		$cleanupCount = $cacheMetrics['cleanup_count'] ?? 0;
		$cleanupFrequency = $cacheMetrics['cleanup_frequency'] ?? 'never';
		$usagePercentage = $cacheMetrics['usage_percentage'] ?? 0;
		
		if ($cleanupCount === 0) return null;
		
		return [
			'frequency' => $cleanupFrequency,
			'count' => $cleanupCount,
			'effectiveness_score' => $this->calculateCleanupEffectivenessScore($cleanupCount, $cleanupFrequency, $usagePercentage),
			'optimization_potential' => $this->getCleanupOptimizationPotential($cleanupFrequency, $usagePercentage)
		];
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Genera recomendaciones basadas en la efectividad
	 * 
	 * @param array $cleanupEffectiveness Análisis de efectividad
	 * @return array Recomendaciones de optimización
	 */
	private function generateCleanupRecommendations(array $cleanupEffectiveness): array
	{
		$recommendations = [];
		
		foreach ($cleanupEffectiveness as $cacheKey => $effectiveness) {
			$frequency = $effectiveness['frequency'] ?? 'never';
			$score = $effectiveness['effectiveness_score'] ?? 0;
			
			if ($frequency === 'never' && $score === 0) {
				$recommendations[] = [
					'type' => 'cleanup',
					'priority' => 'medium',
					'cache_key' => $cacheKey,
					'message' => "Transient $cacheKey nunca se ha limpiado - configurar limpieza automática",
					'action' => 'setup_auto_cleanup'
				];
			} elseif ($score < 50) {
				$recommendations[] = [
					'type' => 'cleanup',
					'priority' => 'medium',
					'cache_key' => $cacheKey,
					'message' => "Transient $cacheKey tiene baja efectividad de limpieza - optimizar estrategia",
					'action' => 'optimize_cleanup_strategy'
				];
			}
		}
		
		return $recommendations;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Prioriza las recomendaciones por importancia
	 * 
	 * @param array $recommendations Lista de recomendaciones
	 * @return array Recomendaciones priorizadas
	 */
	private function prioritizeRecommendations(array $recommendations): array
	{
		//  Ordenar por prioridad y tipo
		usort($recommendations, function($a, $b) {
			$priorityOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
			$typeOrder = ['memory' => 4, 'usage' => 3, 'growth' => 2, 'performance' => 1, 'cleanup' => 0];
			
			$priorityDiff = ($priorityOrder[$b['priority']] ?? 0) - ($priorityOrder[$a['priority']] ?? 0);
			if ($priorityDiff !== 0) return $priorityDiff;
			
			return ($typeOrder[$b['type']] ?? 0) - ($typeOrder[$a['type']] ?? 0);
		});
		
		return $recommendations;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Determina si se debe disparar una alerta crítica
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $cacheMetrics Métricas del caché
	 * @return bool True si se debe disparar alerta
	 */
	private function shouldTriggerCriticalAlert(string $cacheKey, array $cacheMetrics): bool
	{
		$usagePercentage = $cacheMetrics['usage_percentage'] ?? 0;
		$memoryMB = $cacheMetrics['memory_mb'] ?? 0;
		$growthRate = $cacheMetrics['growth_rate'] ?? 0;
		$criticalLevel = $cacheMetrics['critical_level'] ?? false;
		
		return $criticalLevel || $usagePercentage > 95 || $memoryMB > 75 || $growthRate > 200;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Crea una alerta crítica para un transient
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $cacheMetrics Métricas del caché
	 * @return array Alerta crítica
	 */
	private function createCriticalAlert(string $cacheKey, array $cacheMetrics): array
	{
		$usagePercentage = $cacheMetrics['usage_percentage'] ?? 0;
		$memoryMB = $cacheMetrics['memory_mb'] ?? 0;
		$growthRate = $cacheMetrics['growth_rate'] ?? 0;
		
		return [
			'cache_key' => $cacheKey,
			'severity' => 'critical',
			'timestamp' => time(),
			'metrics' => [
				'usage_percentage' => $usagePercentage,
				'memory_mb' => $memoryMB,
				'growth_rate' => $growthRate
			],
			'message' => "Transient $cacheKey en estado crítico: $usagePercentage% uso, {$memoryMB}MB memoria, $growthRate items/hora crecimiento",
			'immediate_actions' => $this->getImmediateActions($usagePercentage, $memoryMB, $growthRate)
		];
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene acciones inmediatas basadas en el estado del transient
	 * 
	 * @param float $usagePercentage Porcentaje de uso del caché
	 * @param float $memoryMB Tamaño de la memoria en MB
	 * @param float $growthRate Tasa de crecimiento de items/hora
	 * @return array Acciones inmediatas
	 */
	private function getImmediateActions(float $usagePercentage, float $memoryMB, float $growthRate): array
	{
		$actions = [];
		
		if ($usagePercentage > 95) {
			$actions[] = 'Reducir uso del caché';
		}
		
		if ($memoryMB > 75) {
			$actions[] = 'Reducir tamaño de caché';
		}
		
		if ($growthRate > 200) {
			$actions[] = 'Reducir tasa de crecimiento';
		}
		
		return $actions;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Calcula el score de efectividad de limpieza
	 * 
	 * @param int $cleanupCount Número de limpiezas
	 * @param string $cleanupFrequency Frecuencia de limpieza
	 * @param float $usagePercentage Porcentaje de uso del caché
	 * @return float Score de efectividad (0-100)
	 */
	private function calculateCleanupEffectivenessScore(int $cleanupCount, string $cleanupFrequency, float $usagePercentage): float
	{
		$frequencyScore = $this->getFrequencyScore($cleanupFrequency);
		$countScore = min(100, $cleanupCount * 10);
		$usageScore = max(0, 100 - $usagePercentage);
		
		$totalScore = ($frequencyScore * 0.4) + ($countScore * 0.3) + ($usageScore * 0.3);
		return $this->safe_round($totalScore, 2);
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene el potencial de optimización de limpieza
	 * 
	 * @param string $cleanupFrequency Frecuencia de limpieza
	 * @param float $usagePercentage Porcentaje de uso
	 * @return string Potencial de optimización
	 */
	private function getCleanupOptimizationPotential(string $cleanupFrequency, float $usagePercentage): string
	{
		if ($cleanupFrequency === 'never' && $usagePercentage > 50) return 'high';
		if ($cleanupFrequency === 'rarely' && $usagePercentage > 70) return 'medium';
		if ($cleanupFrequency === 'monthly' && $usagePercentage > 80) return 'low';
		return 'minimal';
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene factores críticos
	 * 
	 * @param float $usagePercentage Porcentaje de uso
	 * @param float $memoryMB Memoria en MB
	 * @param float $growthRate Tasa de crecimiento
	 * @return array Factores críticos
	 */
	private function getCriticalFactors(float $usagePercentage, float $memoryMB, float $growthRate): array
	{
		$factors = [];
		
		if ($usagePercentage > 90) $factors[] = 'high_usage';
		if ($memoryMB > 50) $factors[] = 'high_memory';
		if ($growthRate > 100) $factors[] = 'rapid_growth';
		
		return $factors;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene el score de rating
	 * 
	 * @param string $rating Rating de rendimiento
	 * @return float Score numérico
	 */
	private function getRatingScore(string $rating): float
	{
		$scores = [
			'excellent' => 100,
			'good' => 80,
			'fair' => 60,
			'poor' => 40,
			'critical' => 20
		];
		
		return $scores[$rating] ?? 50;
	}

	/**
	 * MIGRADO DESDE Sync_Manager: Obtiene el score de frecuencia
	 * 
	 * @param string $frequency Frecuencia de limpieza
	 * @return float Score numérico
	 */
	private function getFrequencyScore(string $frequency): float
	{
		$scores = [
			'daily' => 100,
			'weekly' => 80,
			'monthly' => 60,
			'rarely' => 30,
			'never' => 0
		];
		
		return $scores[$frequency] ?? 50;
	}

	/**
	 * OPTIMIZADO: Obtiene el tamaño máximo para muestras según memoria disponible
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método determina el tamaño óptimo de muestras
	 * basándose en el uso de memoria del sistema y configuración del entorno.
	 * 
	 * @return int Tamaño máximo para muestras
	 */
	public function getMaxSampleSize(): int
	{
		//  Tamaño base según entorno
		$baseSize = defined('WP_DEBUG') && constant('WP_DEBUG') ? 10 : 5;
		
		//  Ajustar según memoria disponible si MemoryManager está disponible
		if (class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
			try {
				$memoryStats = MemoryManager::getMemoryStats();
				$usagePercentage = $memoryStats['usage_percentage'] ?? 0;
				
				//  Ajustar tamaño según uso de memoria
				if ($usagePercentage > 80) {
					$baseSize = max(2, (int) ($baseSize * 0.3)); // Crítico
				} elseif ($usagePercentage > 70) {
					$baseSize = max(3, (int) ($baseSize * 0.5)); // Alto
				} elseif ($usagePercentage > 60) {
					$baseSize = max(5, (int) ($baseSize * 0.7)); // Moderado
				} elseif ($usagePercentage < 30 && ($memoryStats['available'] ?? 0) > 512) {
					$baseSize = min(20, (int) ($baseSize * 1.5)); // Abundante
				}
			} catch (Throwable $e) {
				// Fallback a valor base si falla la consulta de memoria
                $this->logger->warning('Error obteniendo estadísticas de memoria, usando tamaño base', [
                    'error' => $e->getMessage(),
                    'base_size' => $baseSize
                ]);
			}
		}
		
		return $baseSize;
	}

	/**
	 * OPTIMIZADO: Optimiza datos de muestra eliminando campos innecesarios
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método optimiza datos de muestra eliminando
	 * campos pesados y manteniendo solo la información esencial para el caché.
	 * 
	 * @param array $sampleData Datos de muestra a optimizar
	 * @return array Datos optimizados
	 */
	public function optimizeSampleData(array $sampleData): array
	{
		//  Campos a mantener (solo los esenciales)
		$essentialFields = [
			'Id', 'Codigo', 'Nombre', 'Precio', 'Stock', 'Categoria', 'Fabricante'
		];
		
		//  Campos a eliminar (muy pesados o innecesarios)
		$fieldsToRemove = [
			'Texto', 'Descripcion', 'Caracteristicas', 'Imagenes', 'Metadatos',
			'Historial', 'Logs', 'Temporales', 'Cache'
		];
		
		$optimizedData = [];
		
		foreach ($sampleData as $item) {
			$optimizedItem = [];
			
			//  Mantener solo campos esenciales
			foreach ($essentialFields as $field) {
				if (isset($item[$field])) {
					$optimizedItem[$field] = $item[$field];
				}
			}
			
			//  Eliminar campos pesados
			foreach ($fieldsToRemove as $field) {
				if (isset($optimizedItem[$field])) {
					unset($optimizedItem[$field]);
				}
			}
			
			//  Truncar campos de texto largos
			foreach (['Nombre', 'Categoria', 'Fabricante'] as $textField) {
				if (isset($optimizedItem[$textField]) && strlen($optimizedItem[$textField]) > 100) {
					$optimizedItem[$textField] = substr($optimizedItem[$textField], 0, 100) . '...';
				}
			}
			
			$optimizedData[] = $optimizedItem;
		}
		
		return $optimizedData;
	}

	/**
	 * OPTIMIZADO: Obtiene el tamaño máximo para historial de lotes según memoria disponible
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método determina el tamaño óptimo para el historial
	 * de lotes basándose en el uso de memoria del sistema y configuración del entorno.
	 * 
	 * @return int Tamaño máximo para historial de lotes
	 */
	public function getMaxBatchTimesSize(): int
	{
		//  Tamaño base según entorno
		$baseSize = defined('WP_DEBUG') && constant('WP_DEBUG') ? 100 : 50;
		
		//  Ajustar según memoria disponible si MemoryManager está disponible
		if (class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
			try {
				$memoryStats = MemoryManager::getMemoryStats();
				$usagePercentage = $memoryStats['usage_percentage'] ?? 0;
				
				//  Ajustar tamaño según uso de memoria
				if ($usagePercentage > 80) {
					$baseSize = max(20, (int) ($baseSize * 0.2)); // Crítico
				} elseif ($usagePercentage > 70) {
					$baseSize = max(30, (int) ($baseSize * 0.4)); // Alto
				} elseif ($usagePercentage > 60) {
					$baseSize = max(40, (int) ($baseSize * 0.6)); // Moderado
				} elseif ($usagePercentage < 30 && ($memoryStats['available'] ?? 0) > 512) {
					$baseSize = min(200, (int) ($baseSize * 1.5)); // Abundante
				}
			} catch (Throwable $e) {
				// Fallback a valor base si falla la consulta de memoria
                $this->logger->warning('Error obteniendo estadísticas de memoria para batch_times, usando tamaño base', [
                    'error' => $e->getMessage(),
                    'base_size' => $baseSize
                ]);
			}
		}
		
		return $baseSize;
	}

	/**
	 * OPTIMIZADO: Obtiene una muestra limitada de un array con logging inteligente
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método obtiene una muestra limitada de un array
	 * con logging inteligente para monitorear reducciones significativas de datos.
	 * 
	 * @param array $data Array de datos
	 * @param int $maxSize Tamaño máximo de la muestra
	 * @param string $context Contexto para logging
	 * @param bool $fromEnd Si es true, toma desde el final del array
	 * @return array Muestra limitada
	 */
	public function getLimitedSample(array $data, int $maxSize, string $context, bool $fromEnd = false): array
	{
		$originalSize = count($data);
		
		//  Aplicar límite solo si es necesario
		if ($originalSize <= $maxSize) {
			return $data;
		}
		
		//  Obtener muestra según parámetros
		if ($fromEnd) {
			$sample = array_slice($data, -$maxSize, $maxSize, true);
		} else {
			$sample = array_slice($data, 0, $maxSize);
		}
		
		// Logging removido - no afecta funcionalidad
		
		return $sample;
	}

	/**
	 * OPTIMIZADO: Obtiene el número máximo de placeholders según memoria disponible
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método determina el número máximo de placeholders
	 * que se pueden crear basándose en el uso de memoria del sistema.
	 * 
	 * @return int Número máximo de placeholders
	 */
	public function getMaxPlaceholdersCount(): int
	{
		//  Tamaño base según entorno
		$baseSize = defined('WP_DEBUG') && constant('WP_DEBUG') ? 1000 : 500;
		
		//  Ajustar según memoria disponible si MemoryManager está disponible
		if (class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
			try {
				$memoryStats = MemoryManager::getMemoryStats();
				$usagePercentage = $memoryStats['usage_percentage'] ?? 0;
				
				//  Ajustar tamaño según uso de memoria
				if ($usagePercentage > 80) {
					$baseSize = max(100, (int) ($baseSize * 0.2)); // Crítico
				} elseif ($usagePercentage > 70) {
					$baseSize = max(200, (int) ($baseSize * 0.4)); // Alto
				} elseif ($usagePercentage > 60) {
					$baseSize = max(300, (int) ($baseSize * 0.6)); // Moderado
				} elseif ($usagePercentage < 30 && ($memoryStats['available'] ?? 0) > 512) {
					$baseSize = min(2000, (int) ($baseSize * 1.5)); // Abundante
				}
			} catch (Throwable $e) {
				// Fallback a valor base si falla la consulta de memoria
                $this->logger->warning('Error obteniendo estadísticas de memoria para placeholders, usando tamaño base', [
                    'error' => $e->getMessage(),
                    'base_size' => $baseSize
                ]);
			}
		}
		
		return $baseSize;
	}

	/**
	 * OPTIMIZADO: Crea placeholders para consultas SQL con límites de memoria
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método crea placeholders para consultas SQL
	 * con límites de memoria inteligentes para evitar arrays demasiado grandes.
	 * 
	 * @param int $count Número de placeholders a crear
	 * @param mixed $value Valor para cada placeholder
	 * @return array Array de placeholders
	 */
	public function createPlaceholders(int $count, mixed $value): array
	{
		//  Límite máximo para evitar arrays demasiado grandes
		$maxPlaceholders = $this->getMaxPlaceholdersCount();
		
		if ($count > $maxPlaceholders) {
            $this->logger->warning("Número de placeholders limitado por memoria", [
                'requested_count' => $count,
                'max_allowed' => $maxPlaceholders,
                'reduction_percentage' => $count > 0 ? $this->safe_round((($count - $maxPlaceholders) / $count) * 100, 1) : 0.0
            ]);
			$count = $maxPlaceholders;
		}
		
		//  Crear placeholders de forma eficiente
		$placeholders = [];
		for ($i = 0; $i < $count; $i++) {
			$placeholders[] = $value;
		}
		
		return $placeholders;
	}

	/**
	 * OPTIMIZADO: Obtiene el tamaño máximo de array según memoria disponible
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método determina el tamaño máximo de array
	 * que se puede procesar basándose en el uso de memoria del sistema.
	 * 
	 * @return int Tamaño máximo de array
	 */
	public function getMaxArraySize(): int
	{
		//  Tamaño base según entorno
		$baseSize = defined('WP_DEBUG') && constant('WP_DEBUG') ? 10000 : 5000;
		
		//  Ajustar según memoria disponible si MemoryManager está disponible
		if (class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
			try {
				$memoryStats = MemoryManager::getMemoryStats();
				$usagePercentage = $memoryStats['usage_percentage'] ?? 0;
				
				//  Ajustar tamaño según uso de memoria
				if ($usagePercentage > 80) {
					$baseSize = max(1000, (int) ($baseSize * 0.1)); // Crítico
				} elseif ($usagePercentage > 70) {
					$baseSize = max(2000, (int) ($baseSize * 0.3)); // Alto
				} elseif ($usagePercentage > 60) {
					$baseSize = max(3000, (int) ($baseSize * 0.5)); // Moderado
				} elseif ($usagePercentage < 30 && ($memoryStats['available'] ?? 0) > 1024) {
					$baseSize = min(50000, (int) ($baseSize * 2)); // Abundante
				}
			} catch (Throwable $e) {
				// Fallback a valor base si falla la consulta de memoria
                $this->logger->warning('Error obteniendo estadísticas de memoria para array size, usando tamaño base', [
                    'error' => $e->getMessage(),
                    'base_size' => $baseSize
                ]);
			}
		}
		
		return $baseSize;
	}

	/**
	 * OPTIMIZADO: Obtiene el tamaño de un array de forma segura con límites de memoria
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método obtiene el tamaño de un array de forma
	 * segura, aplicando límites de memoria para evitar problemas de rendimiento.
	 * 
	 * @param array $array Array a medir
	 * @return int Tamaño del array
	 */
	public function getArraySize(array $array): int
	{
		//  Verificar si el array es demasiado grande antes de contar
		$maxArraySize = $this->getMaxArraySize();
		
		//  Si el array es muy grande, estimar el tamaño
		if (count($array) > $maxArraySize) {
            $this->logger->warning("Array demasiado grande para contar, estimando tamaño", [
                'array_type' => gettype($array),
                'estimated_size' => $maxArraySize,
                'reason' => 'límite de memoria alcanzado'
            ]);
			return $maxArraySize;
		}
		
		return count($array);
	}

	/**
	 * OPTIMIZADO: Obtiene el tamaño máximo del caché según memoria disponible
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método determina el tamaño máximo del caché
	 * basándose en el tipo de caché y el uso de memoria del sistema.
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return int Tamaño máximo del caché
	 */
	public function getMaxCacheSize(string $cacheKey): int
	{
		//  Tamaños base según tipo de caché
		$baseSizes = [
			'category_names_cache' => ['dev' => 500, 'prod' => 200],
			'batch_times' => ['dev' => 100, 'prod' => 50],
			'completed_batches' => ['dev' => 200, 'prod' => 100],
			'default' => ['dev' => 1000, 'prod' => 500]
		];
		
		$cacheConfig = $baseSizes[$cacheKey] ?? $baseSizes['default'];
		$baseSize = defined('WP_DEBUG') && constant('WP_DEBUG') ? $cacheConfig['dev'] : $cacheConfig['prod'];
		
		//  Ajustar según memoria disponible si MemoryManager está disponible
		if (class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
			try {
				$memoryStats = MemoryManager::getMemoryStats();
				$usagePercentage = $memoryStats['usage_percentage'] ?? 0;
				
				//  Ajustar tamaño según uso de memoria
				if ($usagePercentage > 80) {
					$baseSize = max(10, (int) ($baseSize * 0.1)); // Crítico
				} elseif ($usagePercentage > 70) {
					$baseSize = max(20, (int) ($baseSize * 0.3)); // Alto
				} elseif ($usagePercentage > 60) {
					$baseSize = max(30, (int) ($baseSize * 0.5)); // Moderado
				} elseif ($usagePercentage < 30 && ($memoryStats['available'] ?? 0) > 1024) {
					$baseSize = min(5000, (int) ($baseSize * 2)); // Abundante
				}
			} catch (Throwable $e) {
				// Fallback a valor base si falla la consulta de memoria
                $this->logger->warning('Error obteniendo estadísticas de memoria para caché, usando tamaño base', [
                    'error' => $e->getMessage(),
                    'cache_key' => $cacheKey,
                    'base_size' => $baseSize
                ]);
			}
		}
		
		return $baseSize;
	}

	/**
	 * OPTIMIZADO: Limita el tamaño del caché según memoria disponible
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método limita el tamaño del caché
	 * manteniendo los elementos más recientes y aplicando límites de memoria.
	 * 
	 * @param array $cacheData Datos del caché
	 * @param string $cacheKey Clave del caché para logging
	 * @return array Datos del caché limitados
	 */
	public function limitCacheSize(array $cacheData, string $cacheKey): array
	{
		$maxCacheSize = $this->getMaxCacheSize($cacheKey);
		$originalSize = count($cacheData);
		
		//  Aplicar límite solo si es necesario
		if ($originalSize <= $maxCacheSize) {
			return $cacheData;
		}
		
		//  Limitar tamaño manteniendo los elementos más recientes
		return array_slice($cacheData, -$maxCacheSize, $maxCacheSize, true);
	}

	/**
	 * OPTIMIZADO: Obtiene el tiempo de expiración del caché según memoria disponible
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método obtiene el tiempo de expiración del caché
	 * con configuración personalizada, ajustes por memoria y políticas de retención.
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return int Tiempo de expiración en segundos
	 */
	public function getCacheExpiration(string $cacheKey): int
	{
		//  TTLs personalizados por tipo de transient con configuración por entorno
		$customTTLs = $this->getCustomTTLConfiguration($cacheKey);
		
		//  Obtener TTL base según configuración personalizada o valores por defecto
		$baseExpiration = $this->getBaseExpiration($cacheKey, $customTTLs);
		
		//  Ajustar según memoria disponible si MemoryManager está disponible
		$adjustedExpiration = $this->adjustExpirationByMemory($baseExpiration, $cacheKey);
		
		//  Aplicar límites mínimos y máximos según el tipo de transient
		return $this->applyExpirationLimits($adjustedExpiration, $cacheKey, $customTTLs);
	}



	/**
	 * OPTIMIZADO: Obtiene el tiempo de expiración base según configuración
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método determina el TTL base según
	 * el entorno y la configuración personalizada.
	 * 
	 * @param string $cacheKey Clave del caché
	 * @param array $customTTLs Configuración personalizada de TTL
	 * @return int Tiempo de expiración base en segundos
	 */
	private function getBaseExpiration(string $cacheKey, array $customTTLs): int
	{
		//  Determinar entorno
		$environment = $this->getEnvironment();
		
		//  Obtener TTL base según entorno
		$baseExpiration = $customTTLs[$environment] ?? $customTTLs['prod'];
		
		//  Aplicar filtros de WordPress si están disponibles
		if (function_exists('apply_filters')) {
			$baseExpiration = apply_filters('mia_cache_base_expiration', $baseExpiration, $cacheKey, $environment);
		}

		return $baseExpiration;
	}

	/**
	 * OPTIMIZADO: Ajusta la expiración según el uso de memoria
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método ajusta el TTL basándose
	 * en el uso de memoria del sistema.
	 * 
	 * @param int $baseExpiration Tiempo de expiración base
	 * @param string $cacheKey Clave del caché
	 * @return int Tiempo de expiración ajustado
	 */
	private function adjustExpirationByMemory(int $baseExpiration, string $cacheKey): int
	{
		//  Ajustar según memoria disponible si MemoryManager está disponible
		if (!class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
			return $baseExpiration;
		}
		
		try {
			$memoryStats = MemoryManager::getMemoryStats();
			$usagePercentage = $memoryStats['usage_percentage'] ?? 0;
			$originalExpiration = $baseExpiration;
			
			//  Ajustar TTL según uso de memoria
			if ($usagePercentage > 80) {
				$baseExpiration = max(300, (int) ($baseExpiration * 0.3)); // Crítico: mínimo 5 minutos
			} elseif ($usagePercentage > 70) {
				$baseExpiration = max(600, (int) ($baseExpiration * 0.5)); // Alto: mínimo 10 minutos
			} elseif ($usagePercentage > 60) {
				$baseExpiration = max(900, (int) ($baseExpiration * 0.7)); // Moderado: mínimo 15 minutos
			} elseif ($usagePercentage < 30 && ($memoryStats['available'] ?? 0) > 1024) {
				$baseExpiration = min(86400, (int) ($baseExpiration * 1.5)); // Abundante: máximo 24 horas
			}
			
			// MEJORADO: Logging detallado solo si hay cambio
			if ($baseExpiration !== $originalExpiration) {
				$this->logger->info("TTL ajustado por memoria", [
					'cache_key' => $cacheKey,
					'original_ttl' => $originalExpiration,
					'adjusted_ttl' => $baseExpiration,
					'memory_usage_percentage' => $usagePercentage,
					'memory_available_mb' => $memoryStats['available'] ?? 0,
					'memory_peak_mb' => $memoryStats['peak'] ?? 0,
					'adjustment_reason' => $this->getMemoryAdjustmentReason($usagePercentage),
					'adjustment_factor' => $originalExpiration > 0 ? round($baseExpiration / $originalExpiration, 2) : 1.0
				]);
			}
			
		} catch (Throwable $e) {
			// Fallback a valor base si falla la consulta de memoria
			$this->logger->warning('Error obteniendo estadísticas de memoria para TTL, usando valor base', [
				'error' => $e->getMessage(),
				'error_trace' => $e->getTraceAsString(),
				'cache_key' => $cacheKey,
				'base_expiration' => $baseExpiration
			]);
		}

		return $baseExpiration;
	}

	/**
	 * MEJORADO: Obtiene la razón del ajuste de memoria
	 * 
	 * @param float $usagePercentage Porcentaje de uso de memoria
	 * @return string Razón del ajuste
	 */
	private function getMemoryAdjustmentReason(float $usagePercentage): string
	{
		if ($usagePercentage > 80) {
			return 'Uso crítico de memoria (>80%) - TTL reducido drásticamente';
		} elseif ($usagePercentage > 70) {
			return 'Uso alto de memoria (>70%) - TTL reducido significativamente';
		} elseif ($usagePercentage > 60) {
			return 'Uso moderado de memoria (>60%) - TTL reducido moderadamente';
		} elseif ($usagePercentage < 30) {
			return 'Memoria abundante disponible (<30%) - TTL extendido';
		}
		
		return 'Uso normal de memoria - TTL sin ajuste';
	}

	/**
	 * OPTIMIZADO: Aplica límites mínimos y máximos a la expiración
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método aplica límites de TTL
	 * según la configuración personalizada.
	 * 
	 * @param int $adjustedExpiration Tiempo de expiración ajustado
	 * @param string $cacheKey Clave del caché
	 * @param array $customTTLs Configuración personalizada de TTL
	 * @return int Tiempo de expiración final
	 */
	private function applyExpirationLimits(int $adjustedExpiration, string $cacheKey, array $customTTLs): int
	{
		//  Obtener límites de la configuración
		$minExpiration = $customTTLs['min'] ?? 900; // 15 minutos por defecto
		$maxExpiration = $customTTLs['max'] ?? 43200; // 12 horas por defecto
		
		//  Aplicar límites
		return max($minExpiration, min($maxExpiration, $adjustedExpiration));
	}

	/**
	 * OPTIMIZADO: Determina el entorno actual del sistema
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método determina el entorno
	 * basándose en constantes y configuración de WordPress.
	 * 
	 * @return string Entorno actual (dev, staging, prod)
	 */
	private function getEnvironment(): string
	{
		//  Determinar entorno basándose en constantes
		if (defined('WP_DEBUG') && constant('WP_DEBUG')) {
			if (defined('WP_ENVIRONMENT_TYPE')) {
				return constant('WP_ENVIRONMENT_TYPE');
			}
			return 'dev';
		}
		
		//  Verificar si es staging
		if (defined('WP_ENVIRONMENT_TYPE') && constant('WP_ENVIRONMENT_TYPE') === 'staging') {
			return 'staging';
		}
		
		//  Por defecto, producción
		return 'prod';
	}

	/**
	 * IMPLEMENTACIÓN DE CacheInterface: Determina si un transient debe ser retenido
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método implementa la lógica de caché pura
	 * para determinar retención, sin lógica de dominio de sincronización.
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return bool True si debe ser retenido, false si debe ser limpiado
	 */
	public function shouldRetainTransient(string $cacheKey): bool
	{
		//  Obtener política de retención
		$policy = $this->getRetentionPolicy($cacheKey);
		
		// Transients críticos: mantener siempre
		if ($policy['keep_always']) {
			return true;
		}
		
		// Transients temporales: limpiar inmediatamente
		if ($policy['cleanup_immediate']) {
			return false;
		}
		
		// Transients por antigüedad: verificar edad
		if ($policy['cleanup_by_age']) {
			$age = $this->getTransientAge($cacheKey);
			if ($age > ($policy['max_age_hours'] * 3600)) {
				return false; // Demasiado antiguo
			}
		}
		
		// Transients por inactividad: verificar inactividad
		if ($policy['cleanup_by_inactivity']) {
			$lastAccess = $this->getLastTransientAccess($cacheKey);
			if ($lastAccess && (time() - $lastAccess) > ($policy['inactivity_threshold_hours'] * 3600)) {
				return false; // Demasiado inactivo
			}
		}
		
		return true; // Mantener por defecto
	}

	/**
	 * IMPLEMENTACIÓN DE CacheInterface: Obtiene la edad de un transient en segundos
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método obtiene la edad de un transient
	 * basándose en el timestamp de creación almacenado en WordPress options.
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return int Edad en segundos desde la creación
	 */
	public function getTransientAge(string $cacheKey): int
	{
		//  Obtener timestamp de creación desde WordPress options
		$creationTime = get_option('mia_transient_created_' . $cacheKey, 0);
		if ($creationTime === 0) {
			// Si no hay timestamp de creación, usar el tiempo actual
			$creationTime = time();
			update_option('mia_transient_created_' . $cacheKey, $creationTime, false);
		}
		
		return time() - $creationTime;
	}

	/**
	 * IMPLEMENTACIÓN DE CacheInterface: Obtiene el timestamp del último acceso a un transient
	 * 
	 * MIGRADO DESDE Sync_Manager: Este método obtiene el timestamp del último acceso
	 * desde las métricas de uso almacenadas en WordPress options.
	 * 
	 * @param string $cacheKey Clave del caché
	 * @return int|null Timestamp del último acceso o null si no hay registro
	 */
	public function getLastTransientAccess(string $cacheKey): ?int
	{
		//  Obtener métricas de uso desde WordPress options
		$usageMetrics = get_option('mia_transient_usage_metrics_' . $cacheKey, []);
		return $usageMetrics['last_access'] ?? null;
	}

	/**
	 * IMPLEMENTACIÓN DE CacheInterface: Limpia transients expirados según su TTL
	 * 
	 * NUEVO: Este método implementa la limpieza automática de transients
	 * que han excedido su tiempo de vida configurado.
	 * 
	 * @return int Número de transients limpiados
	 */
	public function cleanupExpiredTransients(): int
	{
		//  Implementar limpieza de transients expirados
		$cleanedCount = 0;
		
		// Obtener lista de transients del sistema
		$transients = $this->getSystemTransients();
		
		foreach ($transients as $transientKey) {
			if (str_starts_with($transientKey, 'mia_')) {
				$expiration = $this->getCacheExpiration($transientKey);
				$age = $this->getTransientAge($transientKey);
				
				if ($age > $expiration) {
					delete_transient($transientKey);
					$cleanedCount++;
					
					// Logging removido - no afecta funcionalidad
				}
			}
		}
		
		return $cleanedCount;
	}

	/**
	 * IMPLEMENTACIÓN DE CacheInterface: Limpia transients inactivos según su política de retención
	 * 
	 * NUEVO: Este método implementa la limpieza de transients inactivos
	 * basándose en las políticas de retención configuradas.
	 * 
	 * @return int Número de transients limpiados
	 */
	public function cleanupInactiveTransients(): int
	{
		//  Implementar limpieza de transients inactivos
		$cleanedCount = 0;
		
		// Obtener lista de transients del sistema
		$transients = $this->getSystemTransients();
		
		foreach ($transients as $transientKey) {
			if (str_starts_with($transientKey, 'mia_')) {
				// Verificar si debe ser retenido según su política
				if (!$this->shouldRetainTransient($transientKey)) {
					delete_transient($transientKey);
					$cleanedCount++;
					
					// Logging removido - no afecta funcionalidad
				}
			}
		}
		
		return $cleanedCount;
	}

	/**
	 * MÉTODO AUXILIAR: Obtiene lista de transients del sistema
	 * 
	 * @return array Lista de claves de transients
	 */
	private function getSystemTransients(): array
	{
		//  Obtener transients del sistema de manera eficiente
		global $wpdb;
		
		$transients = [];
		$results = $wpdb->get_results(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '_transient_mia_%'"
		);
		
		foreach ($results as $result) {
			$transients[] = str_replace('_transient_', '', $result->option_name);
		}
		
		return $transients;
	}

	/**
	 * ✅ NUEVO: Registra el acceso a un transient para tracking de uso y LRU.
	 * 
	 * Este método actualiza las métricas de uso cada vez que se accede a un transient,
	 * permitiendo implementar evicción LRU (Least Recently Used) basada en uso real.
	 * 
	 * @param   string  $cacheKey   Clave del caché
	 * @return  void
	 * @since   1.0.0
	 * @see     CacheManager::updateTransientUsageMetrics() Para actualización de métricas
	 * @see     CacheManager::getLastTransientAccess() Para obtener último acceso
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
	 * ✅ NUEVO: Actualiza métricas de uso de un transient.
	 * 
	 * Calcula y almacena métricas de uso basadas en el historial de accesos,
	 * incluyendo frecuencia de acceso, score de uso y último acceso.
	 * 
	 * @param   string  $cacheKey       Clave del caché
	 * @param   array   $accessHistory  Historial de accesos
	 * @return  void
	 * @since   1.0.0
	 * @see     CacheManager::recordTransientAccess() Para registro de accesos
	 * @see     CacheManager::calculateAccessFrequency() Para cálculo de frecuencia
	 * @see     CacheManager::calculateUsageScore() Para cálculo de score
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
			return ($currentTime - $access['timestamp']) <= 3600; // Última hora
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
	 * ✅ NUEVO: Calcula la frecuencia de acceso de un transient.
	 * 
	 * @param   array   $accessHistory  Historial de accesos
	 * @return  string  Frecuencia de acceso ('very_high', 'high', 'medium', 'low', 'very_low', 'never')
	 * @since   1.0.0
	 */
	private function calculateAccessFrequency(array $accessHistory): string
	{
		if (empty($accessHistory)) {
			return 'never';
		}
		
		$currentTime = time();
		$recentAccesses = array_filter($accessHistory, function($access) use ($currentTime) {
			return ($currentTime - $access['timestamp']) <= 3600; // Última hora
		});
		$recentCount = count($recentAccesses);
		
		if ($recentCount >= 50) {
			return 'very_high';
		} elseif ($recentCount >= 20) {
			return 'high';
		} elseif ($recentCount >= 5) {
			return 'medium';
		} elseif ($recentCount >= 1) {
			return 'low';
		} else {
			return 'very_low';
		}
	}

	/**
	 * ✅ NUEVO: Calcula el score de uso de un transient.
	 * 
	 * @param   array   $accessHistory  Historial de accesos
	 * @return  float   Score de uso (0-100)
	 * @since   1.0.0
	 */
	private function calculateUsageScore(array $accessHistory): float
	{
		if (empty($accessHistory)) {
			return 0.0;
		}
		
		$currentTime = time();
		$totalAccesses = count($accessHistory);
		$recentAccesses = array_filter($accessHistory, function($access) use ($currentTime) {
			return ($currentTime - $access['timestamp']) <= 3600; // Última hora
		});
		$dailyAccesses = array_filter($accessHistory, function($access) use ($currentTime) {
			return ($currentTime - $access['timestamp']) <= 86400; // Último día
		});
		
		// Score basado en: 40% accesos recientes, 30% accesos diarios, 30% total
		$recentScore = min(40, (count($recentAccesses) / 50) * 40);
		$dailyScore = min(30, (count($dailyAccesses) / 100) * 30);
		$totalScore = min(30, ($totalAccesses / 200) * 30);
		
		return round($recentScore + $dailyScore + $totalScore, 2);
	}

	/**
	 * ✅ NUEVO: Obtiene el límite de tamaño global de caché en MB.
	 * 
	 * @return  int     Límite en MB (default: 500MB)
	 * @since   1.0.0
	 */
	public function getGlobalCacheSizeLimit(): int
	{
		$defaultLimit = 500; // 500MB por defecto
		$configuredLimit = get_option('mia_cache_max_size_mb', $defaultLimit);
		
		// Validar rango (50MB - 5000MB)
		if ($configuredLimit < 50 || $configuredLimit > 5000) {
			return $defaultLimit;
		}
		
		// Ajustar según memoria disponible si MemoryManager está disponible
		if (class_exists('\\MiIntegracionApi\\Core\\MemoryManager')) {
			try {
				$memoryStats = MemoryManager::getMemoryStats();
				$availableMB = $memoryStats['available'] ?? 0;
				$usagePercentage = $memoryStats['usage_percentage'] ?? 0;
				
				// Si hay poca memoria disponible, reducir el límite
				if ($availableMB < 256) {
					$configuredLimit = max(50, (int)($configuredLimit * 0.5));
				} elseif ($usagePercentage > 80) {
					$configuredLimit = max(100, (int)($configuredLimit * 0.7));
				} elseif ($availableMB > 2048 && $usagePercentage < 30) {
					$configuredLimit = min(2000, (int)($configuredLimit * 1.5));
				}
			} catch (Throwable $e) {
				$this->logger->warning('Error obteniendo estadísticas de memoria para límite global, usando valor configurado', [
					'error' => $e->getMessage(),
					'configured_limit' => $configuredLimit
				]);
			}
		}
		
		return $configuredLimit;
	}

	/**
	 * ✅ NUEVO: Calcula el tamaño total actual de la caché en MB.
	 * 
	 * @return  float   Tamaño total en MB
	 * @since   1.0.0
	 */
	public function getTotalCacheSize(): float
	{
		global $wpdb;
		
		$sql = $wpdb->prepare(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} 
			WHERE option_name LIKE %s 
			AND option_name NOT LIKE %s",
			'_transient_' . $this->cache_prefix . '%',
			'_transient_timeout_%'
		);
		
		$sizeBytes = (int) $wpdb->get_var($sql);
		return round($sizeBytes / (1024 * 1024), 2);
	}

	/**
	 * ✅ NUEVO: Obtiene el TTL configurado para un endpoint específico.
	 * 
	 * Lee la configuración de TTL por endpoint desde las opciones de WordPress
	 * y devuelve el TTL configurado, o el TTL por defecto si no está configurado.
	 * 
	 * @param   string  $endpoint    Nombre del endpoint (ej: 'GetArticulosWS')
	 * @return  int     TTL en segundos (0 si el endpoint está deshabilitado)
	 * @since   1.0.0
	 * 
	 * @see     mi_integracion_api_cache_config Opción de WordPress que almacena la configuración
	 * 
	 * @example
	 * ```php
	 * $cacheManager = CacheManager::get_instance();
	 * $ttl = $cacheManager->getEndpointTTL('GetArticulosWS');
	 * // Retorna: 3600 si está configurado, o default_ttl si no
	 * // Retorna: 0 si el endpoint está deshabilitado
	 * ```
	 */
	public function getEndpointTTL(string $endpoint): int
	{
		// Obtener configuración de TTL por endpoint
		$cache_config = get_option('mi_integracion_api_cache_config', []);
		
		// Verificar si el endpoint está configurado
		if (isset($cache_config[$endpoint])) {
			$endpoint_config = $cache_config[$endpoint];
			
			// Verificar si está habilitado
			if (isset($endpoint_config['enabled']) && $endpoint_config['enabled'] == 1) {
				// Verificar si tiene TTL configurado
				if (isset($endpoint_config['ttl']) && is_numeric($endpoint_config['ttl'])) {
					$ttl = (int) $endpoint_config['ttl'];
					
					// Validar rango (mínimo 60 segundos, máximo 86400 segundos = 24 horas)
					$ttl = max(60, min(86400, $ttl));
					
					if ($this->logger instanceof \MiIntegracionApi\Helpers\Logger) {
						$this->logger->debug('TTL por endpoint obtenido', [
							'endpoint' => $endpoint,
							'ttl_seconds' => $ttl,
							'ttl_hours' => round($ttl / 3600, 2),
							'source' => 'endpoint_config'
						]);
					}
					
					return $ttl;
				}
			} else {
				// Endpoint deshabilitado en configuración
				if ($this->logger instanceof \MiIntegracionApi\Helpers\Logger) {
					$this->logger->debug('Endpoint deshabilitado en configuración de caché', [
						'endpoint' => $endpoint
					]);
				}
				return 0; // Retornar 0 indica que no debe cachearse
			}
		}
		
		// No hay configuración específica, usar TTL por defecto
		$default_ttl = $this->default_ttl;
		
		if ($this->logger instanceof \MiIntegracionApi\Helpers\Logger) {
			$this->logger->debug('Usando TTL por defecto para endpoint', [
				'endpoint' => $endpoint,
				'ttl_seconds' => $default_ttl,
				'ttl_hours' => round($default_ttl / 3600, 2),
				'source' => 'default'
			]);
		}
		
		return $default_ttl;
	}

	/**
	 * ✅ NUEVO: Verifica si se alcanzó el límite global y evicta elementos si es necesario.
	 * 
	 * Implementa evicción LRU automática cuando el tamaño total de caché excede el límite configurado.
	 * 
	 * @param   string|null $newCacheKey  Clave del nuevo elemento que se está almacenando (opcional)
	 * @return  void
	 * @since   1.0.0
	 * @see     CacheManager::getGlobalCacheSizeLimit() Para obtener el límite
	 * @see     CacheManager::getTotalCacheSize() Para obtener el tamaño actual
	 * @see     CacheManager::evictLRU() Para la evicción LRU
	 */
	private function checkAndEvictIfNeeded(?string $newCacheKey = null): void
	{
		$currentSize = $this->getTotalCacheSize();
		$maxSize = $this->getGlobalCacheSizeLimit();
		
		// Si no se alcanzó el límite, no hacer nada
		if ($currentSize < $maxSize) {
			return;
		}
		
		// Calcular cuánto espacio liberar (evictar hasta llegar al 80% del límite)
		$targetSize = $maxSize * 0.8;
		$sizeToFree = $currentSize - $targetSize;
		
		if ($sizeToFree > 0) {
			$this->logger->info('Límite global de caché alcanzado, iniciando evicción LRU', [
				'current_size_mb' => $currentSize,
				'max_size_mb' => $maxSize,
				'size_to_free_mb' => $sizeToFree,
				'target_size_mb' => $targetSize,
				'new_cache_key' => $newCacheKey
			]);
			
			$this->evictLRU($sizeToFree);
		}
	}

	/**
	 * ✅ NUEVO: Evicta elementos de caché usando estrategia LRU (Least Recently Used).
	 * 
	 * Elimina los elementos menos usados hasta liberar el espacio requerido, basándose
	 * en las métricas de uso y último acceso.
	 * 
	 * @param   float   $sizeToFreeMB    Tamaño a liberar en MB
	 * @return  array   Resultado de la evicción con estadísticas
	 * @since   1.0.0
	 * @see     CacheManager::getAllCacheKeys() Para obtener todas las claves
	 * @see     CacheManager::getLastTransientAccess() Para obtener último acceso
	 * @see     CacheManager::estimateTransientSize() Para estimar tamaño
	 */
	private function evictLRU(float $sizeToFreeMB): array
	{
		global $wpdb;
		
		$result = [
			'evicted_count' => 0,
			'space_freed_mb' => 0.0,
			'errors' => []
		];
		
		try {
			// ✅ MEJORADO: Obtener candidatos de hot cache (transients)
			$sql = $wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) as size_bytes 
				FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND option_name NOT LIKE %s
				ORDER BY option_name",
				'_transient_' . $this->cache_prefix . '%',
				'_transient_timeout_%'
			);
			
			$transients = $wpdb->get_results($sql, ARRAY_A);
			
			// ✅ NUEVO: Obtener candidatos de cold cache (archivos)
			$coldCandidates = [];
			if ($this->cache_dir !== null && is_dir($this->cache_dir . '/cold')) {
				$coldDir = $this->cache_dir . '/cold';
				$files = glob($coldDir . '/*.cache');
				
				foreach ($files as $file) {
					// Extraer cache_key del nombre del archivo
					$basename = basename($file, '.cache');
					// El formato es: {safeKey}_{hash}
					// Necesitamos obtener el cache_key original desde metadata
					$fileSize = filesize($file);
					$sizeMB = round($fileSize / (1024 * 1024), 2);
					
					// Buscar metadata por patrón (no perfecto pero funcional)
					// En una implementación real, podríamos mantener un índice
					$coldCandidates[] = [
						'file' => $file,
						'size_mb' => $sizeMB,
						'is_cold' => true
					];
				}
			}
			
			if (empty($transients) && empty($coldCandidates)) {
				return $result;
			}
			
			// Construir lista de candidatos a evicción con métricas de uso
			$candidates = [];
			
			// Procesar hot cache (transients)
			foreach ($transients as $transient) {
				$cacheKey = str_replace('_transient_', '', $transient['option_name']);
				$sizeMB = round($transient['size_bytes'] / (1024 * 1024), 2);
				
				// Obtener métricas de uso
				$usageMetrics = get_option('mia_transient_usage_metrics_' . $cacheKey, []);
				$lastAccess = $usageMetrics['last_access'] ?? 0;
				$usageScore = $usageMetrics['usage_score'] ?? 0.0;
				$accessFrequency = $usageMetrics['access_frequency'] ?? 'never';
				
				// Calcular score de evicción (menor score = más probable de evictar)
				// Factores: último acceso (más antiguo = menor score), uso (menor uso = menor score)
				$ageInHours = $lastAccess > 0 ? (time() - $lastAccess) / 3600 : 999;
				$frequencyScore = $this->convertFrequencyToScore($accessFrequency);
				
				// Score de evicción: inverso del uso (menor uso = mayor prioridad de evicción)
				// ✅ MEJORADO: Cold cache tiene prioridad de evicción más alta
				$evictionScore = ($ageInHours * 0.6) + ((100 - $usageScore) * 0.3) + ((100 - $frequencyScore) * 0.1);
				
				$candidates[] = [
					'cache_key' => $cacheKey,
					'size_mb' => $sizeMB,
					'last_access' => $lastAccess,
					'usage_score' => $usageScore,
					'eviction_score' => $evictionScore,
					'is_cold' => false
				];
			}
			
			// ✅ NUEVO: Procesar cold cache (archivos) - tienen mayor prioridad de evicción
			foreach ($coldCandidates as $coldCandidate) {
				// Intentar obtener cache_key desde metadata
				// Buscar en todas las opciones que empiecen con mia_cold_cache_meta_
				$metaOptions = $wpdb->get_results(
					"SELECT option_name, option_value FROM {$wpdb->options} 
					WHERE option_name LIKE 'mia_cold_cache_meta_%'",
					ARRAY_A
				);
				
				foreach ($metaOptions as $metaOption) {
					$meta = maybe_unserialize($metaOption['option_value']);
					if (is_array($meta) && isset($meta['cache_key'])) {
						$cacheKey = $meta['cache_key'];
						$expectedFile = $this->getColdCacheFilePath($cacheKey);
						
						if ($expectedFile === $coldCandidate['file']) {
							// Encontrado, obtener métricas
							$usageMetrics = get_option('mia_transient_usage_metrics_' . $cacheKey, []);
							$lastAccess = $usageMetrics['last_access'] ?? ($meta['created_at'] ?? 0);
							$usageScore = $usageMetrics['usage_score'] ?? 0.0;
							$accessFrequency = $usageMetrics['access_frequency'] ?? 'never';
							
							$ageInHours = $lastAccess > 0 ? (time() - $lastAccess) / 3600 : 999;
							$frequencyScore = $this->convertFrequencyToScore($accessFrequency);
							
							// ✅ Cold cache tiene prioridad de evicción más alta (añadir bonus)
							$evictionScore = ($ageInHours * 0.6) + ((100 - $usageScore) * 0.3) + ((100 - $frequencyScore) * 0.1) + 50; // Bonus para cold
							
							$candidates[] = [
								'cache_key' => $cacheKey,
								'size_mb' => $coldCandidate['size_mb'],
								'last_access' => $lastAccess,
								'usage_score' => $usageScore,
								'eviction_score' => $evictionScore,
								'is_cold' => true,
								'file' => $coldCandidate['file']
							];
							break;
						}
					}
				}
			}
			
			// Ordenar por score de evicción (mayor score = evictar primero)
			usort($candidates, function($a, $b) {
				return $b['eviction_score'] <=> $a['eviction_score'];
			});
			
			// Evictar hasta liberar el espacio requerido
			$freedMB = 0.0;
			$evictedKeys = [];
			
			foreach ($candidates as $candidate) {
				if ($freedMB >= $sizeToFreeMB) {
					break;
				}
				
			// Verificar si es crítico (no evictar)
			if ($this->isHighPriorityData($candidate['cache_key'], null)) {
				continue;
			}
			
			// ✅ MEJORADO: Evictar según el tipo de caché (hot o cold)
			$deleted = false;
			
			if (isset($candidate['is_cold']) && $candidate['is_cold']) {
				// Evictar de cold cache
				$deleted = $this->removeFromColdCache($candidate['cache_key']);
			} else {
				// Evictar de hot cache (transient)
				$deleted = delete_transient($candidate['cache_key']);
			}
			
			if ($deleted) {
				$freedMB += $candidate['size_mb'];
				$evictedKeys[] = $candidate['cache_key'];
				$result['evicted_count']++;
				
				// Limpiar métricas asociadas
				delete_option('mia_transient_access_history_' . $candidate['cache_key']);
				delete_option('mia_transient_usage_metrics_' . $candidate['cache_key']);
				delete_option('mia_transient_created_' . $candidate['cache_key']);
				delete_option('mia_cold_cache_meta_' . $candidate['cache_key']);
			}
			}
			
			$result['space_freed_mb'] = round($freedMB, 2);
			
			$this->logger->info('Evicción LRU completada', [
				'evicted_count' => $result['evicted_count'],
				'space_freed_mb' => $result['space_freed_mb'],
				'size_to_free_mb' => $sizeToFreeMB,
				'evicted_keys' => array_slice($evictedKeys, 0, 10) // Log solo primeros 10
			]);
			
		} catch (Exception $e) {
			$result['errors'][] = 'Error durante evicción LRU: ' . $e->getMessage();
			$this->logger->error('Error durante evicción LRU', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}
		
		return $result;
	}

	/**
	 * ✅ NUEVO: Convierte frecuencia de acceso a score numérico.
	 * 
	 * @param   string  $frequency   Frecuencia de acceso
	 * @return  float   Score numérico (0-100)
	 * @since   1.0.0
	 */
	private function convertFrequencyToScore(string $frequency): float
	{
		$scores = [
			'very_high' => 100,
			'high' => 75,
			'medium' => 50,
			'low' => 25,
			'very_low' => 10,
			'never' => 0
		];
		
		return $scores[$frequency] ?? 0.0;
	}

	/**
	 * ✅ NUEVO: Obtiene estadísticas del sistema de caché de dos niveles (hot/cold).
	 * 
	 * Retorna información detallada sobre el hot cache (transients) y el cold cache (archivos comprimidos),
	 * incluyendo conteos y tamaños en MB.
	 * 
	 * @return  array   Array con las siguientes claves:
	 *                  - 'hot_cache': ['count' => int, 'size_mb' => float]
	 *                  - 'cold_cache': ['count' => int, 'size_mb' => float]
	 *                  - 'total': ['count' => int, 'size_mb' => float]
	 * @since   1.0.0
	 * 
	 * @global  wpdb    $wpdb    Instancia de la base de datos de WordPress.
	 */
	public function getTwoTierCacheStats(): array
	{
		global $wpdb;

		$result = [
			'hot_cache' => ['count' => 0, 'size_mb' => 0.0],
			'cold_cache' => ['count' => 0, 'size_mb' => 0.0],
			'total' => ['count' => 0, 'size_mb' => 0.0]
		];

		try {
			// ✅ Obtener estadísticas de hot cache (transients)
			$sql = $wpdb->prepare(
				"SELECT COUNT(*) as count, SUM(LENGTH(option_value)) as size_bytes 
				FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND option_name NOT LIKE %s",
				'_transient_' . $this->cache_prefix . '%',
				'_transient_timeout_%'
			);

			$hotStats = $wpdb->get_row($sql, ARRAY_A);
			if ($hotStats) {
				$result['hot_cache']['count'] = (int) ($hotStats['count'] ?? 0);
				$sizeBytes = (int) ($hotStats['size_bytes'] ?? 0);
				$result['hot_cache']['size_mb'] = $this->safe_round($sizeBytes / (1024 * 1024), 2);
			}

			// ✅ Obtener estadísticas de cold cache (archivos comprimidos)
			if ($this->cache_dir !== null && is_dir($this->cache_dir . '/cold')) {
				$coldDir = $this->cache_dir . '/cold';
				$files = glob($coldDir . '/*.cache');

				if ($files !== false) {
					$result['cold_cache']['count'] = count($files);
					$totalSizeBytes = 0;

					foreach ($files as $file) {
						if (is_file($file)) {
							$fileSize = filesize($file);
							if ($fileSize !== false) {
								$totalSizeBytes += $fileSize;
							}
						}
					}

					$result['cold_cache']['size_mb'] = $this->safe_round($totalSizeBytes / (1024 * 1024), 2);
				}
			}

			// ✅ Calcular totales
			$result['total']['count'] = $result['hot_cache']['count'] + $result['cold_cache']['count'];
			$result['total']['size_mb'] = $this->safe_round(
				$result['hot_cache']['size_mb'] + $result['cold_cache']['size_mb'],
				2
			);

		} catch (Exception $e) {
			$this->logger->error('Error obteniendo estadísticas de caché de dos niveles', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}

		return $result;
	}

	/**
	 * ✅ NUEVO: Obtiene la ruta del archivo de cold cache para una clave dada.
	 * 
	 * Construye la ruta del archivo de cold cache basándose en el cache_key,
	 * sanitizando y hasheando la clave para evitar problemas con caracteres especiales.
	 * 
	 * @param   string  $cacheKey    Clave del caché
	 * @return  string  Ruta completa del archivo de cold cache
	 * @since   1.0.0
	 */
	private function getColdCacheFilePath(string $cacheKey): string
	{
		if ($this->cache_dir === null) {
			return '';
		}

		// Sanitizar la clave para el nombre del archivo
		$safeKey = sanitize_file_name($cacheKey);
		
		// Crear hash para evitar nombres de archivo demasiado largos
		$hash = substr(md5($cacheKey), 0, 8);
		
		// Construir nombre del archivo: {safeKey}_{hash}.cache
		$filename = $safeKey . '_' . $hash . '.cache';
		
		// Retornar ruta completa
		return $this->cache_dir . '/cold/' . $filename;
	}

	/**
	 * ✅ NUEVO: Obtiene un valor del cold cache (archivo comprimido).
	 * 
	 * @param   string  $cacheKey    Clave del caché
	 * @return  mixed   Valor almacenado o false si no existe o está expirado
	 * @since   1.0.0
	 */
	private function getFromColdCache(string $cacheKey): mixed
	{
		if ($this->cache_dir === null) {
			return false;
		}

		$filePath = $this->getColdCacheFilePath($cacheKey);
		
		if (!file_exists($filePath) || !is_readable($filePath)) {
			return false;
		}

		try {
			// Verificar metadata para ver si está expirado
			$metaOptionName = 'mia_cold_cache_meta_' . $cacheKey;
			$meta = get_option($metaOptionName);
			
			if ($meta && is_array($meta)) {
				if (isset($meta['expires_at']) && $meta['expires_at'] < time()) {
					// Expirado, eliminar
					$this->removeFromColdCache($cacheKey);
					return false;
				}
			}

			// Leer archivo comprimido
			$compressedData = file_get_contents($filePath);
			if ($compressedData === false) {
				return false;
			}

			// Descomprimir
			$uncompressedData = gzuncompress($compressedData);
			if ($uncompressedData === false) {
				return false;
			}

			// Deserializar
			$value = maybe_unserialize($uncompressedData);
			
			return $value !== false ? $value : false;
		} catch (Exception $e) {
			$this->logger->error('Error obteniendo de cold cache', [
				'cache_key' => $cacheKey,
				'error' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * ✅ NUEVO: Almacena un valor en cold cache (archivo comprimido).
	 * 
	 * @param   string  $cacheKey    Clave del caché
	 * @param   mixed   $value       Valor a almacenar
	 * @param   int     $ttl         Tiempo de vida en segundos
	 * @return  bool    True si se almacenó correctamente
	 * @since   1.0.0
	 */
	private function storeInColdCache(string $cacheKey, mixed $value, int $ttl): bool
	{
		if ($this->cache_dir === null) {
			return false;
		}

		$coldDir = $this->cache_dir . '/cold';
		
		// Crear directorio si no existe
		if (!is_dir($coldDir)) {
			if (!wp_mkdir_p($coldDir)) {
				$this->logger->error('No se pudo crear directorio de cold cache', [
					'dir' => $coldDir
				]);
				return false;
			}
			
			// Crear archivos de seguridad
			$securityFiles = [
				'.htaccess' => 'deny from all',
				'index.php' => '<?php // Silence is golden'
			];
			
			foreach ($securityFiles as $filename => $content) {
				$filePath = $coldDir . '/' . $filename;
				if (!file_exists($filePath)) {
					file_put_contents($filePath, $content);
				}
			}
		}

		$filePath = $this->getColdCacheFilePath($cacheKey);
		
		try {
			// Serializar y comprimir
			$serializedData = serialize($value);
			$compressedData = gzcompress($serializedData, 6); // Nivel de compresión 6 (balanceado)
			
			if ($compressedData === false) {
				return false;
			}

			// Escribir archivo
			$result = file_put_contents($filePath, $compressedData, LOCK_EX);
			
			if ($result === false) {
				return false;
			}

			// Guardar metadata
			$expiresAt = $ttl > 0 ? time() + $ttl : 0;
			$meta = [
				'cache_key' => $cacheKey,
				'created_at' => time(),
				'expires_at' => $expiresAt,
				'ttl' => $ttl,
				'size_bytes' => strlen($compressedData)
			];
			
			update_option('mia_cold_cache_meta_' . $cacheKey, $meta, false);
			
			return true;
		} catch (Exception $e) {
			$this->logger->error('Error almacenando en cold cache', [
				'cache_key' => $cacheKey,
				'error' => $e->getMessage()
			]);
			return false;
		}
	}

	/**
	 * ✅ NUEVO: Elimina un valor del cold cache.
	 * 
	 * @param   string  $cacheKey    Clave del caché
	 * @return  bool    True si se eliminó correctamente
	 * @since   1.0.0
	 */
	private function removeFromColdCache(string $cacheKey): bool
	{
		if ($this->cache_dir === null) {
			return false;
		}

		$filePath = $this->getColdCacheFilePath($cacheKey);
		
		$fileDeleted = false;
		if (file_exists($filePath)) {
			$fileDeleted = @unlink($filePath);
		}

		// Eliminar metadata
		delete_option('mia_cold_cache_meta_' . $cacheKey);
		
		return $fileDeleted || !file_exists($filePath);
	}

	/**
	 * ✅ NUEVO: Promueve un valor de cold cache a hot cache.
	 * 
	 * @param   string  $cacheKey    Clave del caché
	 * @param   mixed   $value       Valor a promover
	 * @return  bool    True si se promovió correctamente
	 * @since   1.0.0
	 */
	private function promoteToHotCache(string $cacheKey, mixed $value): bool
	{
		// Obtener metadata para recuperar TTL
		$metaOptionName = 'mia_cold_cache_meta_' . $cacheKey;
		$meta = get_option($metaOptionName);
		
		$ttl = 0;
		if ($meta && is_array($meta) && isset($meta['ttl'])) {
			// Calcular TTL restante
			if (isset($meta['expires_at']) && $meta['expires_at'] > time()) {
				$ttl = $meta['expires_at'] - time();
			} else {
				$ttl = $meta['ttl'] ?? $this->default_ttl;
			}
		} else {
			$ttl = $this->default_ttl;
		}

		// Almacenar en hot cache
		$result = set_transient($cacheKey, $value, $ttl);
		
		if ($result) {
			// Eliminar de cold cache
			$this->removeFromColdCache($cacheKey);
		}
		
		return $result;
	}

	/**
	 * ✅ NUEVO: Determina si un valor debe almacenarse en hot cache basándose en frecuencia de acceso.
	 * 
	 * @param   string  $cacheKey    Clave del caché
	 * @return  bool    True si debe usar hot cache, false para cold cache
	 * @since   1.0.0
	 */
	private function shouldUseHotCache(string $cacheKey): bool
	{
		// Obtener métricas de uso si existen
		$usageMetrics = get_option('mia_transient_usage_metrics_' . $cacheKey, []);
		
		if (empty($usageMetrics) || !isset($usageMetrics['access_frequency'])) {
			// Sin métricas, usar hot cache por defecto (nuevos datos)
			return true;
		}
		
		$accessFrequency = $usageMetrics['access_frequency'];
		$hotCacheThreshold = get_option('mia_hot_cache_threshold', 'medium');
		
		$frequencyScores = [
			'very_high' => 100,
			'high' => 75,
			'medium' => 50,
			'low' => 25,
			'very_low' => 10,
			'never' => 0
		];
		
		$frequencyScore = $frequencyScores[$accessFrequency] ?? 0;
		$thresholdScore = $frequencyScores[$hotCacheThreshold] ?? 50;
		
		// Si la frecuencia de acceso es mayor o igual al threshold, usar hot cache
		return $frequencyScore >= $thresholdScore;
	}

	/**
	 * ✅ NUEVO: Migra datos de hot cache a cold cache basándose en baja frecuencia de acceso.
	 * 
	 * @return  array   Resultado con 'migrated_count' y 'skipped_count'
	 * @since   1.0.0
	 */
	public function performHotToColdMigration(): array
	{
		$result = [
			'migrated_count' => 0,
			'skipped_count' => 0,
			'errors' => []
		];
		
		if ($this->cache_dir === null) {
			return $result;
		}
		
		$hotCacheThreshold = get_option('mia_hot_cache_threshold', 'medium');
		$frequencyScores = [
			'very_high' => 100,
			'high' => 75,
			'medium' => 50,
			'low' => 25,
			'very_low' => 10,
			'never' => 0
		];
		$thresholdScore = $frequencyScores[$hotCacheThreshold] ?? 50;
		
		try {
			global $wpdb;
			
			// Obtener todos los transients del sistema de caché
			$sql = $wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} 
				WHERE option_name LIKE %s 
				AND option_name NOT LIKE %s",
				'_transient_' . $this->cache_prefix . '%',
				'_transient_timeout_%'
			);
			
			$transients = $wpdb->get_results($sql, ARRAY_A);
			
			if (empty($transients)) {
				return $result;
			}
			
			foreach ($transients as $transient) {
				$cacheKey = str_replace('_transient_', '', $transient['option_name']);
				
				// Obtener métricas de uso
				$usageMetrics = get_option('mia_transient_usage_metrics_' . $cacheKey, []);
				
				if (empty($usageMetrics) || !isset($usageMetrics['access_frequency'])) {
					// Sin métricas, saltar (mantener en hot cache)
					$result['skipped_count']++;
					continue;
				}
				
				$accessFrequency = $usageMetrics['access_frequency'];
				$frequencyScore = $frequencyScores[$accessFrequency] ?? 0;
				
				// Si la frecuencia es menor que el threshold, migrar a cold cache
				if ($frequencyScore < $thresholdScore) {
					try {
						$value = maybe_unserialize($transient['option_value']);
						
						// Obtener TTL desde metadata
						$metadata = $this->get_cache_metadata($cacheKey);
						$ttl = $metadata['ttl'] ?? $this->default_ttl;
						
						// Almacenar en cold cache
						if ($this->storeInColdCache($cacheKey, $value, $ttl)) {
							// Eliminar de hot cache
							delete_transient($cacheKey);
							$result['migrated_count']++;
						} else {
							$result['skipped_count']++;
							$result['errors'][] = "Error almacenando en cold cache: {$cacheKey}";
						}
					} catch (Exception $e) {
						$result['skipped_count']++;
						$result['errors'][] = "Error migrando {$cacheKey}: " . $e->getMessage();
						$this->logger->warning('Error migrando a cold cache', [
							'cache_key' => $cacheKey,
							'error' => $e->getMessage()
						]);
					}
				} else {
					// Frecuencia alta, mantener en hot cache
					$result['skipped_count']++;
				}
			}
		} catch (Exception $e) {
			$result['errors'][] = "Error general en migración: " . $e->getMessage();
			$this->logger->error('Error en migración hot→cold', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
		}
		
		return $result;
	}
}
