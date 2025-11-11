<?php
declare(strict_types=1);

/**
 * Helper para mapear datos de productos entre Verial y WooCommerce.
 *
 * VERSIÓN CONSOLIDADA: Implementación oficial de MapProduct para la integración de productos entre
 * la API de Verial y WooCommerce. Permite transformar, validar y sincronizar datos de productos,
 * gestionando diferencias de estructura, campos y lógica de negocio entre ambos sistemas.
 *
 * DEPENDENCIAS REQUERIDAS:
 * - MiIntegracionApi\Core\Config_Manager: Gestión de configuración
 * - MiIntegracionApi\DTOs\ProductDTO: Data Transfer Object para productos
 * - MiIntegracionApi\Core\DataSanitizer: Sanitización y validación de datos
 * - MiIntegracionApi\Helpers\Logger: Sistema de logging
 * - WooCommerce: Funciones wc_format_decimal(), wc_stock_amount()
 * - WordPress: Funciones de términos y taxonomías
 *
 * El archivo map-product.php es un enlace simbólico a este archivo para mantener compatibilidad legacy.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\Core\Config_Manager;
use MiIntegracionApi\DTOs\ProductDTO;
use MiIntegracionApi\Core\DataSanitizer;
use MiIntegracionApi\Helpers\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente al archivo
}

/**
 * Clase principal para el mapeo y sincronización de productos entre Verial y WooCommerce.
 *
 * Proporciona métodos estáticos para convertir datos de productos en ambos sentidos (Verial → WooCommerce y viceversa),
 * gestionar la obtención y creación de categorías, y asegurar la validación y sanitización de los datos críticos.
 *
 * Incluye integración con logger y sanitizer, soporte para caché de lotes y manejo de errores detallado.
 *
 * @since 1.0.0
 */
class MapProduct {
	/** @var Logger Instancia estática del logger para registrar eventos y errores. */
	private static $logger;
	/** @var DataSanitizer Instancia estática del sanitizador para validar y limpiar datos. */
	private static $sanitizer;
	/** @var bool Indica si la clase ya fue inicializada correctamente. */
	private static $initialized = false;

	/**
	 * Inicializa logger y sanitizer si aún no existen.
	 * Solo debe llamarse internamente para garantizar dependencias listas.
	 */
	private static function init() {
		if (self::$initialized) {
			return;
		}
		try {
			self::$logger = new Logger('map_product');
			self::$sanitizer = new DataSanitizer();
			self::$initialized = true;
		} catch (\Exception $e) {
			// Si falla la inicialización, registrar en error_log y marcar como no inicializado
			error_log('Error al inicializar MapProduct: ' . $e->getMessage());
			self::$initialized = false;
		}
	}

	/**
	 * Garantiza que la clase esté inicializada antes de ejecutar lógica de mapeo.
	 * Lanza excepción si la inicialización falla.
	 */
	private static function ensure_initialized(): void {
		if (!self::$initialized) {
			self::init();
		}
		if (!self::$initialized) {
			throw new \RuntimeException('No se pudo inicializar MapProduct');
		}
	}

	/**
	 * Mapea un producto de Verial a un DTO compatible con WooCommerce.
	 *
	 * Realiza la conversión y sanitización de los datos de un producto recibido desde la API de Verial
	 * al formato esperado por WooCommerce, incluyendo la obtención de precios, stock y categorías.
	 * Permite inyectar campos extra y utilizar caché de lotes para optimizar la sincronización masiva.
	 *
	 * @param array<string, mixed> $verial_product Array asociativo con los datos del producto de Verial.
	 * @param array<string, mixed> $extra_fields   Campos extra opcionales a añadir o sobrescribir en el resultado mapeado.
	 * @param array<string, mixed> $batch_cache    Caché de datos precargados para el lote (ej. precios, nombres de categorías).
	 *
	 * @return ProductDTO|null Instancia de ProductDTO con los datos mapeados, o null si los datos son inválidos o incompletos.
	 *
	 * @throws \RuntimeException Si la clase no puede inicializarse correctamente.
	 */
	public static function verial_to_wc(array $verial_product, array $extra_fields = array(), array $batch_cache = []): ?ProductDTO {
		self::ensure_initialized();
		self::init();

		$sku = $verial_product['ReferenciaBarras'] ?? $verial_product['Id'] ?? 'UNKNOWN';
		$start_time = microtime(true);
		
		// ✅ OPTIMIZADO: Log inicial eliminado - información consolidada al final

		if (!\MiIntegracionApi\Helpers\WooCommerceHelper::isFunctionAvailable('wc_format_decimal') || 
		    !\MiIntegracionApi\Helpers\WooCommerceHelper::isFunctionAvailable('wc_stock_amount')) {
			self::$logger->error("Funciones de WooCommerce no disponibles", [
				'sku' => $sku,
				'verial_id' => $verial_product['Id'] ?? 'N/A'
			]);
			return null;
		}

		// Validar datos mínimos requeridos
		if ((empty($verial_product['ReferenciaBarras']) && empty($verial_product['Id'])) || empty($verial_product['Nombre'])) {
			self::$logger->error('Producto Verial inválido: faltan campos requeridos', [
				'sku' => $sku,
				'verial_id' => $verial_product['Id'] ?? 'N/A',
				'has_referencia' => !empty($verial_product['ReferenciaBarras']),
				'has_id' => !empty($verial_product['Id']),
				'has_nombre' => !empty($verial_product['Nombre'])
			]);
			return null;
		}

		// ✅ OPTIMIZADO: Normalizar datos de Verial
		$verial_product = self::normalize_verial_product($verial_product);
		$product_data = self::prepareBasicProductData($verial_product);

		// Procesar componentes del producto (sin logs intermedios)
		$product_data = self::processProductPricing($verial_product, $product_data, $batch_cache);
		$product_data = self::processProductStock($verial_product, $product_data, $batch_cache);
		$product_data = self::processProductCategoriesFromBatch($verial_product, $product_data, $batch_cache);
		$product_data = self::processProductImages($verial_product, $product_data, $batch_cache);

		// Añadir campos extra si existen
		if (!empty($extra_fields)) {
			$product_data = array_merge($product_data, $extra_fields);
		}

		// Validar y crear DTO
		$dto = self::validateAndCreateDTO($product_data, $verial_product);

		// ✅ OPTIMIZADO: Log eliminado para productos exitosos - solo se registran errores
		// Esto evita generar archivos de log demasiado grandes durante sincronizaciones masivas
		if ($dto === null) {
			// Solo registrar errores de mapeo
			self::$logger->error("Mapeo falló", [
				'sku' => $sku,
				'verial_id' => $verial_product['Id'] ?? 'N/A',
				'product_data_keys' => array_keys($product_data)
			]);
		}

		return $dto;
	}

	/**
	 * Prepara los datos básicos del producto desde Verial
	 *
	 * @param array $verial_product Datos del producto de Verial
	 * @return array Datos básicos del producto
	 */
	private static function prepareBasicProductData(array $verial_product): array
	{
		// ✅ CORREGIDO: Estado del producto explícitamente 'publish'
		$product_status = 'publish'; // Forzar estado publicado
		
		// ✅ VERIFICACIÓN: Asegurar que el nombre no esté vacío
		$product_name = self::$sanitizer->sanitize($verial_product['Nombre'], 'text');
		if (empty($product_name)) {
			$product_name = 'Producto ' . ($verial_product['ReferenciaBarras'] ?? $verial_product['Id'] ?? 'Sin Nombre');
			self::$logger->warning('Nombre de producto vacío, usando nombre generado', [
				'verial_id' => $verial_product['Id'] ?? 'N/A',
				'sku' => $verial_product['ReferenciaBarras'] ?? 'N/A',
				'generated_name' => $product_name
			]);
		}

		return [
			'sku' => !empty($verial_product['ReferenciaBarras']) 
				? self::$sanitizer->sanitize($verial_product['ReferenciaBarras'], 'sku') 
				: self::$sanitizer->sanitize((string)($verial_product['Id'] ?? 'unknown'), 'sku'),
			'name' => $product_name,
			'price' => 0,
			'regular_price' => 0,
			'sale_price' => '',
			'description' => self::$sanitizer->sanitize($verial_product['DescripcionLarga'] ?? '', 'html'),
			'short_description' => self::$sanitizer->sanitize($verial_product['DescripcionCorta'] ?? '', 'html'),
			'stock_quantity' => 0, // Se establecerá desde batch_cache en processProductPricing
			'stock_status' => self::$sanitizer->sanitize($verial_product['Stock'] ?? 0, 'int') > 0 ? 'instock' : 'outofstock',
			'weight' => self::$sanitizer->sanitize($verial_product['Peso'] ?? 0, 'float'),
			
			// ✅ CORREGIDO: Campos individuales de dimensiones
			'length' => self::$sanitizer->sanitize($verial_product['Longitud'] ?? $verial_product['Grueso'] ?? 0, 'float'),
			'width' => self::$sanitizer->sanitize($verial_product['Ancho'] ?? 0, 'float'),
			'height' => self::$sanitizer->sanitize($verial_product['Alto'] ?? 0, 'float'),
			
			// ✅ CORREGIDO: Estado explícitamente 'publish'
			'status' => $product_status,
			'type' => 'simple',
			'external_id' => (string)($verial_product['Id'] ?? ''),
			
			// ✅ CORREGIDO: Campos adicionales requeridos por BatchProcessor
			'visibility' => 'visible',
			'tag_ids' => [],
			'meta_data' => [],
			'images' => [],
			'gallery' => [],
			'category_ids' => [], // ✅ NUEVO: Inicializar categorías vacías
			
			// ✅ NUEVO: Campos de gestión de stock explícitos
			'manage_stock' => true,
			'backorders' => 'no',
			'sold_individually' => false,
			'reviews_allowed' => true,
			'purchase_note' => '',
			'featured' => false,
			'virtual' => false,
			'downloadable' => false,
			
			// ✅ NUEVO: Metadatos específicos de Verial
			'verial_metadata' => [
				'verial_id' => $verial_product['Id'] ?? null,
				'verial_nombre' => $verial_product['Nombre'] ?? '',
				'verial_referencia' => $verial_product['ReferenciaBarras'] ?? '',
				'verial_categoria' => $verial_product['ID_Categoria'] ?? 0,
				'verial_fabricante' => $verial_product['ID_Fabricante'] ?? 0,
				'verial_tipo' => $verial_product['Tipo'] ?? 0
			],
			
			// ✅ NUEVO: Campos específicos de libros si es Tipo = 2
			'book_fields' => self::extractBookFields($verial_product)
		];
	}

