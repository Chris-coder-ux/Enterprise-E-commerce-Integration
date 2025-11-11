<?php declare(strict_types=1);
/**
 * Helper para manejo seguro de metadatos
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase de utilidades para el manejo seguro y compatible de metadatos de usuarios, posts y pedidos.
 *
 * Proporciona métodos estáticos para obtener, actualizar y eliminar metadatos de forma segura,
 * incluyendo compatibilidad con HPOS (High-Performance Order Storage) en WooCommerce.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */
class MetaHelper {
    /**
     * Obtiene un metadato de forma segura para usuarios, posts o pedidos.
     *
     * Si el tipo es 'order' y existe la clase de compatibilidad HPOS, utiliza el método especializado;
     * en caso contrario, recurre a las funciones estándar de WordPress.
     *
     * @param int    $object_id ID del usuario, post o pedido.
     * @param string $meta_key  Clave del metadato a obtener.
     * @param string $type      Tipo de objeto: 'user', 'post' o 'order'.
     * @param mixed  $default   Valor por defecto si no existe el metadato.
     * @return mixed            Valor del metadato o el valor por defecto si no existe.
     */
    public static function get_meta_safe( $object_id, $meta_key, $type = 'user', $default = '' ) {
        if ( $type === 'user' ) {
            $value = get_user_meta( $object_id, $meta_key, true );
        } elseif ( $type === 'order' ) {
            // Usar la clase de compatibilidad con HPOS para pedidos
            if ( class_exists( 'MI_WC_HPOS_Compatibility' ) ) {
                $value = \MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'get', $object_id, $meta_key );
            } else {
                // Fallback si la clase no está disponible
                $value = get_post_meta( $object_id, $meta_key, true );
            }
        } else {
            $value = get_post_meta( $object_id, $meta_key, true );
        }
        return ( isset( $value ) && $value !== '' ) ? $value : $default;
    }

    /**
     * Actualiza un metadato de forma segura para usuarios, posts o pedidos.
     *
     * Si el tipo es 'order' y existe la clase de compatibilidad HPOS, utiliza el método especializado;
     * en caso contrario, recurre a las funciones estándar de WordPress.
     *
     * @param int    $object_id  ID del objeto (usuario, post o pedido).
     * @param string $meta_key   Clave del metadato a actualizar.
     * @param mixed  $meta_value Valor a guardar en el metadato.
     * @param string $type       Tipo de objeto: 'user', 'post' o 'order'.
     * @return bool|int          True/int si se actualizó correctamente, false en caso contrario.
     */
    public static function update_meta_safe( $object_id, $meta_key, $meta_value, $type = 'user' ) {
        if ( $type === 'user' ) {
            return update_user_meta( $object_id, $meta_key, $meta_value );
        } elseif ( $type === 'order' ) {
            // Usar la clase de compatibilidad con HPOS para pedidos
            if ( class_exists( 'MI_WC_HPOS_Compatibility' ) ) {
                return \MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $object_id, $meta_key, $meta_value );
            }
            // Fallback si la clase no está disponible
            return update_post_meta( $object_id, $meta_key, $meta_value );
        }

        return update_post_meta( $object_id, $meta_key, $meta_value );
    }

    /**
     * Elimina un metadato de forma segura para usuarios, posts o pedidos.
     *
     * Si el tipo es 'order' y existe la clase de compatibilidad HPOS, utiliza el método especializado;
     * en caso contrario, recurre a las funciones estándar de WordPress.
     *
     * @param int    $object_id ID del objeto (usuario, post o pedido).
     * @param string $meta_key  Clave del metadato a eliminar.
     * @param string $type      Tipo de objeto: 'user', 'post' o 'order'.
     * @return bool             True si se eliminó correctamente, false en caso contrario.
     */
    public static function delete_meta_safe( $object_id, $meta_key, $type = 'user' ) {
        if ( $type === 'user' ) {
            return delete_user_meta( $object_id, $meta_key );
        } elseif ( $type === 'order' ) {
            // Usar la clase de compatibilidad con HPOS para pedidos
            if ( class_exists( 'MI_WC_HPOS_Compatibility' ) ) {
                return \MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'delete', $object_id, $meta_key );
            }
            // Fallback si la clase no está disponible
            return delete_post_meta( $object_id, $meta_key );
        }

        return delete_post_meta( $object_id, $meta_key );
    }
}
