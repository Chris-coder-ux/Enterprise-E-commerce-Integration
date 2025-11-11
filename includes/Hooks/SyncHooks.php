<?php

declare(strict_types=1);

namespace MiIntegracionApi\Hooks;

use MiIntegracionApi\Hooks\HooksManager;
use MiIntegracionApi\Hooks\HookPriorities;

/**
 * Módulo de sincronización para la integración con WooCommerce
 *
 * Este archivo contiene la implementación de hooks relacionados con la sincronización
 * bidireccional entre WooCommerce y el sistema Verial, incluyendo:
 * - Mapeo de datos de productos, clientes y pedidos
 * - Sincronización programada y en tiempo real
 * - Manejo de eventos de WooCommerce
 *
 * @package    MiIntegracionApi
 * @subpackage Hooks
 * @category   Core
 * @since      1.0.0
 * @author     [Autor]
 * @link       [URL del plugin]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar la sincronización con WooCommerce
 *
 * Esta clase maneja toda la lógica de sincronización entre WooCommerce y Verial,
 * incluyendo el mapeo de datos, sincronización programada y manejo de eventos.
 *
 * @see HooksManager
 * @see HookPriorities
 */
class SyncHooks {
    /**
     * Registra todos los hooks de sincronización
     *
     * Este método inicializa todos los filtros y acciones necesarios para:
     * - Mapeo de datos (productos, clientes, pedidos)
     * - Manejo de eventos post-sincronización
     * - Integración con WooCommerce
     *
     * @return void
     * @hook plugins_loaded - Se ejecuta cuando todos los plugins están cargados
     */
    public static function register_hooks() {
		add_filter(
			'mi_integracion_api_map_product_data',
			[self::class, 'map_product_data'],
			10,
			2
		);
		add_filter(
			'mi_integracion_api_map_customer_data',
			[self::class, 'map_customer_data'],
			10,
			2
		);
		add_filter(
			'mi_integracion_api_map_order_data',
			[self::class, 'map_order_data'],
			10,
			2
		);
		add_action(
			'mi_integracion_api_after_sync_product',
			[self::class, 'after_sync_product'],
			10,
			2
		);
		add_action(
			'mi_integracion_api_after_sync_customer',
			[self::class, 'after_sync_customer'],
			10,
			2
		);
		add_action(
			'mi_integracion_api_after_sync_order',
			[self::class, 'after_sync_order'],
			10,
			2
		);
	}
	
    /**
     * ===========================================
     * GESTIÓN DE HOOKS WOOCOMMERCE
     * ===========================================
     * 
     * Los siguientes métodos manejan la integración con WooCommerce,
     * incluyendo sincronización automática y programada.
     * 
     * @see register_woocommerce_hooks()
     * @see register_scheduled_sync()
     * @see run_daily_sync()
     */
    
    /**
     * Registra los hooks de WooCommerce para sincronización automática
     * 
     * Este método configura los siguientes hooks:
     * - Cron job para sincronización diaria
     * - Hooks para eventos de productos en WooCommerce
     * - Manejo de actualizaciones y creaciones
     *
     * @return void
     * @hook init - Se ejecuta durante la inicialización de WordPress
     */
	public static function register_woocommerce_hooks(): void {
		// Hook de inicialización para registrar cron
		HooksManager::add_action(
			'init',
			[self::class, 'register_scheduled_sync'],
			HookPriorities::get('INIT', 'DEFAULT')
		);
		
		// Hook de sincronización diaria eliminado - solo sincronización manual
		
		// Hooks de productos WooCommerce (solo si WC está activo)
		HooksManager::add_wc_action(
			'woocommerce_update_product',
			[self::class, 'on_product_updated'],
			10,
			1
		);
		
		HooksManager::add_wc_action(
			'woocommerce_new_product',
			[self::class, 'on_product_created'],
			10,
			1
		);
		
		HooksManager::add_wc_action(
			'woocommerce_trash_product',
			[self::class, 'on_product_deleted'],
			10,
			1
		);
	}
	