	/**
	 * Extrae campos específicos de libros si el producto es de tipo libro (Tipo = 2)
	 * 
	 * @param array $verial_product Datos del producto de Verial
	 * @return array Campos específicos de libros o array vacío
	 */
	private static function extractBookFields(array $verial_product): array
	{
		// Solo procesar si es un libro (Tipo = 2)
		if (!isset($verial_product['Tipo']) || $verial_product['Tipo'] != 2) {
			return [];
		}
		
		$book_fields = [
			'autores' => $verial_product['Autores'] ?? [],
			'obra_completa' => $verial_product['ObraCompleta'] ?? '',
			'subtitulo' => $verial_product['Subtitulo'] ?? '',
			'menciones' => $verial_product['Menciones'] ?? '',
			'id_pais_publicacion' => $verial_product['ID_PaisPublicacion'] ?? 0,
			'edicion' => $verial_product['Edicion'] ?? '',
			'fecha_edicion' => $verial_product['FechaEdicion'] ?? '',
			'paginas' => $verial_product['Paginas'] ?? 0,
			'volumenes' => $verial_product['Volumenes'] ?? 0,
			'numero_volumen' => $verial_product['NumeroVolumen'] ?? '',
			'id_coleccion' => $verial_product['ID_Coleccion'] ?? 0,
			'numero_coleccion' => $verial_product['NumeroColeccion'] ?? '',
			'id_curso' => $verial_product['ID_Curso'] ?? 0,
			'id_asignatura' => $verial_product['ID_Asignatura'] ?? 0,
			'idioma_original' => $verial_product['IdiomaOriginal'] ?? '',
			'idioma_publicacion' => $verial_product['IdiomaPublicacion'] ?? '',
			'indice' => $verial_product['Indice'] ?? '',
			'resumen' => $verial_product['Resumen'] ?? ''
		];
		
		// Filtrar campos vacíos para optimizar almacenamiento
		return array_filter($book_fields, function($value) {
			return !empty($value) && $value !== 0;
		});
	}

	/**
	 * Procesa los precios del producto desde Verial
	 *
	 * @param array $verial_product Datos del producto de Verial
	 * @param array $product_data Datos del producto en progreso
	 * @param array $batch_cache Caché de lotes
	 * @return array Datos del producto con precios actualizados
	 */
	private static function processProductPricing(array $verial_product, array $product_data, array $batch_cache): array
	{
		if (!empty($verial_product['Id'])) {
			try {
				$id_articulo = (int)$verial_product['Id'];
				$condiciones_tarifa = null;
				
				// ✅ MEJORADO: Búsqueda más robusta en batch_cache
				if (!empty($batch_cache['batch_prices']) && isset($batch_cache['batch_prices'][$id_articulo])) {
					$condiciones_tarifa = $batch_cache['batch_prices'][$id_articulo];
				} elseif (!empty($batch_cache['condiciones_tarifa'])) {
					// Buscar en condiciones_tarifa directo
					$condiciones_array = $batch_cache['condiciones_tarifa']['CondicionesTarifa'] ?? $batch_cache['condiciones_tarifa'];
					if (is_array($condiciones_array)) {
						foreach ($condiciones_array as $condicion) {
							if (isset($condicion['ID_Articulo']) && (int)$condicion['ID_Articulo'] === $id_articulo) {
								$condiciones_tarifa = $condicion;
								break;
							}
						}
					}
				}
				
				if (is_array($condiciones_tarifa)) {
					$precio_base = 0;
					
					// ✅ MEJORADO: Múltiples formas de obtener el precio
					if (isset($condiciones_tarifa['Precio']) && is_numeric($condiciones_tarifa['Precio']) && $condiciones_tarifa['Precio'] > 0) {
						$precio_base = (float)$condiciones_tarifa['Precio'];
					} elseif (isset($condiciones_tarifa['PVP']) && is_numeric($condiciones_tarifa['PVP']) && $condiciones_tarifa['PVP'] > 0) {
						$precio_base = (float)$condiciones_tarifa['PVP'];
					} elseif (isset($verial_product['PVP']) && is_numeric($verial_product['PVP']) && $verial_product['PVP'] > 0) {
						$precio_base = (float)$verial_product['PVP'];
					}
					
					// ✅ CORREGIDO: Lógica de precios más robusta
					if ($precio_base > 0) {
						$precio_final = $precio_base;
						$sale_price = '';
						
						// Aplicar descuentos si existen
						if (isset($condiciones_tarifa['Dto']) && is_numeric($condiciones_tarifa['Dto']) && $condiciones_tarifa['Dto'] > 0) {
							$descuento = ($precio_base * $condiciones_tarifa['Dto']) / 100;
							$precio_final = $precio_base - $descuento;
							$sale_price = self::$sanitizer->sanitize($precio_final, 'price');
						}
						
						if (isset($condiciones_tarifa['DtoEurosXUd']) && is_numeric($condiciones_tarifa['DtoEurosXUd']) && $condiciones_tarifa['DtoEurosXUd'] > 0) {
							$precio_final = $precio_base - $condiciones_tarifa['DtoEurosXUd'];
							$sale_price = self::$sanitizer->sanitize($precio_final, 'price');
						}
						
						// Asignar precios
						$product_data['regular_price'] = self::$sanitizer->sanitize($precio_base, 'price');
						$product_data['price'] = self::$sanitizer->sanitize($precio_final, 'price');
						$product_data['sale_price'] = $sale_price;
						
						// ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
					} else {
						// ✅ MEJORADO: Precio por defecto si no se encuentra
						$product_data['price'] = 1.00;
						$product_data['regular_price'] = 1.00;
						$product_data['sale_price'] = '';
						
						self::$logger->warning('Precio no encontrado, usando valor por defecto', [
							'sku' => $product_data['sku'],
							'id_articulo' => $id_articulo
						]);
					}
				} else {
					// ✅ MEJORADO: Precio por defecto si no hay condiciones
					$product_data['price'] = 1.00;
					$product_data['regular_price'] = 1.00;
					$product_data['sale_price'] = '';
					
					self::$logger->warning('No se encontraron condiciones de tarifa, usando precio por defecto', [
						'sku' => $product_data['sku'],
						'id_articulo' => $id_articulo
					]);
				}
				
			} catch (\Exception $e) {
				// ✅ MEJORADO: Manejo de errores con precio por defecto
				$product_data['price'] = 1.00;
				$product_data['regular_price'] = 1.00;
				$product_data['sale_price'] = '';
				
				self::$logger->error('Error procesando precios, usando valores por defecto', [
					'sku' => $product_data['sku'],
					'error' => $e->getMessage()
				]);
			}
		} else {
			// ✅ MEJORADO: Producto sin ID, usar precio por defecto
			$product_data['price'] = 1.00;
			$product_data['regular_price'] = 1.00;
			$product_data['sale_price'] = '';
			
			self::$logger->warning('Producto sin ID Verial, usando precio por defecto', [
				'sku' => $product_data['sku']
			]);
		}

		return $product_data;
	}

	/**
	 * Procesa las categorías del producto usando mapeo pre-calculado del batch
	 *
	 * @param array $verial_product Datos del producto de Verial
	 * @param array $product_data Datos del producto en proceso
	 * @param array $batch_data Datos del batch con categorías validadas
	 * @return array Datos del producto con categorías actualizadas
	 */
	private static function processProductCategoriesFromBatch(array $verial_product, array $product_data, array $batch_data): array
	{
		// ✅ CORREGIDO: Buscar categorías validadas en el batch_data
		$wc_category_ids = [];
		
		// Buscar en productos validados del batch
		if (!empty($batch_data['productos']) && is_array($batch_data['productos'])) {
			$verial_id = $verial_product['Id'] ?? null;
			foreach ($batch_data['productos'] as $batch_product) {
				if (isset($batch_product['Id']) && $batch_product['Id'] == $verial_id) {
					$wc_category_ids = $batch_product['category_ids'] ?? [];
					break;
				}
			}
		}
		
		// Si no se encontraron categorías en el batch, intentar extraerlas directamente
		if (empty($wc_category_ids)) {
			$verial_category_ids = self::extractVerialCategoryIds($verial_product);
			if (!empty($verial_category_ids)) {
				// Buscar mapeos existentes en el batch
				$category_mappings = $batch_data['category_validation']['category_mappings'] ?? [];
				foreach ($verial_category_ids as $verial_id) {
					if (isset($category_mappings[$verial_id])) {
						$wc_category_ids[] = $category_mappings[$verial_id];
					}
				}
			}
		}
		
		// Asignar categorías al producto
		$product_data['category_ids'] = $wc_category_ids;
		
		// Obtener datos completos de las categorías
		if (!empty($wc_category_ids)) {
			$categories = [];
			foreach ($wc_category_ids as $category_id) {
				$term = get_term($category_id, 'product_cat');
				if ($term && !is_wp_error($term)) {
					$categories[] = [
						'id' => $category_id,
						'name' => $term->name,
						'slug' => $term->slug
					];
				}
			}
			$product_data['categories'] = $categories;
			
			// ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
		} else {
			self::$logger->warning('No se encontraron categorías para el producto', [
				'sku' => $product_data['sku'] ?? 'N/A',
				'verial_id' => $verial_product['Id'] ?? 'N/A',
				'verial_category_id' => $verial_product['ID_Categoria'] ?? 'N/A'
			]);
		}
		
		return $product_data;
	}
	
