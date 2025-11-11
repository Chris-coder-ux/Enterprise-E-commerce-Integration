<?php
/**
 * Comandos WP-CLI para diagnóstico de sincronización
 *
 * @package MiIntegracionApi\Cli
 */

namespace MiIntegracionApi\Cli;

use WP_CLI;
use MiIntegracionApi\Core\Sync_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Comandos WP-CLI para diagnóstico y reparación del estado de sincronización
 */
class SyncDiagnosticCommand {

	/**
	 * Muestra el estado actual de sincronización
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Formato de salida (table, json, csv, yaml, count). Por defecto: table
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Campos a mostrar (separados por comas)
	 *
	 * ## EXAMPLES
	 *
	 *     wp verial sync status
	 *     wp verial sync status --format=json
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$manager = Sync_Manager::get_instance();
		$status = $manager->getSyncStatus();
		
		// Formatear para salida
		$formatted = [
			'Estado' => $status['current_sync']['in_progress'] ? 'En progreso' : 'Inactivo',
			'Entidad' => $status['current_sync']['entity'] ?: 'N/A',
			'Dirección' => $status['current_sync']['direction'] ?: 'N/A',
			'Lote actual' => sprintf('%d de %d', 
				$status['current_sync']['current_batch'], 
				$status['current_sync']['total_batches']
			),
			'Elementos' => sprintf('%d de %d', 
				$status['current_sync']['items_synced'], 
				$status['current_sync']['total_items']
			),
			'Errores' => $status['current_sync']['errors'],
			'ID Operación' => $status['current_sync']['operation_id'] ?: 'N/A',
			'Inicio' => $status['current_sync']['start_time'] 
				? date('Y-m-d H:i:s', $status['current_sync']['start_time']) 
				: 'N/A',
			'Última actualización' => $status['current_sync']['last_update'] 
				? date('Y-m-d H:i:s', $status['current_sync']['last_update']) 
				: 'N/A',
		];

		// Mostrar en formato tabla
		if ('table' === $assoc_args['format']) {
			WP_CLI\Utils\format_items(
				$assoc_args['format'],
				[ $formatted ],
				array_keys($formatted)
			);
		} else {
			WP_CLI\Utils\format_items(
				$assoc_args['format'],
				[ $formatted ],
				$assoc_args['fields'] ? explode(',', $assoc_args['fields']) : array_keys($formatted)
			);
		}
	}

	/**
	 * Ejecuta un diagnóstico del estado de sincronización
	 *
	 * ## OPTIONS
	 *
	 * [--repair]
	 * : Intenta reparar automáticamente los problemas encontrados
	 *
	 * [--format=<format>]
	 * : Formato de salida (table, json). Por defecto: table
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Diagnóstico simple
	 *     wp verial sync diagnostic
	 *
	 *     # Diagnóstico con reparación automática
	 *     wp verial sync diagnostic --repair
	 *
	 *     # Salida en formato JSON
	 *     wp verial sync diagnostic --format=json
	 *
	 * @when after_wp_load
	 */
	public function diagnostic( $args, $assoc_args ) {
		$manager = Sync_Manager::get_instance();
		$reparar = isset($assoc_args['repair']) && $assoc_args['repair'];
		
		WP_CLI::line(sprintf(
			'Iniciando diagnóstico%s...',
			$reparar ? ' con reparación automática' : ''
		));
		
		$resultado = $manager->diagnostico_y_reparacion_estado($reparar);
		
		// Manejar errores
		if (isset($resultado['error'])) {
			WP_CLI::error($resultado['error']);
			return;
		}
		
		// Mostrar resumen
		$total_problemas = count($resultado['problemas_detectados']);
		$total_acciones = count($resultado['acciones_realizadas']);
		
		if ('json' === $assoc_args['format']) {
			WP_CLI::line(json_encode($resultado, JSON_PRETTY_PRINT));
			return;
		}
		
		// Mostrar problemas
		if ($total_problemas > 0) {
			WP_CLI::warning(sprintf('Se encontraron %d problema(s):', $total_problemas));
			foreach ($resultado['problemas_detectados'] as $i => $problema) {
				WP_CLI::line(sprintf('  %d. %s', $i + 1, $problema));
			}
		} else {
			WP_CLI::success('No se encontraron problemas en el estado de sincronización.');
		}
		
		// Mostrar acciones realizadas
		if ($total_acciones > 0) {
			WP_CLI::line("\n" . sprintf('Se realizaron %d acción(es):', $total_acciones));
			foreach ($resultado['acciones_realizadas'] as $i => $accion) {
				WP_CLI::line(sprintf('  %d. %s', $i + 1, $accion));
			}
		}
		
		// Mostrar resumen final
		$estado = $resultado['estado_actual'];
		WP_CLI::line("\n" . 'Resumen del estado actual:');
		WP_CLI::line(sprintf('  - Estado: %s', $estado['in_progress'] ? 'En progreso' : 'Inactivo'));
		WP_CLI::line(sprintf('  - Lote: %d/%d', $estado['current_batch'], $estado['total_batches']));
		WP_CLI::line(sprintf('  - Elementos: %d/%d', $estado['items_synced'], $estado['total_items']));
		WP_CLI::line(sprintf('  - Errores: %d', $estado['errors']));
		
		// Mostrar mensaje final
		if ($total_problemas > 0 && !$reparar) {
			WP_CLI::warning('Se detectaron problemas. Ejecuta con --repair para intentar solucionarlos automáticamente.');
		} elseif ($reparar) {
			WP_CLI::success('Diagnóstico y reparación completados.');
		} else {
			WP_CLI::success('Diagnóstico completado.');
		}
	}