	/**
	 * Registra el cron job para sincronización diaria
	 * CORRECCIÓN: Los cron jobs se manejan centralmente en RobustnessHooks
	 * para evitar múltiples cargas del plugin
	 * 
	 * @return void
	 */
	public static function register_scheduled_sync(): void {
		// CORRECCIÓN: La programación del cron se maneja centralmente en RobustnessHooks
		// para evitar múltiples cargas del plugin
		// Este método se mantiene por compatibilidad pero no programa cron
		
		if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-hooks');
			$logger->debug('Verificación de cron job de sincronización - manejado por RobustnessHooks');
			
			// Verificar estado del cron job
			$next_scheduled = wp_next_scheduled('mi_integracion_api_daily_sync');
			if ($next_scheduled) {
				$logger->info('Cron job de sincronización diaria programado correctamente', [
					'next_run' => date('Y-m-d H:i:s', $next_scheduled)
				]);
			} else {
				$logger->warning('Cron job de sincronización diaria no está programado');
			}
		}
	}
	
	/**
	 * Ejecuta la sincronización diaria via cron
	 * Reemplaza run_daily_sync() del SyncManager legacy
	 * 
	 * @return void
	 */
	// Método run_daily_sync eliminado - solo sincronización manual
	
	/**
	 * Maneja la actualización de un producto en WooCommerce
	 *
	 * Este método se dispara cuando un producto es actualizado en WooCommerce
	 * y desencadena la sincronización con Verial si corresponde.
	 *
	 * @param int $product_id ID del producto actualizado en WooCommerce
	 * @return void
	 * @hook woocommerce_update_product - Se ejecuta al actualizar un producto
	 * @see MapProduct::get_verial_id_by_wc_id()
	 */
	public static function on_product_updated(int $product_id): void {
		// Verificar que el producto existe
		$product = wc_get_product($product_id);
		if (!$product) {
			return;
		}
		
		// Verificar si el producto tiene un Verial ID asociado
		$verial_id = null;
		if (class_exists('\MiIntegracionApi\Helpers\MapProduct')) {
			$verial_id = \MiIntegracionApi\Helpers\MapProduct::get_verial_id_by_wc_id($product_id);
		}
		
		if ($verial_id) {
			// Trigger evento personalizado para productos sincronizados
			do_action('mi_integracion_api_wc_product_updated', $product_id, $verial_id);
		} else {
			// NUEVO: Notificar productos no sincronizados
			do_action('mi_integracion_api_wc_product_updated', $product_id, null);
			self::handle_product_notification('updated', $product_id, $product, $verial_id);
		}
	}
	
	/**
	 * Maneja la creación de un nuevo producto en WooCommerce
	 * Reemplaza on_product_created() del SyncManager legacy
	 * 
	 * @param int $product_id ID del producto creado
	 * @return void
	 */
	public static function on_product_created(int $product_id): void {
		// Verificar que el producto existe
		$product = wc_get_product($product_id);
		if (!$product) {
			return;
		}
		
		// Trigger evento personalizado para que otros sistemas reaccionen
		do_action('mi_integracion_api_wc_product_created', $product_id);
		
		// NUEVO: Integrar con el sistema de notificaciones
		self::handle_product_notification('created', $product_id, $product);
	}
	
	/**
	 * Maneja la eliminación de un producto en WooCommerce
	 * 
	 * @param int $product_id ID del producto eliminado
	 * @return void
	 */
	public static function on_product_deleted(int $product_id): void {
		// Trigger evento personalizado para que otros sistemas reaccionen
		do_action('mi_integracion_api_wc_product_deleted', $product_id);
		
		// NUEVO: Integrar con el sistema de notificaciones
		self::handle_product_notification('deleted', $product_id, null);
	}
	
	/**
	 * Maneja las notificaciones de productos de forma centralizada
	 * 
	 * @param string $action Acción realizada (created, updated, deleted)
	 * @param int $product_id ID del producto
	 * @param \WC_Product|null $product Objeto del producto (null para eliminados)
	 * @param int|null $verial_id ID de Verial si existe
	 * @return void
	 */
	private static function handle_product_notification(
		string $action, 
		int $product_id, 
		?\WC_Product $product = null, 
		?int $verial_id = null
	): void {
		// Verificar que el sistema de notificaciones esté disponible
		if (!class_exists('MiIntegracionApi\\Deteccion\\WooCommerceProductNotifier')) {
			return;
		}
		
		try {
			// Obtener instancia del notificador
			$notifier = new \MiIntegracionApi\Deteccion\WooCommerceProductNotifier();
			
			// Delegar al notificador según la acción
			switch ($action) {
				case 'created':
					$notifier->handle_new_product($product_id);
					break;
					
				case 'updated':
					$notifier->handle_product_update($product_id, $verial_id);
					break;
					
				case 'deleted':
					$notifier->handle_product_delete($product_id);
					break;
			}
			
		} catch (\Throwable $e) {
			// Log del error sin interrumpir el flujo principal
			if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync_hooks');
				$logger->error('Error en sistema de notificaciones de productos', [
					'action' => $action,
					'product_id' => $product_id,
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString()
				]);
			}
		}
	}
	
	/**
	 * Desregistra los hooks de cron al desactivar el plugin
	 * 
	 * @return void
	 */
	public static function unregister_scheduled_sync(): void {
		// Eliminar todos los eventos programados
		wp_clear_scheduled_hook('mi_integracion_api_daily_sync');
	}

	public static function map_product_data($data, $wc_product) {
		return $data;
	}
	public static function map_customer_data($data, $user) {
		return $data;
	}
	public static function map_order_data($data, $order) {
		return $data;
	}
	public static function after_sync_product($wc_product_id, $verial_response) {
		// ...
	}
	public static function after_sync_customer($user_id, $verial_response) {
		// ...
	}
	public static function after_sync_order($order_id, $verial_response) {
		// ...
	}
}

// Registrar los hooks al cargar el archivo
SyncHooks::register_hooks();

// Registrar también los hooks de WooCommerce (cuando corresponda)
add_action('plugins_loaded', function() {
	// Solo registrar si las clases necesarias existen
	if (class_exists('\MiIntegracionApi\Hooks\HooksManager')) {
		SyncHooks::register_woocommerce_hooks();
	}
}, 15); // Prioridad 15 para asegurar que WC esté cargado