	/**
	 * Extrae todos los IDs de categorías de Verial del producto
	 * 
	 * @param array $verial_product Datos del producto de Verial
	 * @return array Array de IDs de categorías únicos
	 */
	private static function extractVerialCategoryIds(array $verial_product): array
	{
		$verial_category_ids = [];
		
		// 1. Categorías del array principal
		if (!empty($verial_product['Categorias']) && is_array($verial_product['Categorias'])) {
			foreach ($verial_product['Categorias'] as $verial_category) {
				if (!empty($verial_category['Id'])) {
					$verial_category_ids[] = (int)$verial_category['Id'];
				}
			}
		}
		
		// 2. Categorías de campos individuales (solo si no están en el array principal)
		$individual_fields = ['ID_Categoria', 'ID_CategoriaWeb1', 'ID_CategoriaWeb2', 'ID_CategoriaWeb3', 'ID_CategoriaWeb4'];
		foreach ($individual_fields as $field) {
			if (!empty($verial_product[$field]) && $verial_product[$field] > 0) {
				$cat_id = (int)$verial_product[$field];
				if (!in_array($cat_id, $verial_category_ids)) {
					$verial_category_ids[] = $cat_id;
				}
			}
		}
		
		return array_unique($verial_category_ids);
	}
	
	/**
	 * Obtiene mapeos existentes de categorías de la base de datos
	 * 
	 * @param array $verial_ids IDs de categorías de Verial
	 * @return array Mapeo [verial_id => wc_term_id]
	 */
	public static function get_category_mappings(array $verial_ids): array
	{
		global $wpdb;
		if (empty($verial_ids)) {
			return [];
		}

		$placeholders = implode(', ', array_fill(0, count($verial_ids), '%d'));
		$sql = "SELECT tm.term_id, tm.meta_value as verial_id
				FROM {$wpdb->termmeta} tm
				WHERE tm.meta_key = '_verial_category_id'
				AND tm.meta_value IN ({$placeholders})";

		$results = $wpdb->get_results($wpdb->prepare($sql, $verial_ids));

		$mappings = [];
		foreach ($results as $result) {
			$mappings[(int)$result->verial_id] = (int)$result->term_id;
		}

		return $mappings;
	}
	
	/**
	 * Obtiene el nombre de una categoría desde el batch cache
	 * 
	 * @param int $verial_id ID de la categoría en Verial
	 * @param array $batch_cache Caché del batch
	 * @return string Nombre de la categoría
	 */
	private static function getCategoryNameFromBatch(int $verial_id, array $batch_cache): string
	{
		// Buscar en categorías normales
		if (!empty($batch_cache['categorias_indexed'][$verial_id])) {
			return $batch_cache['categorias_indexed'][$verial_id];
		}
		
		// Buscar en categorías web
		if (!empty($batch_cache['categorias_web_indexed'][$verial_id])) {
			return $batch_cache['categorias_web_indexed'][$verial_id];
		}
		
		// Nombre genérico si no se encuentra
		return "Categoría Verial #{$verial_id}";
	}
	
	/**
	 * Crea una categoría de WooCommerce desde datos de Verial
	 * 
	 * @param int $verial_id ID de la categoría en Verial
	 * @param string $category_name Nombre de la categoría
	 * @return int|null ID de la categoría creada en WooCommerce
	 */
	private static function create_wc_category_from_verial(int $verial_id, string $category_name): ?int
	{
		if (empty($verial_id)) {
			return null;
		}
		
		// Si no hay nombre, usar nombre genérico
		if (empty($category_name)) {
			$category_name = "Categoría Verial #{$verial_id}";
		}
		
		// Verificar si ya existe por nombre
		$term_exists = term_exists($category_name, 'product_cat');
		if ($term_exists && is_array($term_exists) && isset($term_exists['term_id'])) {
			// Ya existe, guardar el mapeo
			update_term_meta((int)$term_exists['term_id'], '_verial_category_id', $verial_id);
			return (int)$term_exists['term_id'];
		}
		
		// Crear nueva categoría
		$new_term_data = wp_insert_term(
			sanitize_text_field($category_name), 
			'product_cat',
			[
				'slug' => 'categoria-verial-' . $verial_id
			]
		);
		
		if (!is_wp_error($new_term_data) && is_array($new_term_data) && isset($new_term_data['term_id'])) {
			// Guardar el mapeo
			update_term_meta((int)$new_term_data['term_id'], '_verial_category_id', $verial_id);
			return (int)$new_term_data['term_id'];
		}
		
		self::$logger->error('Error creando categoría de WooCommerce', [
			'verial_id' => $verial_id,
			'category_name' => $category_name,
			'error' => is_wp_error($new_term_data) ? $new_term_data->get_error_message() : 'Error desconocido'
		]);
		
		return null;
	}

	/**
	 * Procesa las imágenes del producto desde la media library.
	 *
	 * En la arquitectura de dos fases, las imágenes ya están procesadas
	 * y guardadas en la media library. Este método busca los attachments
	 * asociados al artículo usando metadatos.
	 *
	 * @param   array $verial_product Datos del producto de Verial.
	 * @param   array $product_data   Datos del producto de WooCommerce.
	 * @param   array $batch_cache    Cache del batch (no usado en nueva arquitectura).
	 * @return  array Datos del producto con imágenes asignadas.
	 * @since   1.5.0
	 */
	private static function processProductImages(array $verial_product, array $product_data, array $batch_cache): array
	{
		$verial_product_id = (int)($verial_product['Id'] ?? 0);
		$sku = $verial_product['ReferenciaBarras'] ?? $verial_product['Id'] ?? 'UNKNOWN';
		
		// ⚠️ CÓDIGO LEGACY COMENTADO: Búsqueda lineal en batch_cache
		// Este código se ha comentado porque la nueva arquitectura busca imágenes
		// directamente en la media library usando metadatos.
		//
		// Para rollback, descomentar este bloque y comentar la nueva lógica.
		//
		// Fecha de comentario: 2025-11-04
		// Arquitectura: Dos Fases v1.0
		/*
		$images = [];
		$gallery = [];

		// Buscar imágenes del producto en el batch cache
		if (!empty($batch_cache['imagenes_productos']) && is_array($batch_cache['imagenes_productos'])) {
			// ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
			
			foreach ($batch_cache['imagenes_productos'] as $index => $imagen_data) {
				if (!is_array($imagen_data)) {
					self::$logger->debug('Imagen no es array, saltando', [
						'sku' => $sku,
						'verial_id' => $verial_product_id,
						'index' => $index,
						'type' => gettype($imagen_data)
					]);
					continue;
				}
				
				if (empty($imagen_data['ID_Articulo'])) {
					self::$logger->debug('Imagen sin ID_Articulo, saltando', [
						'sku' => $sku,
						'verial_id' => $verial_product_id,
						'index' => $index,
						'keys' => array_keys($imagen_data)
					]);
					continue;
				}
				
				if (empty($imagen_data['Imagen'])) {
					self::$logger->debug('Imagen sin datos Base64, saltando', [
						'sku' => $sku,
						'verial_id' => $verial_product_id,
						'index' => $index,
						'ID_Articulo' => $imagen_data['ID_Articulo'] ?? 'N/A'
					]);
					continue;
				}

				$imagen_articulo_id = (int)$imagen_data['ID_Articulo'];
				if ($imagen_articulo_id === $verial_product_id) {
					$imagen_base64 = $imagen_data['Imagen'];
					
					// Crear URL temporal para la imagen (Base64 data URL)
					$image_url = 'data:image/jpeg;base64,' . $imagen_base64;
					
					// La primera imagen va a images, las demás a gallery
					if (empty($images)) {
						$images[] = $image_url;
						self::$logger->debug('Imagen principal encontrada', [
							'sku' => $sku,
							'verial_id' => $verial_product_id,
							'imagen_id_articulo' => $imagen_articulo_id,
							'imagen_base64_length' => strlen($imagen_base64)
						]);
					} else {
						$gallery[] = $image_url;
						self::$logger->debug('Imagen de galería encontrada', [
							'sku' => $sku,
							'verial_id' => $verial_product_id,
							'imagen_id_articulo' => $imagen_articulo_id,
							'gallery_count' => count($gallery)
						]);
					}
				}
				// ✅ ELIMINADO: Log innecesario "ID de artículo no coincide"
				// Este log generaba ~98% de ruido sin valor, ya que es comportamiento normal
				// durante la búsqueda de imágenes en el batch. El código funciona correctamente.
			}
		} else {
			self::$logger->debug('No hay imágenes en batch cache', [
				'sku' => $sku,
				'verial_id' => $verial_product_id,
				'has_imagenes_key' => isset($batch_cache['imagenes_productos']),
				'is_array' => isset($batch_cache['imagenes_productos']) ? is_array($batch_cache['imagenes_productos']) : false,
				'is_empty' => isset($batch_cache['imagenes_productos']) ? empty($batch_cache['imagenes_productos']) : true
			]);
		}

		// Asignar imágenes al producto
		$product_data['images'] = $images;
		$product_data['gallery'] = $gallery;

		// ✅ OPTIMIZADO: Log eliminado - información consolidada en log final

		return $product_data;
		*/

		// ✅ NUEVO: Buscar attachments en media library por article_id
		$attachment_ids = self::get_attachments_by_article_id($verial_product_id);
		
		if (empty($attachment_ids)) {
			self::$logger->debug('No se encontraron imágenes en media library', [
				'sku' => $sku,
				'verial_id' => $verial_product_id
			]);
			$product_data['images'] = [];
			$product_data['gallery'] = [];
			return $product_data;
		}

		// Primera imagen va a images, resto a gallery
		$images = [array_shift($attachment_ids)];
		$gallery = $attachment_ids;

		$product_data['images'] = $images;
		$product_data['gallery'] = $gallery;

		self::$logger->debug('Imágenes encontradas en media library', [
			'sku' => $sku,
			'verial_id' => $verial_product_id,
			'total_images' => count($images) + count($gallery)
		]);

		return $product_data;
	}

	/**
	 * Obtiene la instancia del logger
	 *
	 * @return Logger Instancia del logger
	 */
	private static function getLogger(): Logger
	{
		if (!self::$logger) {
			self::init();
		}
		return self::$logger;
	}

