<?php declare(strict_types=1);
/**
 * Funciones de utilidad y validación consolidadas para MiIntegracionApi.
 *
 * Este archivo contiene la clase principal de utilidades (`Utils`) y la función global
 * `mi_integracion_api_validate_filters`, que proporcionan métodos para sanitización,
 * validación de datos, manipulación de arrays, fechas, strings y generación de hashes.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */

namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase con utilidades y funciones de validación para datos comunes en WordPress y WooCommerce.
 *
 * Consolida la funcionalidad de las antiguas clases Utils y Validation, proporcionando métodos
 * estáticos para sanitizar, validar y transformar datos de uso frecuente en la integración.
 *
 * @since 1.0.0
 */
class Utils {
		/**
		 * Elimina etiquetas HTML y recorta espacios en blanco de una cadena.
		 *
		 * @param string $str Cadena a sanitizar.
		 * @return string Cadena sanitizada (sin HTML y sin espacios al inicio/fin).
		 */
		public static function sanitize_string( string $str ): string {
			return trim( strip_tags( $str ) );
		}

		/**
		 * Sanitiza recursivamente todos los valores string de un array (incluyendo subarrays).
		 *
		 * @param array $arr Array a sanitizar (puede contener subarrays).
		 * @return array Array con todos los valores string sanitizados.
		 */
		public static function sanitize_array_strings( array $arr ): array {
			foreach ( $arr as $key => $value ) {
				if ( is_array( $value ) ) {
					$arr[ $key ] = self::sanitize_array_strings( $value );
				} elseif ( is_string( $value ) ) {
					$arr[ $key ] = self::sanitize_string( $value );
				}
			}
			return $arr;
		}

		/**
		 * Valida si una cadena es un email válido (alias de is_email).
		 *
		 * @param string $email Email a validar.
		 * @return bool True si es un email válido, false en caso contrario.
		 */
		public static function is_valid_email( string $email ): bool {
			return self::is_email( $email );
		}

		/**
		 * Valida si un valor no está vacío (incluye 0, '0' y false como no vacíos).
		 *
		 * @param mixed $value El valor a comprobar.
		 * @return bool True si no está vacío, false en caso contrario.
		 * @since 1.0.0
		 */
		public static function not_empty( $value ): bool {
			return ! empty( $value );
		}

