<?php declare(strict_types=1);
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para filtrado avanzado de clientes en batch.
 *
 * Permite filtrar clientes por email, nombre, fecha de registro, rol, búsqueda, etc.
 * Uso: FilterCustomers::advanced( $args )
 *
 * @package MiIntegracionApi\Helpers
 */
class FilterCustomers {
	/**
	 * Filtra clientes de WordPress/WooCommerce según criterios avanzados.
	 *
	 * @param array $args Argumentos de filtrado:
	 *   - search (string): Búsqueda general (nombre, email, etc.).
	 *   - email (string): Filtrar por email exacto.
	 *   - role (string|array): Rol o roles de usuario.
	 *   - registered_after (string): Fecha mínima de registro (Y-m-d).
	 *   - registered_before (string): Fecha máxima de registro (Y-m-d).
	 *   - limit (int): Límite de resultados.
	 *   - offset (int): Desplazamiento de resultados.
	 *   - orderby (string): Campo de ordenación.
	 *   - order (string): Dirección de ordenación (ASC|DESC).
	 * @return array IDs de usuarios que cumplen los filtros.
	 */
	public static function advanced( array $args = [] ): array {
		$defaults   = array(
			'search'            => '',
			'email'             => '',
			'role'              => 'customer',
			'registered_after'  => '',
			'registered_before' => '',
			'limit'             => 100,
			'offset'            => 0,
			'orderby'           => 'ID',
			'order'             => 'ASC',
		);
		$args       = wp_parse_args( $args, $defaults );
		$query_args = array(
			'role'    => $args['role'],
			'number'  => $args['limit'],
			'offset'  => $args['offset'],
			'orderby' => $args['orderby'],
			'order'   => $args['order'],
			'fields'  => 'ID',
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['search'] = '*' . esc_attr( $args['search'] ) . '*';
		}
		if ( ! empty( $args['email'] ) ) {
			$query_args['search']         = $args['email'];
			$query_args['search_columns'] = array( 'user_email' );
		}
		if ( ! empty( $args['registered_after'] ) ) {
			$query_args['date_query'][] = array(
				'after'     => $args['registered_after'],
				'inclusive' => true,
			);
		}
		if ( ! empty( $args['registered_before'] ) ) {
			$query_args['date_query'][] = array(
				'before'    => $args['registered_before'],
				'inclusive' => true,
			);
		}
		$user_query = new \WP_User_Query( $query_args );
		return $user_query->get_results();
	}
}