	/**
	 * Valida los datos del producto y crea el DTO
	 *
	 * @param array $product_data Datos del producto
	 * @param array $verial_product Datos originales de Verial
	 * @return ProductDTO|null DTO creado o null si falla
	 */
	private static function validateAndCreateDTO(array $product_data, array $verial_product): ?ProductDTO
	{
		// ✅ CORREGIDO: Validación más permisiva del SKU
		$sku = $product_data['sku'] ?? '';
		if (empty($sku) || !self::$sanitizer->validate($sku, 'sku')) {
			// Generar SKU válido si el original no es válido
			$original_sku = $verial_product['ReferenciaBarras'] ?? $verial_product['Id'] ?? 'UNKNOWN';
			$product_data['sku'] = 'VERIAL_' . md5($original_sku . time());
			
			self::$logger->warning('SKU inválido, generando SKU alternativo', [
				'sku_original' => $original_sku,
				'sku_nuevo' => $product_data['sku'],
				'verial_id' => $verial_product['Id'] ?? 'N/A'
			]);
		}

		// ✅ CORREGIDO: Validación de precio más flexible
		$price = $product_data['price'] ?? 0;
		if ($price < 0) {
			self::$logger->error('Precio negativo, estableciendo a 0', [
				'sku' => $product_data['sku'],
				'price' => $price
			]);
			$product_data['price'] = 0;
			$product_data['regular_price'] = 0;
		}

		// ✅ CORREGIDO: Asegurar que el nombre no esté vacío
		if (empty($product_data['name'])) {
			$product_data['name'] = 'Producto ' . $product_data['sku'];
			self::$logger->warning('Nombre vacío, generando nombre desde SKU', [
				'sku' => $product_data['sku'],
				'nombre_generado' => $product_data['name']
			]);
		}

		// ✅ CORREGIDO: Asegurar estado válido
		if (!in_array($product_data['status'] ?? '', ['publish', 'draft', 'pending', 'private'])) {
			$product_data['status'] = 'publish';
			self::$logger->warning('Estado inválido, estableciendo a "publish"', [
				'sku' => $product_data['sku'],
				'estado_original' => $product_data['status'] ?? 'N/A',
				'estado_nuevo' => 'publish'
			]);
		}

		try {
			$dto = new ProductDTO($product_data);
			
			// ✅ OPTIMIZADO: Log eliminado - información consolidada en log final del mapeo
			
			return $dto;
		} catch (\Exception $e) {
			self::$logger->error('Error crítico al crear ProductDTO', [
				'error' => $e->getMessage(),
				'sku' => $product_data['sku'],
				'product_data_keys' => array_keys($product_data),
				'trace' => $e->getTraceAsString()
			]);
			return null;
		}
	}

	/**
	 * Función de diagnóstico para verificar el mapeo de productos
	 * 
	 * @param array $verial_product Producto de Verial a diagnosticar
	 * @param array $batch_cache Caché del batch
	 * @return array Resultado del diagnóstico
	 */
	public static function diagnoseProductMapping(array $verial_product, array $batch_cache = []): array
	{
		self::ensure_initialized();
		
		$diagnosis = [
			'verial_product' => [
				'has_id' => !empty($verial_product['Id']),
				'has_sku' => !empty($verial_product['ReferenciaBarras']),
				'has_name' => !empty($verial_product['Nombre']),
				'has_stock' => isset($verial_product['Stock']),
				'keys_available' => array_keys($verial_product)
			],
			'mapping_steps' => [],
			'final_product' => null,
			'errors' => []
		];

		try {
			// Paso 1: Datos básicos
			$basic_data = self::prepareBasicProductData($verial_product);
			$diagnosis['mapping_steps']['basic_data'] = [
				'success' => !empty($basic_data['sku']) && !empty($basic_data['name']),
				'sku' => $basic_data['sku'],
				'name' => $basic_data['name'],
				'status' => $basic_data['status'],
				'stock_quantity' => $basic_data['stock_quantity']
			];

			// Paso 2: Precios
			$priced_data = self::processProductPricing($verial_product, $basic_data, $batch_cache);
			$diagnosis['mapping_steps']['pricing'] = [
				'success' => ($priced_data['price'] > 0),
				'price' => $priced_data['price'],
				'regular_price' => $priced_data['regular_price'],
				'sale_price' => $priced_data['sale_price']
			];

			// Paso 3: Categorías
			$categorized_data = self::processProductCategoriesFromBatch($verial_product, $priced_data, $batch_data);
			$diagnosis['mapping_steps']['categories'] = [
				'success' => true,
				'category_ids' => $categorized_data['category_ids'] ?? []
			];

			// Paso 4: Validación final
			$final_dto = self::validateAndCreateDTO($categorized_data, $verial_product);
			$diagnosis['mapping_steps']['validation'] = [
				'success' => ($final_dto !== null),
				'dto_created' => ($final_dto !== null)
			];

			if ($final_dto) {
				$diagnosis['final_product'] = $final_dto->toArray();
				$diagnosis['success'] = true;
			} else {
				$diagnosis['errors'][] = 'No se pudo crear el ProductDTO final';
				$diagnosis['success'] = false;
			}

		} catch (\Exception $e) {
			$diagnosis['errors'][] = 'Excepción durante el diagnóstico: ' . $e->getMessage();
			$diagnosis['success'] = false;
		}

		return $diagnosis;
	}

	/**
 * Verifica rápidamente si un producto de Verial puede ser mapeado correctamente
 * @param array $verial_product Producto de Verial
 * @return array Resultado de la verificación
 */
	public static function quickProductCheck(array $verial_product): array
	{
		$check = [
			'can_be_mapped' => false,
			'missing_fields' => [],
			'critical_issues' => [],
			'warnings' => []
		];

		// Campos críticos
		if (empty($verial_product['Id']) && empty($verial_product['ReferenciaBarras'])) {
			$check['critical_issues'][] = 'Falta ID o ReferenciaBarras';
		}

		if (empty($verial_product['Nombre'])) {
			$check['critical_issues'][] = 'Falta Nombre del producto';
		}

		// Campos recomendados
		if (empty($verial_product['Stock'])) {
			$check['warnings'][] = 'Stock no definido';
		}

		if (empty($verial_product['DescripcionLarga']) && empty($verial_product['DescripcionCorta'])) {
			$check['warnings'][] = 'Falta descripción del producto';
		}

		$check['can_be_mapped'] = empty($check['critical_issues']);

		return $check;
	}

	/**
	 * Mapea un producto de WooCommerce al formato de Verial.
	 *
	 * Convierte y sanitiza los datos de un producto de WooCommerce al formato requerido por la API de Verial,
	 * incluyendo campos estándar, atributos, categorías, etiquetas e imágenes.
	 *
	 * @param \WC_Product $wc_product Instancia de producto de WooCommerce a mapear.
	 * @return \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface
	 */
	public static function wc_to_verial(\WC_Product $wc_product): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		self::init();