	/**
	 * Restaura el estado de sincronización desde un backup
	 *
	 * ## OPTIONS
	 *
	 * [--backup=<backup_key>]
	 * : Clave del backup a restaurar. Si no se especifica, se usará el más reciente
	 *
	 * [--list]
	 * : Lista los backups disponibles sin restaurar
	 *
	 * ## EXAMPLES
	 *
	 *     # Listar backups disponibles
	 *     wp verial sync restore --list
	 *
	 *     # Restaurar el backup más reciente
	 *     wp verial sync restore
	 *
	 *     # Restaurar un backup específico
	 *     wp verial sync restore --backup=backup_1234567890
	 *
	 * @when after_wp_load
	 */
	public function restore($args, $assoc_args) {
		$manager = Sync_Manager::get_instance();
		
		// Listar backups disponibles
		if (isset($assoc_args['list'])) {
			$backups = $manager->get_available_backups();
			
			if (empty($backups)) {
				WP_CLI::warning('No se encontraron backups disponibles.');
				return;
			}
			
			WP_CLI::line('Backups disponibles:');
			
			$items = [];
			foreach ($backups as $key => $timestamp) {
				$items[] = [
					'Clave' => $key,
					'Fecha' => date('Y-m-d H:i:s', $timestamp),
					'Antigüedad' => human_time_diff($timestamp) . ' atrás'
				];
			}
			
			WP_CLI\Utils\format_items('table', $items, ['Clave', 'Fecha', 'Antigüedad']);
			return;
		}
		
		// Restaurar backup
		$backup_key = $assoc_args['backup'] ?? null;
		
		if ($backup_key) {
			WP_CLI::line(sprintf('Restaurando backup: %s', $backup_key));
			$result = $manager->restore_sync_status($backup_key);
		} else {
			WP_CLI::line('Restaurando el backup más reciente...');
			$result = $manager->restore_latest_backup();
		}
		
		if ($result) {
			WP_CLI::success('Backup restaurado correctamente.');
			
			// Mostrar estado actual
			WP_CLI::line("\n" . 'Estado actual después de la restauración:');
			$this->status([], ['format' => 'table']);
		} else {
			WP_CLI::error('No se pudo restaurar el backup. Verifica que la clave sea correcta o que existan backups disponibles.');
		}
	}
}

// Registrar el comando
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('verial sync', 'MiIntegracionApi\\Cli\\SyncDiagnosticCommand');
}