		/**
		 * Valida si un array contiene únicamente valores enteros.
		 *
		 * @param array $array El array a validar.
		 * @return bool True si todos los elementos son enteros, false en caso contrario.
		 * @since 1.0.0
		 */
		public static function array_of_int( array $array ): bool {
			foreach ( $array as $item ) {
				if ( ! is_int( $item ) ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * Valida si un valor es un email válido.
		 *
		 * @param mixed $email El email a validar.
		 * @return bool True si es un email válido, false en caso contrario.
		 */
		public static function is_email( $email ): bool {
			if ( ! is_string( $email ) ) {
				return false;
			}
			return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
		}

		/**
		 * Valida si un array es asociativo.
		 *
		 * Un array es considerado asociativo si sus claves no son una secuencia numérica de 0 a n-1.
		 *
		 * @param array $array El array a comprobar.
		 * @return bool True si es asociativo, false en caso contrario.
		 */
		public static function is_associative_array( array $array ): bool {
			return array_keys( $array ) !== range( 0, count( $array ) - 1 );
		}

		/**
		 * Alias de is_associative_array para mantener compatibilidad con la clase Validation.
		 *
		 * @param mixed $array El array a comprobar.
		 * @return bool True si es asociativo, false en caso contrario.
		 */
		public static function is_assoc( $array ): bool {
			if ( ! is_array( $array ) ) {
				return false;
			}
			return self::is_associative_array( $array );
		}

		/**
		 * Valida si un valor es una URL válida.
		 *
		 * @param mixed $url URL a validar.
		 * @return bool True si es una URL válida, false en caso contrario.
		 */
		public static function is_url( $url ): bool {
			if ( ! is_string( $url ) ) {
				return false;
			}
			return (bool) filter_var( $url, FILTER_VALIDATE_URL );
		}

		/**
		 * Valida si un string tiene una longitud dentro del rango especificado.
		 *
		 * @param mixed $string String a validar.
		 * @param int   $min    Longitud mínima permitida.
		 * @param int   $max    Longitud máxima permitida.
		 * @return bool True si cumple la longitud, false en caso contrario.
		 */
		public static function validate_length( $string, $min = 0, $max = PHP_INT_MAX ): bool {
			if ( ! is_string( $string ) ) {
				return false;
			}
			$length = mb_strlen( $string );
			return ( $length >= $min && $length <= $max );
		}

		/**
		 * Valida si un número está dentro del rango especificado.
		 *
		 * @param int|float $number Número a validar.
		 * @param int|float $min    Mínimo permitido.
		 * @param int|float $max    Máximo permitido.
		 * @return bool True si está en el rango, false en caso contrario.
		 */
		public static function validate_number_range( $number, $min = PHP_INT_MIN, $max = PHP_INT_MAX ): bool {
			if ( ! is_numeric( $number ) ) {
				return false;
			}
			return ( $number >= $min && $number <= $max );
		}

		/**
		 * Convierte una fecha a un formato específico.
		 *
		 * @param string $date   Fecha a convertir (cualquier formato reconocible por strtotime).
		 * @param string $format Formato de salida (por defecto 'Y-m-d').
		 * @return string Fecha formateada o cadena vacía si es inválida.
		 */
		public static function format_date( $date, $format = 'Y-m-d' ): string {
			if ( empty( $date ) ) {
				return '';
			}
			$timestamp = strtotime( $date );
			if ( $timestamp === false ) {
				return '';
			}
			return date( $format, $timestamp );
		}

		/**
		 * Valida si una fecha es válida según el formato especificado.
		 *
		 * @param string $date   Fecha a validar.
		 * @param string $format Formato esperado (por defecto 'Y-m-d').
		 * @return bool True si la fecha es válida, false en caso contrario.
		 */
		public static function is_valid_date( $date, $format = 'Y-m-d' ): bool {
			if ( ! is_string( $date ) ) {
				return false;
			}
			$d = \DateTime::createFromFormat( $format, $date );
			return $d && $d->format( $format ) === $date;
		}

		/**
		 * Valida si una fecha está en formato YYYY-MM-DD y es válida.
		 *
		 * @param string $date_string Fecha a validar.
		 * @return bool True si es una fecha válida en formato YYYY-MM-DD, false en caso contrario.
		 */
		public static function is_valid_date_format( string $date_string ): bool {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_string ) ) {
				$parts = explode( '-', $date_string );
				// checkdate(month, day, year)
				return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
			}
			return false;
		}

		/**
		 * Valida si una hora está en formato HH:MM o HH:MM:SS y es válida.
		 *
		 * @param string $time_string Hora a validar.
		 * @return bool True si es una hora válida, false en caso contrario.
		 */
		public static function is_valid_time_format( string $time_string ): bool {
			// Permite HH:MM o HH:MM:SS
			if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time_string ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Valida una fecha opcional (vacía o válida en formato YYYY-MM-DD).
		 *
		 * @param string $date_string Fecha a validar.
		 * @return bool True si es vacía o válida, false en caso contrario.
		 */
		public static function is_valid_date_format_optional( string $date_string ): bool {
			return $date_string === '' || self::is_valid_date_format($date_string);
		}

		/**
		 * Valida una hora opcional (vacía o válida en formato HH:MM o HH:MM:SS).
		 *
		 * @param string $time_string Hora a validar.
		 * @return bool True si es vacía o válida, false en caso contrario.
		 */
		public static function is_valid_time_format_optional( string $time_string ): bool {
			return $time_string === '' || self::is_valid_time_format($time_string);
		}

		/**
		 * Genera un hash único (MD5) a partir de cualquier dato (útil para caché, identificadores, etc.).
		 *
		 * @param mixed $data Datos para generar el hash (string, array, objeto, etc.).
		 * @return string Hash único en formato hexadecimal.
		 */
		public static function generate_hash( $data ): string {
			if ( is_array( $data ) || is_object( $data ) ) {
				$data = json_encode( $data );
			}
			return md5( (string) $data );
		}

		/**
		 * Convierte un objeto o array de objetos a un array puro de forma recursiva.
		 *
		 * @param mixed $obj Objeto o array a convertir.
		 * @return array|mixed Array resultante o el valor original si no es objeto/array.
		 */
		public static function object_to_array( $obj ) {
			if ( is_object( $obj ) ) {
				$obj = (array) $obj;
			}
			if ( is_array( $obj ) ) {
				$new = array();
				foreach ( $obj as $key => $val ) {
					$new[ $key ] = self::object_to_array( $val );
				}
			} else {
				$new = $obj;
			}
			return $new;
		}

		/**
		 * Filtra un array para eliminar elementos vacíos (excepto 0, '0' y false, que se consideran válidos).
		 *
		 * @param array $array Array a filtrar.
		 * @return array Array sin elementos vacíos (según la lógica definida).
		 */
		public static function filter_empty( array $array ): array {
			return array_filter(
				$array,
				function ( $value ) {
					return ! empty( $value ) || $value === 0 || $value === '0' || $value === false;
				}
			);
		}

		/**
		 * Convierte un string a snake_case (minúsculas y guiones bajos).
		 *
		 * @param mixed $string String a convertir.
		 * @return string String en snake_case o vacío si no es string.
		 */
		public static function to_snake_case( $string ): string {
			if ( ! is_string( $string ) ) {
				return '';
			}
			$string = preg_replace( '/\s+/', '_', $string );
			$string = strtolower( $string );
			return $string;
		}

		/**
		 * Convierte un string a camelCase.
		 *
		 * @param mixed $string String a convertir.
		 * @param bool  $capitalizeFirst Si se debe capitalizar la primera letra (por defecto false).
		 * @return string String en camelCase o vacío si no es string.
		 */
		public static function to_camel_case( $string, $capitalizeFirst = false ): string {
			if ( ! is_string( $string ) ) {
				return '';
			}
			$string = str_replace( array( '-', '_' ), ' ', $string );
			$string = ucwords( $string );
			$string = str_replace( ' ', '', $string );
			if ( ! $capitalizeFirst ) {
				$string = lcfirst( $string );
			}
					return $string;
	}

	/**
	 * Limpieza automática de transients obsoletos
	 * Elimina transients de sincronización antiguos para evitar acumulación
	 * 
	 * @param int $max_age_hours Edad máxima en horas (por defecto 24h)
	 * @return array Estadísticas de limpieza
	 */
	public static function cleanup_old_sync_transients(int $max_age_hours = 24): array {
		global $wpdb;
		
		$logger = new \MiIntegracionApi\Helpers\Logger('transient-cleanup');
		
		// Estadísticas básicas
		$stats = [
			'cleaned_count' => 0,
			'patterns_processed' => 0,
			'errors' => [],
			'start_time' => time(),
			'duration' => 0
		];
		
		// Patrones de transients a limpiar
		$transient_patterns = [
			'mia_sync_progress',
			'mia_sync_start_time', 
			'mia_sync_cancelada',
			'mia_sync_heartbeat',
			'mia_sync_status',
			'mia_sync_current_%',
			'mia_last_product%',
			'mia_last_client%',
			'mia_last_order%',
			'mia_batch_lock_%',
			'mia_sync_lock_%',
			'mia_api_lock_%',
			'mia_api_cache_%',
			'mia_temp_data_%',
			'mia_debug_%',
			'mia_polling_%',
			'mia_monitor_%',
			'mia_config_temp_%',
			'mia_test_%'
		];
		
		// Bucle de limpieza
		foreach ($transient_patterns as $pattern) {
			$stats['patterns_processed']++;
			
			try {
				if (strpos($pattern, '%') !== false) {
					// Patrón con wildcard - usar consultas SQL
					$like_pattern = str_replace('%', '', $pattern);
					
					// Limpiar transients principales
					$deleted_main = $wpdb->query($wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name LIKE %s",
						'_transient_' . $like_pattern . '%',
						'%' . $like_pattern . '%'
					));
					
					// Limpiar timeouts correspondientes
					$deleted_timeout = $wpdb->query($wpdb->prepare(
						"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name LIKE %s",
						'_transient_timeout_' . $like_pattern . '%',
						'%' . $like_pattern . '%'
					));
					
					$stats['cleaned_count'] += $deleted_main + $deleted_timeout;
				} else {
					// Patrón exacto - usar métodos nativos de WordPress
					$transient_value = get_transient($pattern);
					if ($transient_value !== false) {
						// Verificar edad si se especifica
						if ($max_age_hours > 0) {
							$transient_timeout = get_option("_transient_timeout_{$pattern}");
							if ($transient_timeout) {
								$age_hours = (time() - $transient_timeout) / HOUR_IN_SECONDS;
								if ($age_hours > $max_age_hours) {
									delete_transient($pattern);
									$stats['cleaned_count']++;
								}
							}
						} else {
							delete_transient($pattern);
							$stats['cleaned_count']++;
						}
					}
				}
			} catch (\Exception $e) {
				$error_msg = "Error procesando patrón '{$pattern}': " . $e->getMessage();
				$logger->error($error_msg);
				$stats['errors'][] = $error_msg;
			}
		}
		
		// Calcular duración
		$stats['end_time'] = time();
		$stats['duration'] = $stats['end_time'] - $stats['start_time'];
		
		
		return $stats;
	}

	/**
	 * ✅ HELPER ESTÁTICO: Obtiene un valor de un array con validación y logging contextual
	 * 
	 * Método centralizado para acceso seguro a datos de arrays (especialmente batch_data)
	 * con logging robusto y manejo de errores. Usado por BatchProcessor y Sync_Manager.
	 * 
	 * @param array $data Datos del array (ej. batch_data)
	 * @param string $key Clave a obtener del array
	 * @param mixed $default Valor por defecto si no existe la clave
	 * @param string $context Contexto para logging (debugging)
	 * @param object|null $logger Instancia del logger (opcional)
	 * @return mixed Valor encontrado o valor por defecto
	 * 
	 * @since 2.2.0 Agregado para centralizar acceso a batch_data
	 */
	public static function get_array_value(array $data, string $key, $default = null, string $context = 'unknown', $logger = null): mixed
	{
		// Validación básica del array
		if (empty($data)) {
			return $default;
		}

		// Verificar existencia de la clave
		if (!isset($data[$key])) {
			return $default;
		}

		$value = $data[$key];
		return $value;
	}
}

/**
 * Valida y limpia los filtros recibidos según el tipo de entidad (productos, clientes, pedidos, etc.).
 *
 * Esta función global sanea y valida filtros provenientes de peticiones externas (REST, admin, etc.)
 * para asegurar que los datos sean seguros y tengan el formato esperado antes de ser usados en queries o lógica de negocio.
 * El tipo de filtro determina qué claves se aceptan y cómo se procesan.
 *
 * @param mixed  $raw_filters Filtros originales a validar y limpiar (se espera array, pero puede llegar cualquier tipo).
 * @param string $type        Tipo de filtro a aplicar ('productos_wc', 'productos_verial', 'clientes', 'pedidos', ...).
 * @return array              Array de filtros limpios y validados listos para usar en queries.
 */
function mi_integracion_api_validate_filters( $raw_filters, string $type ): array {
	$clean_filters = array();
	if ( ! is_array( $raw_filters ) ) {
		return $clean_filters; // Devuelve array vacío si la entrada no es un array
	}

	switch ( $type ) {
		case 'productos_wc': // Filtros para productos de WooCommerce
			if ( isset( $raw_filters['min_price'] ) ) {
				$clean_filters['min_price'] = floatval( $raw_filters['min_price'] );
			}
			if ( isset( $raw_filters['max_price'] ) ) {
				$clean_filters['max_price'] = floatval( $raw_filters['max_price'] );
			}
			if ( isset( $raw_filters['min_stock'] ) ) {
				$clean_filters['min_stock'] = intval( $raw_filters['min_stock'] );
			}
			if ( isset( $raw_filters['max_stock'] ) ) {
				$clean_filters['max_stock'] = intval( $raw_filters['max_stock'] );
			}
			if ( isset( $raw_filters['search'] ) ) {
				$clean_filters['search'] = sanitize_text_field( wp_unslash( $raw_filters['search'] ) );
			}
			if ( isset( $raw_filters['category'] ) ) {
				// Puede ser un ID único o un array de IDs/slugs
				$clean_filters['category'] = is_array( $raw_filters['category'] )
					? array_map( 'sanitize_text_field', $raw_filters['category'] )
					: sanitize_text_field( $raw_filters['category'] );
			}
			if ( isset( $raw_filters['status'] ) ) {
				$clean_filters['status'] = is_array( $raw_filters['status'] )
					? array_map( 'sanitize_key', $raw_filters['status'] )
					: sanitize_key( $raw_filters['status'] );
			}
			break;

		case 'productos_verial': // Filtros para productos de Verial (usados por BatchProcessor)
			// Las claves aquí deben coincidir con las que envía el JS desde el formulario del panel de admin.
			// Se mapean a las claves que espera `filter_verial_products_locally` en BatchProcessor.
			if ( ! empty( $raw_filters['nombre_producto_verial'] ) ) {
				$clean_filters['Nombre'] = sanitize_text_field( wp_unslash( $raw_filters['nombre_producto_verial'] ) );
			}
			if ( isset( $raw_filters['id_categoria_verial'] ) && is_numeric( $raw_filters['id_categoria_verial'] ) ) {
				// La clave real en los datos de Verial podría ser 'ID_Categoria' o 'ID_CategoriaWeb1', etc.
				// BatchProcessor debe saber cómo usar esto. Aquí solo se sanea el valor.
				$clean_filters['id_categoria_verial_filter'] = absint( $raw_filters['id_categoria_verial'] );
			}
			if ( isset( $raw_filters['id_fabricante_verial'] ) && is_numeric( $raw_filters['id_fabricante_verial'] ) ) {
				$clean_filters['id_fabricante_verial_filter'] = absint( $raw_filters['id_fabricante_verial'] );
			}
			// Para precios, se asume que el JS envía 'precio_min_verial' y 'precio_max_verial'.
			// BatchProcessor usará estos para filtrar contra el campo de precio real de Verial.
			if ( isset( $raw_filters['precio_min_verial'] ) && is_numeric( $raw_filters['precio_min_verial'] ) ) {
				$clean_filters['precio_min_verial_filter'] = floatval( $raw_filters['precio_min_verial'] );
			}
			if ( isset( $raw_filters['precio_max_verial'] ) && is_numeric( $raw_filters['precio_max_verial'] ) ) {
				$clean_filters['precio_max_verial_filter'] = floatval( $raw_filters['precio_max_verial'] );
			}
			break;

		case 'clientes': // Filtros para clientes/usuarios de WordPress
			if ( isset( $raw_filters['search'] ) ) {
				$clean_filters['search'] = sanitize_text_field( wp_unslash( $raw_filters['search'] ) );
			}
			if ( isset( $raw_filters['email'] ) ) {
				$clean_filters['email'] = sanitize_email( wp_unslash( $raw_filters['email'] ) );
			}
			if ( isset( $raw_filters['registered_after'] ) ) {
				$clean_filters['registered_after'] = Utils::is_valid_date_format( $raw_filters['registered_after'] )
					? $raw_filters['registered_after']
					: '';
			}
			if ( isset( $raw_filters['registered_before'] ) ) {
				$clean_filters['registered_before'] = Utils::is_valid_date_format( $raw_filters['registered_before'] )
					? $raw_filters['registered_before']
					: '';
			}
			if ( isset( $raw_filters['role'] ) ) {
				$clean_filters['role'] = is_array( $raw_filters['role'] )
					? array_map( 'sanitize_key', $raw_filters['role'] )
					: sanitize_key( $raw_filters['role'] );
			}
			break;

		case 'pedidos': // Filtros para pedidos de WooCommerce
			if ( isset( $raw_filters['customer_id'] ) ) {
				$clean_filters['customer_id'] = absint( $raw_filters['customer_id'] );
			}
			if ( isset( $raw_filters['status'] ) ) {
				$clean_filters['status'] = is_array( $raw_filters['status'] )
					? array_map( 'sanitize_key', $raw_filters['status'] )
					: sanitize_key( $raw_filters['status'] );
			}
			if ( isset( $raw_filters['min_total'] ) ) {
				$clean_filters['min_total'] = floatval( $raw_filters['min_total'] );
			}
			if ( isset( $raw_filters['max_total'] ) ) {
				$clean_filters['max_total'] = floatval( $raw_filters['max_total'] );
			}
			if ( isset( $raw_filters['date_after'] ) ) {
				$clean_filters['date_after'] = Utils::is_valid_date_format( $raw_filters['date_after'] )
					? $raw_filters['date_after']
					: '';
			}
			if ( isset( $raw_filters['date_before'] ) ) {
				$clean_filters['date_before'] = Utils::is_valid_date_format( $raw_filters['date_before'] )
					? $raw_filters['date_before']
					: '';
			}
			if ( isset( $raw_filters['search'] ) ) {
				$clean_filters['search'] = sanitize_text_field( wp_unslash( $raw_filters['search'] ) );
			}
			break;

		default:
	}

	return $clean_filters;
	}
