<?php declare(strict_types=1);
/**
 * Definiciones centralizadas de argumentos comunes para endpoints.
 * @package MiIntegracionApi\Helpers
 */

namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Definiciones centralizadas de argumentos comunes para endpoints REST.
 *
 * Proporciona métodos estáticos para obtener la definición de argumentos estándar reutilizables
 * en los endpoints de la API (por ejemplo: sesión, contexto, forzar refresco, etc.).
 *
 * @package MiIntegracionApi\Helpers
 */
class EndpointArgs {
	/**
	 * Devuelve la definición del argumento común 'sesionwcf' para endpoints.
	 *
	 * @return array Definición del argumento 'sesionwcf'.
	 */
	public static function sesionwcf(): array {
		return [
			'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		];
	}

	/**
	 * Devuelve la definición del argumento común 'context' para endpoints.
	 *
	 * @return array Definición del argumento 'context'.
	 */
	public static function context(): array {
		return [
			'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
			'type'        => 'string',
			'enum'        => [ 'view', 'embed', 'edit' ],
			'default'     => 'view',
		];
	}

	/**
	 * Devuelve la definición del argumento común 'force_refresh' para endpoints.
	 *
	 * @return array Definición del argumento 'force_refresh'.
	 */
	public static function force_refresh(): array {
		return [
			'description'       => __( 'Forzar la actualización de la caché.', 'mi-integracion-api' ),
			'type'              => 'boolean',
			'required'          => false,
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		];
	}

	// Agregar aquí otros argumentos comunes (paginación, filtros, etc.)
}