		if (!$wc_product instanceof \WC_Product) {
			self::$logger->error('Producto WooCommerce inválido');
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'Producto WooCommerce inválido',
				400,
				[
					'endpoint' => 'MapProduct::wc_to_verial',
					'error_code' => 'invalid_wc_product',
					'product_id' => $wc_product ? $wc_product->get_id() : null,
					'timestamp' => time()
				]
			);
		}

		// Sanitizar datos
		$verial_product = [
			'Codigo' => self::$sanitizer->sanitize($wc_product->get_sku(), 'sku'),
			'Descripcion' => self::$sanitizer->sanitize($wc_product->get_name(), 'text'),
			'DescripcionLarga' => self::$sanitizer->sanitize($wc_product->get_description(), 'html'),
			'DescripcionCorta' => self::$sanitizer->sanitize($wc_product->get_short_description(), 'html'),
			'PVP' => self::$sanitizer->sanitize($wc_product->get_regular_price(), 'price'),
			'PVPOferta' => self::$sanitizer->sanitize($wc_product->get_sale_price(), 'price'),
			'Stock' => self::$sanitizer->sanitize($wc_product->get_stock_quantity(), 'int'),
			'Peso' => self::$sanitizer->sanitize($wc_product->get_weight(), 'float'),
			'Longitud' => self::$sanitizer->sanitize($wc_product->get_length(), 'float'),
			'Ancho' => self::$sanitizer->sanitize($wc_product->get_width(), 'float'),
			'Alto' => self::$sanitizer->sanitize($wc_product->get_height(), 'float'),
			'Categorias' => self::$sanitizer->sanitize($wc_product->get_category_ids(), 'int'),
			'Etiquetas' => self::$sanitizer->sanitize($wc_product->get_tag_ids(), 'int'),
			'Imagenes' => self::$sanitizer->sanitize($wc_product->get_gallery_image_ids(), 'int'),
			'Atributos' => self::$sanitizer->sanitize($wc_product->get_attributes(), 'text'),
			'MetaDatos' => self::$sanitizer->sanitize($wc_product->get_meta_data(), 'text')
		];

		// Validar datos críticos
		if (!self::$sanitizer->validate($verial_product['Codigo'], 'sku')) {
			self::$logger->error('SKU de producto inválido', [
				'sku' => $verial_product['Codigo']
			]);
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'SKU de producto inválido',
				400,
				[
					'endpoint' => 'MapProduct::wc_to_verial',
					'error_code' => 'invalid_product_sku',
					'sku' => $verial_product['Codigo'],
					'product_id' => $wc_product->get_id(),
					'timestamp' => time()
				]
			);
		}

		if (!self::$sanitizer->validate($verial_product['PVP'], 'price')) {
			self::$logger->error('Precio de producto inválido', [
				'price' => $verial_product['PVP']
			]);
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'Precio de producto inválido',
				400,
				[
					'endpoint' => 'MapProduct::wc_to_verial',
					'error_code' => 'invalid_product_price',
					'price' => $verial_product['PVP'],
					'product_id' => $wc_product->get_id(),
					'timestamp' => time()
				]
			);
		}

		return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
			$verial_product,
			'Producto mapeado correctamente a formato Verial',
			[
				'endpoint' => 'MapProduct::wc_to_verial',
				'product_id' => $wc_product->get_id(),
				'sku' => $verial_product['Codigo'],
				'mapping_successful' => true,
				'timestamp' => time()
			]
		);
	}

	// --- EJEMPLOS DE FUNCIONES HELPER PARA MAPEOLOGÍA AVANZADA (NO IMPLEMENTADAS COMPLETAMENTE) ---

	/**
	 * Obtiene o crea un ID de categoría de WooCommerce a partir de un ID y nombre de categoría de Verial.
	 *
	 * Busca primero en la caché de lote, luego en la base de datos (por metadato), y finalmente crea la categoría
	 * si no existe, asociando el ID de Verial como metadato. Permite mantener la correspondencia entre categorías
	 * de ambos sistemas y evitar duplicados.
	 *
	 * @param int         $verial_category_id   ID de la categoría en Verial.
	 * @param string      $verial_category_name Nombre de la categoría en Verial (opcional, para crearla si no existe).
	 * @param string      $taxonomy             Taxonomía de WooCommerce (por defecto 'product_cat').
	 * @param array<int,int> $category_cache    Caché de mapeos de categorías ['verial_id' => 'wc_id'].
	 *
	 * @return int|null    ID del término de WooCommerce si existe o se crea correctamente, null si falla.
	 */
	public static function get_or_create_wc_category_from_verial_id( int $verial_category_id, string $verial_category_name = '', string $taxonomy = 'product_cat', array $category_cache = [] ): ?int {
		self::ensure_initialized();
		if ( empty( $verial_category_id ) ) {
			return null;
		}

		// 1. Buscar primero en la caché de lote.
		if ( ! empty( $category_cache ) && isset( $category_cache[ $verial_category_id ] ) ) {
			$cached_value = $category_cache[ $verial_category_id ];
			// Asegurar que retornamos int o null
			return is_numeric($cached_value) ? (int) $cached_value : null;
		}

		// 2. Si no está en caché, buscar si ya existe un mapeo en la BD (ej. en term_meta)
		$args  = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'meta_query' => array(
				array(
					'key'     => '_verial_category_id', // Meta key para guardar el ID de Verial
					'value'   => $verial_category_id,
					'compare' => '=',
				),
			),
			'fields'     => 'ids', // Obtener solo IDs
		);
		$terms = get_terms( $args );
		if ( ! empty( $terms ) && is_array( $terms ) && ! is_wp_error( $terms ) && isset( $terms[0] ) && is_numeric( $terms[0] ) ) {
			// Si la categoría ya existe pero no tiene un nombre descriptivo (solo "Categoría Verial #X"),
			// actualizar el nombre si ahora disponemos de uno mejor
			if (!empty($verial_category_name)) {
				$term_obj = get_term($terms[0], $taxonomy);
				if ($term_obj && !is_wp_error($term_obj)) {
					$current_name = $term_obj->name;
					if (strpos($current_name, 'Categoría Verial #') === 0 && $current_name !== $verial_category_name) {
						wp_update_term($terms[0], $taxonomy, array(
							'name' => sanitize_text_field($verial_category_name)
						));
					}
				}
			}
			return (int) $terms[0];
		}

		// 3. Si no hay mapeo y se proporciona un nombre, intentar crear la categoría
		if ( ! empty( $verial_category_name ) ) {
			// Verificar si ya existe una categoría con ese nombre (para evitar duplicados por nombre)
			$term_exists = term_exists( $verial_category_name, $taxonomy );
			if ( $term_exists && is_array( $term_exists ) && isset( $term_exists['term_id'] ) && is_numeric( $term_exists['term_id'] ) ) {
				// La categoría ya existe por nombre, guardar el mapeo y devolver su ID
				update_term_meta( (int) $term_exists['term_id'], '_verial_category_id', $verial_category_id );
				return (int) $term_exists['term_id'];
			}

			// Crear la nueva categoría con el nombre real de Verial
			$new_term_data = wp_insert_term( sanitize_text_field( $verial_category_name ), $taxonomy );
			if ( ! is_wp_error( $new_term_data ) && is_array( $new_term_data ) && isset( $new_term_data['term_id'] ) && is_numeric( $new_term_data['term_id'] ) ) {
				// Guardar el ID de Verial como metadato del término para futuro mapeo
				update_term_meta( (int) $new_term_data['term_id'], '_verial_category_id', $verial_category_id );
				self::init();
				return (int) $new_term_data['term_id'];
			} elseif ( class_exists( 'MiIntegracionApi\\helpers\\Logger' ) && is_wp_error( $new_term_data ) && is_object( $new_term_data ) && method_exists( $new_term_data, 'get_error_message' ) ) {
				self::$logger->error( '[MapProduct] Error al crear categoría WC para ' . $verial_category_name . ': ' . $new_term_data->get_error_message(), array( 'context' => 'mia-mapper' ) );
			}
		} else {
			// Si no hay nombre disponible, crear con nombre genérico pero informativo
			$category_name = "Categoría Verial #$verial_category_id";
			$new_term_data = wp_insert_term( $category_name, $taxonomy, [
				'slug' => 'categoria-verial-' . $verial_category_id
			]);
			
			if ( ! is_wp_error( $new_term_data ) && is_array( $new_term_data ) && isset( $new_term_data['term_id'] ) ) {
				update_term_meta( (int) $new_term_data['term_id'], '_verial_category_id', $verial_category_id );
				return (int) $new_term_data['term_id'];
			} else {
				// Si falla la creación, registrar el error específico
				$error_message = is_wp_error($new_term_data) ? $new_term_data->get_error_message() : 'Error desconocido';
				self::$logger->error('Error al crear categoría genérica', [
					'verial_category_id' => $verial_category_id,
					'category_name' => $category_name,
					'error' => $error_message
				]);
			}
		}

		self::init();
		self::$logger->error('No se pudo mapear ni crear categoría WC', [
			'verial_category_id' => $verial_category_id,
			'verial_category_name' => $verial_category_name,
			'fallback_attempted' => empty($verial_category_name)
		]);
		return null;
	}

	/**
	 * Crea UNICAMENTE productos nuevos en WooCommerce
	 * NO busca productos existentes, NO actualiza nada
	 * Reutiliza métodos auxiliares existentes para evitar duplicación
	 * 
	 * @param array $product_data Datos del producto a crear
	 * @param array $batch_cache Caché del lote (opcional)
	 * @return WC_Product|WP_Error Producto creado o error
	 */
	// public static function create_wc_product_only(array $product_data, array $batch_cache = []): \WC_Product|\WP_Error
	// {
	// 	self::ensure_initialized();

	// 	// 1. VALIDACIÓN PREVIA
	// 	$validation_result = self::validate_product_data_before_wc($product_data);
	// 	if (is_wp_error($validation_result)) {
	// 		self::$logger->error('🚨 VALIDACIÓN PREVIA FALLIDA - Producto rechazado antes de WooCommerce', [
	// 			'sku' => $product_data['sku'] ?? 'N/A',
	// 			'error' => $validation_result->get_error_message(),
	// 			'error_code' => $validation_result->get_error_message(),
	// 			'product_data_keys' => array_keys($product_data),
	// 			'category' => 'pre-wc-validation'
	// 		]);
	// 		return $validation_result;
	// 	}

	// 	// 1.1 VALIDACIÓN DEL BATCH_CACHE (NUEVO - COMPLETITUD 100%)
	// 	$batch_processor = new \MiIntegracionApi\Core\BatchProcessor();
	// 	$batch_cache_valid = $batch_processor->validate_batch_cache_structure($batch_cache);
	// 	if (!$batch_cache_valid && !empty($batch_cache)) {
	// 		self::$logger->warning('⚠️ Batch cache inválido, usando fallback methods', [
	// 			'sku' => $product_data['sku'] ?? 'N/A',
	// 			'batch_cache_keys' => array_keys($batch_cache),
	// 			'action' => 'fallback_to_original_methods'
	// 		]);
	// 		$batch_cache = []; // Reset para usar fallback
	// 	}

	// 	// 2. Hook ANTES de que WooCommerce valide
	// 	do_action('mi_integracion_api_before_wc_product_validation', $product_data);

	// 	try {
	// 		// 3. CREAR NUEVO PRODUCTO (sin verificaciones de existencia)
	// 		$product = new \WC_Product();
			
	// 		// 4. ASIGNAR DATOS BÁSICOS
	// 		$product->set_sku($product_data['sku']);
	// 		$product->set_name($product_data['name']);
	// 		$product->set_regular_price($product_data['regular_price']);
			
	// 		// 5. ASIGNAR CAMPOS OPCIONALES
	// 		if (isset($product_data['description'])) {
	// 			$product->set_description($product_data['description']);
	// 		}
			
	// 		if (isset($product_data['short_description'])) {
	// 			$product->set_short_description($product_data['short_description']);
	// 		}
			
			
	// 		// 6. MANEJO DE SKUs DUPLICADOS (SIMPLIFICADO)
	// 		$original_sku = $product_data['sku'];
			
	// 		// Verificar si el SKU ya existe
	// 		$existing_product_with_sku = wc_get_product_id_by_sku($original_sku);
	// 		if ($existing_product_with_sku && $existing_product_with_sku !== $product->get_id()) {
	// 			// ✅ MIGRADO: Generar SKU único usando IdGenerator
	// 			$unique_suffix = \MiIntegracionApi\Helpers\IdGenerator::generateHash(
	// 				['sku' => $original_sku, 'product_id' => $product->get_id()],
	// 				'md5',
	// 				8
	// 			);
	// 			$unique_sku = $original_sku . '_' . $unique_suffix;
	// 			$product->set_sku($unique_sku);
				
	// 			self::$logger->warning('SKU duplicado resuelto con timestamp', [
	// 				'original_sku' => $original_sku,
	// 				'new_sku' => $unique_sku,
	// 				'existing_product_id' => $existing_product_with_sku,
	// 				'action' => 'sku_resolved_for_new_product',
	// 				'method' => 'timestamp_suffix'
	// 			]);
	// 		}
			
	// 		// 7. GUARDAR PRODUCTO
	// 		$product_id = $product->save();
			
	// 		if (!$product_id) {
	// 			return new \WP_Error(
	// 				'save_failed',
	// 				__('Error al guardar el producto.', 'mi-integracion-api')
	// 			);
	// 		}
			
	// 		// 8. METADATA DE SINCRONIZACIÓN
	// 		$product->update_meta_data('_verial_last_sync', current_time('mysql'));
	// 		$product->update_meta_data('_verial_sync_status', 'completed');
	// 		$product->update_meta_data('_verial_creation_batch', $batch_cache['batch_id'] ?? 'unknown');
	// 		$product->update_meta_data('_verial_needs_update', current_time('mysql')); // Marcar para actualización futura
			
	// 		// Extraer verial_id de forma optimizada
	// 		$verial_id = self::extract_verial_id($product_data);
	// 		if ($verial_id) {
	// 			$product->update_meta_data('_verial_id', $verial_id);
	// 			self::$logger->info('🏷️ Verial ID extraído y asignado', [
	// 				'sku' => $product_data['sku'],
	// 				'verial_id' => $verial_id,
	// 				'source' => 'extract_verial_id_helper'
	// 			]);
	// 		} else {
	// 			self::$logger->warning('⚠️ No se pudo extraer Verial ID del producto', [
	// 				'sku' => $product_data['sku'],
	// 				'product_data_keys' => array_keys($product_data),
	// 				'verial_id_fields' => [
	// 					'verial_id' => $product_data['verial_id'] ?? 'N/A',
	// 					'Id' => $product_data['Id'] ?? 'N/A',
	// 					'has_meta_data' => isset($product_data['meta_data']) && is_array($product_data['meta_data'])
	// 				]
	// 			]);
	// 		}
			
	// 		$product->save();
			
	// 		// 9. Hook DESPUÉS de la operación
	// 		do_action('mi_integracion_api_after_wc_product_operation', $product_data, $product);
			
	// 		// 10. LOG DE ÉXITO (OPTIMIZADO) + MÉTRICAS DE RENDIMIENTO (NUEVO - COMPLETITUD 100%)
	// 		$batch_info = !empty($batch_cache) ? [
	// 			'batch_cache_keys' => array_keys($batch_cache),
	// 			'batch_id' => $batch_cache['batch_id'] ?? 'N/A',
	// 			'batch_timestamp' => $batch_cache['timestamp'] ?? 'N/A'
	// 		] : ['batch_cache' => 'no_disponible'];
			
	// 		// MÉTRICAS DE RENDIMIENTO (NUEVO - COMPLETITUD 100%)
	// 		$performance_metrics = $batch_processor->generate_performance_metrics($batch_cache);
			
	// 		self::$logger->info('✅ Producto NUEVO creado exitosamente', [
	// 			'sku' => $product_data['sku'],
	// 			'product_id' => $product_id,
	// 			'verial_id' => $verial_id ?? 'N/A',
	// 			'action' => 'product_created_only',
	// 			'optimization_source' => !empty($batch_cache) ? 'batch_cache' : 'fallback_methods',
	// 			'batch_info' => $batch_info,
	// 			'performance_metrics' => $performance_metrics
	// 		]);
			
	// 		return $product;
			
	// 	} catch (\Exception $e) {
	// 		// Capturar excepciones de WooCommerce
	// 		$error_message = $e->getMessage();
			
	// 		// Detectar si es un error de SKU
	// 		if (strpos($error_message, 'SKU') !== false || strpos($error_message, 'duplicado') !== false || strpos($error_message, 'válido') !== false) {
	// 			self::$logger->error('🚨 ERROR DE SKU INTERCEPTADO EN EXCEPCIÓN - Creación de producto', [
	// 				'sku' => $product_data['sku'] ?? 'N/A',
	// 				'error_message' => $error_message,
	// 				'exception_class' => get_class($e),
	// 				'product_data_keys' => array_keys($product_data),
	// 				'product_data_sample' => array_slice($product_data, 0, 5),
	// 				'category' => 'exception-sku-interception-creation'
	// 			]);
	// 		}
			
	// 		// Crear WP_Error con información detallada
	// 		return new \WP_Error(
	// 			'wc_product_creation_error',
	// 			$error_message,
	// 			[
	// 				'sku' => $product_data['sku'] ?? 'N/A',
	// 				'product_data_keys' => array_keys($product_data),
	// 				'exception_class' => get_class($e),
	// 				'category' => 'wc-product-creation-only'
	// 			]
	// 		);
	// 	}
	// }

	/**
	 * @deprecated Este método ha sido eliminado. Usar InputValidation::validate_precio() directamente en su lugar.
	 */
	private static function validate_product_data_before_wc(array $product_data): \MiIntegracionApi\ErrorHandling\Responses\SyncResponseInterface {
		// Redirigir a la lógica consolidada
		$is_valid = \MiIntegracionApi\Core\InputValidation::validate_precio($product_data['regular_price'] ?? 0, [
			'status' => $product_data['status'] ?? 'draft',
			'product_type' => 'normal'
		]);
		
		if ($is_valid) {
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::success(
				$product_data,
				'Datos del producto válidos',
				['validation_passed' => true, 'timestamp' => time()]
			);
		} else {
			$errors = \MiIntegracionApi\Core\InputValidation::get_errors();
			return \MiIntegracionApi\ErrorHandling\Handlers\ResponseFactory::error(
				'Precio inválido: ' . implode(', ', $errors),
				400,
				['validation_errors' => $errors]
			);
		}
	}


	/**
	 * Extrae el ID de Verial de los datos del producto
	 * 
	 * @param array $product_data Datos del producto
	 * @return int|null ID de Verial o null
	 */
	private static function extract_verial_id(array $product_data): ?int {
		// Buscar en diferentes campos posibles
		$verial_id_fields = ['verial_id', 'Id', 'external_id'];
		
		foreach ($verial_id_fields as $field) {
			if (!empty($product_data[$field]) && is_numeric($product_data[$field])) {
				return (int)$product_data[$field];
			}
		}
		
		return null;
	}

	/**
	 * Obtiene el stock del producto desde el caché del batch
	 * 
	 * @param array $verial_product Datos del producto de Verial
	 * @param array $batch_cache Caché del batch
	 * @return int Stock del producto
	 */
	private static function getProductStockFromBatch(array $verial_product, array $batch_cache): int
	{
		$verial_id = (int)($verial_product['Id'] ?? 0);
		
		// Usar método robusto de BatchProcessor
		$stock = \MiIntegracionApi\Core\BatchProcessor::get_product_stock_from_batch(
			$verial_id,
			$batch_cache,
			(int)($verial_product['Stock'] ?? 0) // Fallback a datos básicos del producto
		);
		
		// ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
		
		return $stock;
	}

	/**
	 * Procesa el stock del producto usando caché optimizado
	 * 
	 * @param array $verial_product Datos del producto de Verial
	 * @param array $product_data Datos del producto WooCommerce
	 * @param array $batch_cache Caché del batch
	 * @return array Datos del producto con stock actualizado
	 */
	private static function processProductStock(array $verial_product, array $product_data, array $batch_cache): array
	{
		// Obtener stock desde caché optimizado
		$stock_quantity = self::getProductStockFromBatch($verial_product, $batch_cache);
		
		// Actualizar datos del producto
		$product_data['stock_quantity'] = $stock_quantity;
		$product_data['stock_status'] = $stock_quantity > 0 ? 'instock' : 'outofstock';
		
		// ✅ OPTIMIZADO: Log eliminado - información consolidada en log final
		
		return $product_data;
	}

	/**
	 * Normaliza los datos de un producto de Verial para su uso con WooCommerce
	 * 
	 * @param array $verial_product Datos originales del producto desde Verial
	 * @return array Datos normalizados
	 */
	public static function normalize_verial_product($verial_product) {
		// Asegurar que tenemos un array
		if (!is_array($verial_product)) {
			self::getLogger()->error('Producto Verial no es un array', [
				'source' => 'MapProduct', 
				'verial_product' => $verial_product
			]);
			return [];
		}
		
		// Normalizar los nombres de los campos inconsistentes
		$verial_product = self::normalizeFieldNames($verial_product);
		
		// Asegurar que los campos críticos existen
		$normalized = [
			// Campos básicos
			'Id' => isset($verial_product['Id']) ? (int)$verial_product['Id'] : 0,
			'ReferenciaBarras' => isset($verial_product['ReferenciaBarras']) ? $verial_product['ReferenciaBarras'] : '',
			'Nombre' => isset($verial_product['Nombre']) ? $verial_product['Nombre'] : '',
			'Descripcion' => isset($verial_product['Descripcion']) ? $verial_product['Descripcion'] : '',
			'Tipo' => isset($verial_product['Tipo']) ? (int)$verial_product['Tipo'] : 0,
			
			// Fechas importantes
			'FechaDisponibilidad' => isset($verial_product['FechaDisponibilidad']) ? $verial_product['FechaDisponibilidad'] : '',
			'FechaInicioVenta' => isset($verial_product['FechaInicioVenta']) ? $verial_product['FechaInicioVenta'] : '',
			'FechaInactivo' => isset($verial_product['FechaInactivo']) ? $verial_product['FechaInactivo'] : '',
			
			// Categorías - usando campos estandarizados que se agregan en normalizeFieldNames
			'ID_Categoria' => isset($verial_product['ID_Categoria']) ? (int)$verial_product['ID_Categoria'] : 0,
			'CategoriaId' => isset($verial_product['CategoriaId']) ? (int)$verial_product['CategoriaId'] : 
							 (isset($verial_product['ID_Categoria']) ? (int)$verial_product['ID_Categoria'] : 0),
			'ID_CategoriaWeb1' => isset($verial_product['ID_CategoriaWeb1']) ? (int)$verial_product['ID_CategoriaWeb1'] : 0,
			'ID_CategoriaWeb2' => isset($verial_product['ID_CategoriaWeb2']) ? (int)$verial_product['ID_CategoriaWeb2'] : 0,
			'ID_CategoriaWeb3' => isset($verial_product['ID_CategoriaWeb3']) ? (int)$verial_product['ID_CategoriaWeb3'] : 0,
			'ID_CategoriaWeb4' => isset($verial_product['ID_CategoriaWeb4']) ? (int)$verial_product['ID_CategoriaWeb4'] : 0,
			
			// Fabricante
			'ID_Fabricante' => isset($verial_product['ID_Fabricante']) ? (int)$verial_product['ID_Fabricante'] : 0,
			'FabricanteId' => isset($verial_product['FabricanteId']) ? (int)$verial_product['FabricanteId'] : 
							  (isset($verial_product['ID_Fabricante']) ? (int)$verial_product['ID_Fabricante'] : 0),
							  
			// Impuestos
			'PorcentajeIVA' => isset($verial_product['PorcentajeIVA']) ? (float)$verial_product['PorcentajeIVA'] : 0,
			'PorcentajeRE' => isset($verial_product['PorcentajeRE']) ? (float)$verial_product['PorcentajeRE'] : 0,
			
			// Dimensiones físicas
			'Peso' => isset($verial_product['Peso']) ? (float)$verial_product['Peso'] : 0,
			'Alto' => isset($verial_product['Alto']) ? (float)$verial_product['Alto'] : 0,
			'Ancho' => isset($verial_product['Ancho']) ? (float)$verial_product['Ancho'] : 0,
			'Grueso' => isset($verial_product['Grueso']) ? (float)$verial_product['Grueso'] : 0,
			'NumDimensiones' => isset($verial_product['NumDimensiones']) ? (int)$verial_product['NumDimensiones'] : 0,
			
			// Unidades de venta
			'NombreUds' => isset($verial_product['NombreUds']) ? $verial_product['NombreUds'] : '',
			'NombreUdsAux' => isset($verial_product['NombreUdsAux']) ? $verial_product['NombreUdsAux'] : '',
			'NombreUdsOCU' => isset($verial_product['NombreUdsOCU']) ? $verial_product['NombreUdsOCU'] : '',
			'RelacionUdsAux' => isset($verial_product['RelacionUdsAux']) ? (float)$verial_product['RelacionUdsAux'] : 0,
			'RelacionUdsOCU' => isset($verial_product['RelacionUdsOCU']) ? (float)$verial_product['RelacionUdsOCU'] : 0,
			'VenderUdsAux' => isset($verial_product['VenderUdsAux']) ? (bool)$verial_product['VenderUdsAux'] : false,
			'DecUdsVentas' => isset($verial_product['DecUdsVentas']) ? (int)$verial_product['DecUdsVentas'] : 0,
			'DecPrecioVentas' => isset($verial_product['DecPrecioVentas']) ? (int)$verial_product['DecPrecioVentas'] : 0,
			
			// Nexo para productos relacionados
			'Nexo' => isset($verial_product['Nexo']) ? $verial_product['Nexo'] : '',
			
			// Ecotasas
			'ID_ArticuloEcotasas' => isset($verial_product['ID_ArticuloEcotasas']) ? (int)$verial_product['ID_ArticuloEcotasas'] : 0,
			'PrecioEcotasas' => isset($verial_product['PrecioEcotasas']) ? (float)$verial_product['PrecioEcotasas'] : 0,
			
			// Campos auxiliares configurables
			'Aux1' => isset($verial_product['Aux1']) ? $verial_product['Aux1'] : '',
			'Aux2' => isset($verial_product['Aux2']) ? $verial_product['Aux2'] : '',
			'Aux3' => isset($verial_product['Aux3']) ? $verial_product['Aux3'] : '',
			'Aux4' => isset($verial_product['Aux4']) ? $verial_product['Aux4'] : '',
			'Aux5' => isset($verial_product['Aux5']) ? $verial_product['Aux5'] : '',
			'Aux6' => isset($verial_product['Aux6']) ? $verial_product['Aux6'] : '',
			
			// Campos configurables del usuario
			'CamposConfigurables' => isset($verial_product['CamposConfigurables']) ? $verial_product['CamposConfigurables'] : [],
			
			// ✅ NUEVO: Campos adicionales importantes
			'DescripcionLarga' => isset($verial_product['DescripcionLarga']) ? $verial_product['DescripcionLarga'] : '',
			'DescripcionCorta' => isset($verial_product['DescripcionCorta']) ? $verial_product['DescripcionCorta'] : '',
			'Stock' => isset($verial_product['Stock']) ? (int)$verial_product['Stock'] : 0,
			'Peso' => isset($verial_product['Peso']) ? (float)$verial_product['Peso'] : 0,
			'Ancho' => isset($verial_product['Ancho']) ? (float)$verial_product['Ancho'] : 0,
			'Alto' => isset($verial_product['Alto']) ? (float)$verial_product['Alto'] : 0,
			'Grueso' => isset($verial_product['Grueso']) ? (float)$verial_product['Grueso'] : 0,
			'Longitud' => isset($verial_product['Longitud']) ? (float)$verial_product['Longitud'] : 0,
			'PorcentajeIVA' => isset($verial_product['PorcentajeIVA']) ? (float)$verial_product['PorcentajeIVA'] : 0,
			'PorcentajeRE' => isset($verial_product['PorcentajeRE']) ? (float)$verial_product['PorcentajeRE'] : 0,
			'Nexo' => isset($verial_product['Nexo']) ? $verial_product['Nexo'] : '',
			'ID_ArticuloEcotasas' => isset($verial_product['ID_ArticuloEcotasas']) ? (int)$verial_product['ID_ArticuloEcotasas'] : 0,
			'PrecioEcotasas' => isset($verial_product['PrecioEcotasas']) ? (float)$verial_product['PrecioEcotasas'] : 0
		];
		
		// Agregar campos específicos para libros si existen
		if (isset($verial_product['Tipo']) && $verial_product['Tipo'] == 2) {
			// Campos específicos de libros
			$normalized['Autores'] = isset($verial_product['Autores']) ? $verial_product['Autores'] : [];
			$normalized['ObraCompleta'] = isset($verial_product['ObraCompleta']) ? $verial_product['ObraCompleta'] : '';
			$normalized['Subtitulo'] = isset($verial_product['Subtitulo']) ? $verial_product['Subtitulo'] : '';
			$normalized['Menciones'] = isset($verial_product['Menciones']) ? $verial_product['Menciones'] : '';
			$normalized['ID_PaisPublicacion'] = isset($verial_product['ID_PaisPublicacion']) ? (int)$verial_product['ID_PaisPublicacion'] : 0;
			$normalized['Edicion'] = isset($verial_product['Edicion']) ? $verial_product['Edicion'] : '';
			$normalized['FechaEdicion'] = isset($verial_product['FechaEdicion']) ? $verial_product['FechaEdicion'] : '';
			$normalized['Paginas'] = isset($verial_product['Paginas']) ? (int)$verial_product['Paginas'] : 0;
			$normalized['Volumenes'] = isset($verial_product['Volumenes']) ? (int)$verial_product['Volumenes'] : 0;
			$normalized['NumeroVolumen'] = isset($verial_product['NumeroVolumen']) ? $verial_product['NumeroVolumen'] : '';
			$normalized['ID_Coleccion'] = isset($verial_product['ID_Coleccion']) ? (int)$verial_product['ID_Coleccion'] : 0;
			$normalized['NumeroColeccion'] = isset($verial_product['NumeroColeccion']) ? $verial_product['NumeroColeccion'] : '';
			$normalized['ID_Curso'] = isset($verial_product['ID_Curso']) ? (int)$verial_product['ID_Curso'] : 0;
			$normalized['ID_Asignatura'] = isset($verial_product['ID_Asignatura']) ? (int)$verial_product['ID_Asignatura'] : 0;
			$normalized['IdiomaOriginal'] = isset($verial_product['IdiomaOriginal']) ? $verial_product['IdiomaOriginal'] : '';
			$normalized['IdiomaPublicacion'] = isset($verial_product['IdiomaPublicacion']) ? $verial_product['IdiomaPublicacion'] : '';
			$normalized['Indice'] = isset($verial_product['Indice']) ? $verial_product['Indice'] : '';
			$normalized['Resumen'] = isset($verial_product['Resumen']) ? $verial_product['Resumen'] : '';
		} else {
			// Para artículos normales, mantener compatibilidad con campos que podrían existir
			if (isset($verial_product['Autores'])) {
				$normalized['Autores'] = $verial_product['Autores'];
			}
			if (isset($verial_product['Edicion'])) {
				$normalized['Edicion'] = $verial_product['Edicion'];
			}
			if (isset($verial_product['Paginas'])) {
				$normalized['Paginas'] = (int)$verial_product['Paginas'];
			}
			if (isset($verial_product['Subtitulo'])) {
				$normalized['Subtitulo'] = $verial_product['Subtitulo'];
			}
		}
		
		// ✅ OPTIMIZADO: Log reducido - JSON completo eliminado (~1,190 chars por log)
		// Solo registrar campos clave para reducir tamaño del log
		self::getLogger()->debug('Producto Verial normalizado', [
			'source' => 'MapProduct',
			'id' => $normalized['Id'] ?? 0,
			'sku' => $normalized['ReferenciaBarras'] ?? '',
			'nombre' => $normalized['Nombre'] ?? '',
			'tipo' => $normalized['Tipo'] ?? 0,
			'categoria_id' => $normalized['ID_Categoria'] ?? 0
		]);
		
		return $normalized;
	}

	/**
	 * Normaliza los nombres de campos inconsistentes en respuestas de API de Verial
	 * 
	 * La API de Verial tiene inconsistencias en cómo nombra los campos en diferentes endpoints.
	 * Este método normaliza estos campos para facilitar el procesamiento.
	 *
	 * @param array $verial_data Datos originales de Verial
	 * @return array Datos con nombres de campos normalizados
	 */
	public static function normalizeFieldNames($verial_data) {
		if (!is_array($verial_data)) {
			return [];
		}

		$result = $verial_data;
		
		// Normalización de categorías
		if (isset($result['Id']) && !isset($result['ID_Categoria']) && isset($result['Clave'])) {
			// Si tiene Id y Clave pero no ID_Categoria, es probablemente del endpoint GetCategoriasWS
			$result['ID_Categoria'] = $result['Id'];
		}
		
		// Verificar si hay categorías web adicionales y normalizarlas
		$category_web_fields = ['ID_CategoriaWeb1', 'ID_CategoriaWeb2', 'ID_CategoriaWeb3', 'ID_CategoriaWeb4'];
		foreach ($category_web_fields as $field) {
			// Normalizar posibles variaciones en nombres de campo
			$variations = [
				$field,
				str_replace('ID_', '', $field),
				str_replace('Web', '', $field)
			];
			
			// Buscar en todas las variaciones posibles
			$found_value = null;
			foreach ($variations as $var) {
				if (isset($result[$var]) && !empty($result[$var])) {
					$found_value = $result[$var];
					break;
				}
			}
			
			// Si encontramos un valor, asegurarse de que esté en todos los formatos
			if ($found_value !== null) {
				foreach ($variations as $var) {
					$result[$var] = $found_value;
				}
			}
		}
		
		// Normalización de fabricantes
		if (isset($result['Id']) && !isset($result['ID_Fabricante']) && isset($result['Nombre']) && isset($result['Tipo'])) {
			// Si tiene Id, Nombre y Tipo pero no ID_Fabricante, es probablemente del endpoint GetFabricantesWS
			$result['ID_Fabricante'] = $result['Id'];
		}
		
		// Normalización de productos
		if (isset($result['Codigo']) && !isset($result['Id'])) {
			$result['Id'] = $result['Codigo']; // Algunos endpoints usan Codigo en lugar de Id
		}		
		
		return $result;
	}

	/**
	 * Actualiza o crea un mapeo entre un producto de WooCommerce y Verial
	 * 
	 * @param int $wc_id ID del producto en WooCommerce
	 * @param int $verial_id ID del producto en Verial
	 * @param string $sku SKU del producto
	 * @return bool true si fue exitoso, false si falló
	 */
	public static function upsert_product_mapping(int $wc_id, int $verial_id, string $sku): bool {
		try {
			global $wpdb;
			$table_name = $wpdb->prefix . 'verial_product_mapping';
			
			self::getLogger()->debug("Actualizando mapeo de producto: WC ID #$wc_id - Verial ID #$verial_id - SKU: $sku");
			
			// Verificar si la tabla existe, si no, crearla
			if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
				self::getLogger()->info("Tabla de mapeo no encontrada, creando: $table_name");
				self::create_mapping_table();
			}
			
			// Actualizar metadatos en WordPress (para compatibilidad)
			update_post_meta($wc_id, '_verial_product_id', $verial_id);
			
			// Verificar si ya existe un mapeo
			$existing = $wpdb->get_var($wpdb->prepare(
				"SELECT id FROM $table_name WHERE wc_id = %d OR verial_id = %d",
				$wc_id, $verial_id
			));
			
			if ($existing) {
				// Actualizar mapeo existente
				$result = $wpdb->update(
					$table_name,
					[
						'wc_id' => $wc_id,
						'verial_id' => $verial_id,
						'sku' => $sku,
						'updated_at' => current_time('mysql')
					],
					['id' => $existing],
					['%d', '%d', '%s', '%s'],
					['%d']
				);
				self::getLogger()->debug("Mapeo existente actualizado: ID #$existing");
			} else {
				// Crear nuevo mapeo
				$result = $wpdb->insert(
					$table_name,
					[
						'wc_id' => $wc_id,
						'verial_id' => $verial_id,
						'sku' => $sku,
						'created_at' => current_time('mysql'),
						'updated_at' => current_time('mysql')
					],
					['%d', '%d', '%s', '%s', '%s']
				);
				if ($result) {
					$new_id = $wpdb->insert_id;
					// ✅ OPTIMIZADO: Log eliminado - información redundante (disponible en otros logs)
				}
			}
			
			return $result !== false;
		} catch (\Exception $e) {
			self::getLogger()->error("Error al actualizar mapeo de producto: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Obtiene el ID de Verial asociado con el ID de producto de WooCommerce
	 * 
	 * @param int $wc_product_id ID del producto en WooCommerce
	 * @return int|null ID del producto en Verial o null si no se encuentra
	 */
	public static function get_verial_id_by_wc_id(int $wc_product_id): ?int {
		global $wpdb;
		$table_name = $wpdb->prefix . 'verial_product_mapping';
		
		// Primero, verificar en la tabla de mapeo
		if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
			$verial_id = $wpdb->get_var($wpdb->prepare(
				"SELECT verial_id FROM $table_name WHERE wc_id = %d",
				$wc_product_id
			));
			
			if ($verial_id) {
				return (int) $verial_id;
			}
		}
		
		// Si no se encontró en la tabla de mapeo, buscar en los metadatos
		$verial_id = get_post_meta($wc_product_id, '_verial_product_id', true);
		
		if ($verial_id) {
			return (int) $verial_id;
		}
		
		return null;
	}

	/**
	 * Registra hooks para actualización automática de nombres de categorías después de cada sincronización masiva de productos
	 * Esta función debe llamarse durante la inicialización del plugin
	 */
	public static function register_auto_update_hooks() {
		// Registrar hook para actualización de nombres de categorías después de sincronización
		if (!has_action('mi_integracion_api_sync_completed', [self::class, 'update_category_names_from_api'])) {
			add_action('mi_integracion_api_sync_completed', [self::class, 'update_category_names_from_api']);
		}
		
		// Registrar hook para limpieza del sufijo Sinli después de actualizar los nombres
		if (!has_action('mi_integracion_api_sync_completed', [self::class, 'clean_sinli_suffix_from_categories'])) {
			add_action('mi_integracion_api_sync_completed', [self::class, 'clean_sinli_suffix_from_categories'], 20); // Prioridad 20 para que se ejecute después de update_category_names_from_api
		}
	}

	/**
	 * Actualiza los nombres genéricos de categorías existentes con nombres reales
	 * Utilizar esta función cuando se quiera forzar una actualización de todos los nombres
	 * genéricos por nombres reales obtenidos de la API
	 * 
	 * @return array Resultado con estadísticas de actualización
	 */
	public static function update_category_names_from_api($operation_id = '', $stats = []): array {
		self::getLogger()->info('Iniciando actualización de nombres de categorías', [
			'operation_id' => $operation_id,
			'stats' => $stats
		]);
		$result = [
			'processed' => 0,
			'updated' => 0,
			'errors' => 0,
			'skipped' => 0
		];
		
		try {
			// 1. Obtener todas las categorías de WooCommerce que tengan metadato _verial_category_id
			$args = [
				'taxonomy' => 'product_cat',
				'hide_empty' => false,
				'meta_query' => [
					[
						'key' => '_verial_category_id',
						'compare' => 'EXISTS'
					]
				]
			];
			
			$terms = get_terms($args);
			
			if (is_wp_error($terms)) {
				self::getLogger()->error('Error al obtener categorías de WooCommerce: ' . $terms->get_error_message());
				return $result;
			}
			
			// ELIMINADO: Ya no usamos ApiConnector directamente
			// Esta función debe usar datos del batch_cache en lugar de API
			self::getLogger()->error('Método update_category_names_from_api deshabilitado - usar batch_cache');
			return $result;
			
		} catch (\Exception $e) {
			self::getLogger()->error('Excepción al actualizar categorías: ' . $e->getMessage());
			$result['errors']++;
			return $result;
		}
	}

	/**
	 * Limpia todas las categorías del sufijo ", Sinli"
	 * Esta función es útil para corregir categorías existentes
	 * 
	 * @return array Resultado con estadísticas de la limpieza
	 */
	public static function clean_sinli_suffix_from_categories(): array {
		$result = [
			'total' => 0,
			'updated' => 0,
			'skipped' => 0,
			'errors' => 0
		];
		
		self::getLogger()->info('Iniciando limpieza de sufijo Sinli de categorías');
		
		try {
			// Obtener todas las categorías de productos
			$terms = get_terms([
				'taxonomy' => 'product_cat',
				'hide_empty' => false
			]);
			
			if (is_wp_error($terms)) {
				self::getLogger()->error('Error al obtener categorías: ' . $terms->get_error_message());
				$result['errors']++;
				return $result;
			}
			
			foreach ($terms as $term) {
				$result['total']++;
				$current_name = $term->name;
				
				// Verificar si el nombre termina con ", Sinli"
				if (preg_match('/, Sinli$/', $current_name)) {
					$clean_name = preg_replace('/, Sinli$/', '', $current_name);
					
					self::getLogger()->info('Limpiando sufijo Sinli de categoría', [
						'term_id' => $term->term_id,
						'nombre_anterior' => $current_name,
						'nombre_nuevo' => $clean_name
					]);
					
					$update = wp_update_term($term->term_id, 'product_cat', [
						'name' => $clean_name
					]);
					
					if (is_wp_error($update)) {
						self::getLogger()->error('Error al actualizar categoría', [
							'term_id' => $term->term_id,
							'nombre' => $current_name,
							'error' => $update->get_error_message()
						]);
						$result['errors']++;
					} else {
						$result['updated']++;
						
						// Limpiar caché
						clean_term_cache($term->term_id, 'product_cat');
					}
				} else {
					$result['skipped']++;
				}
			}
			
			// Limpiar cachés globales después de todas las actualizaciones
			delete_transient('wc_term_counts');
			if (function_exists('wc_delete_product_transients')) {
				wc_delete_product_transients();
			}
			
			self::getLogger()->info('Limpieza de sufijo Sinli completada', $result);
			
		} catch (\Exception $e) {
			self::getLogger()->error('Excepción al limpiar sufijos Sinli: ' . $e->getMessage());
			$result['errors']++;
		}
		
		return $result;
	}

	/**
	 * Crea la tabla de mapeo de productos entre WooCommerce y Verial
	 * 
	 * @return bool true si fue exitoso, false si falló
	 */
	private static function create_mapping_table(): bool {
		try {
			// Usar el Installer centralizado para crear la tabla
			if (class_exists('MiIntegracionApi\\Core\\Installer')) {
				$result = \MiIntegracionApi\Core\Installer::create_product_mapping_table();
				if ($result) {
					self::getLogger()->info("Tabla de mapeo de productos creada usando Installer centralizado");
				}
				return $result;
			} else {
				self::getLogger()->error("Clase Installer no encontrada, no se puede crear la tabla de mapeo");
				return false;
			}
		} catch (\Exception $e) {
			self::getLogger()->error("Error al crear tabla de mapeo: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Obtiene attachment IDs de imágenes por ID de artículo de Verial.
	 *
	 * Busca en la media library attachments asociados a un artículo específico
	 * usando metadatos. Retorna los attachments ordenados por _verial_image_order.
	 *
	 * @param   int $article_id ID del artículo de Verial.
	 * @return  array Array de attachment IDs ordenados.
	 * @since   1.5.0
	 */
	public static function get_attachments_by_article_id(int $article_id): array
	{
		$args = [
			'post_type' => 'attachment',
			'post_mime_type' => 'image',
			'meta_query' => [
				[
					'key' => '_verial_article_id',
					'value' => $article_id,
					'compare' => '='
				]
			],
			'posts_per_page' => -1,
			'fields' => 'ids'
		];

		$attachment_ids = get_posts($args);

		if (empty($attachment_ids)) {
			return [];
		}

		// Ordenar por orden guardado
		usort($attachment_ids, function($a, $b) use ($article_id) {
			$order_a = get_post_meta($a, '_verial_image_order', true) ?: 999;
			$order_b = get_post_meta($b, '_verial_image_order', true) ?: 999;
			return $order_a <=> $order_b;
		});

		return array_map('intval', $attachment_ids);
	}
}
