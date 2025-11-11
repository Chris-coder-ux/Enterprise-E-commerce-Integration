<?php
declare(strict_types=1);

namespace MiIntegracionApi\Tools;

use WP_CLI; // phpcs:ignore
use WP_CLI_Command; // phpcs:ignore

if (!class_exists('WP_CLI')) {
    return;
}

/**
 * Comandos WP-CLI para sincronizaciones Verial <-> WooCommerce.
 *
 * Uso básico:
 *   wp verial sync products --direction=verial_to_wc --batch-size=50
 *   wp verial sync status
 *   wp verial sync resume
 *   wp verial sync cancel
 */
class SyncCommands extends WP_CLI_Command {
    /**
     * Lanza una sincronización.
     *
     * ## OPTIONS
     *
     * <entity>
     * : Entidad a sincronizar (products|orders)
     *
     * [--direction=<direction>]
     * : Dirección (verial_to_wc|wc_to_verial). Por defecto: verial_to_wc.
     *
     * [--batch-size=<n>]
     * : Tamaño de lote (override temporal).
     *
     * [--dry-run]
     * : No persiste cambios (simula). (Pendiente futura implementación)
     *
     * [--json]
     * : Salida en JSON.
     *
     * [--metrics]
     * : Incluir métricas de rendimiento en la salida.
     *
     * ## EXAMPLES
     *   wp verial sync products --batch-size=25
     *   wp verial sync products --direction=wc_to_verial --json
     */
    public function sync(array $args, array $assoc_args) {
        list($entity) = $args;
        $direction = $assoc_args['direction'] ?? 'verial_to_wc';
        $batchSize = isset($assoc_args['batch-size']) ? (int)$assoc_args['batch-size'] : null;
        $asJson = isset($assoc_args['json']);
        $dryRun = isset($assoc_args['dry-run']);
    $withMetrics = isset($assoc_args['metrics']);

        // Validación básica de entorno (WooCommerce y funciones esenciales)
        $envIssues = [];
        if (!function_exists('wc_get_products')) {
            $envIssues[] = 'WooCommerce no parece activo (falta wc_get_products)';
        }
        if (!function_exists('get_option')) {
            $envIssues[] = 'Funciones base de WordPress no disponibles (get_option)';
        }
        if ($envIssues) {
            $msg = 'Validación de entorno fallida: '.implode('; ', $envIssues);
            if ($asJson) {
                WP_CLI::line(json_encode(['success'=>false,'error'=>$msg,'issues'=>$envIssues], JSON_PRETTY_PRINT));
                return;
            }
            WP_CLI::error($msg);
        }

        // Validar entidad y dirección usando SyncEntityValidator unificado
        try {
            \MiIntegracionApi\Core\Validation\SyncEntityValidator::validateEntityAndDirection($entity, $direction);
		} catch ( \MiIntegracionApi\ErrorHandling\Exceptions\SyncError $e ) {
            WP_CLI::error('Error de validación: ' . $e->getMessage());
        }

        $syncManager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        $status = $syncManager->getSyncStatus();

        if ($status['current_sync']['in_progress']) {
            WP_CLI::warning('Ya hay una sincronización en curso. Use: wp verial sync continue');
            if ($asJson) {
                WP_CLI::line(json_encode(['status'=>'in_progress','current'=>$status['current_sync']], JSON_PRETTY_PRINT));
            }
            return;
        }

        // Override batch size si se pasa explícito
        if ($batchSize !== null) {
            \MiIntegracionApi\Helpers\BatchSizeHelper::setBatchSize($entity === 'products' ? 'productos' : $entity, $batchSize);
        }

        // Validación previa (incluye prueba de conexión y conteo) siempre si dry-run o si se solicita métrica
        $preValidation = $syncManager->validate_sync_prerequisites($entity, $direction, []);
        if (!$preValidation->isSuccess()) {
            if ($dryRun) {
                // En dry-run devolvemos las incidencias sin abortar el proceso (informativo)
                WP_CLI::warning('Validación previa falló (dry-run): ' . $preValidation->getMessage());
            } else {
                // Abortamos en ejecución real
                $msg = 'Fallo validación previa: ' . $preValidation->getMessage();
                if ($asJson) {
                    WP_CLI::line(json_encode([
                        'success' => false,
                        'error' => $msg,
                        'validation' => $preValidation->toArray()
                    ], JSON_PRETTY_PRINT));
                    return;
                }
                WP_CLI::error($msg);
            }
        }

        if ($dryRun) {
            // Dry-run real: usamos resultados de validación (count_test / item_count)
            $estimated = 0;
            if (isset($preValidation['item_count'])) {
                $estimated = (int)$preValidation['item_count'];
            } else {
                // Intento de conteo universal via preview_count
                if (method_exists($syncManager, 'preview_count')) {
                    $preview = $syncManager->preview_count($entity, $direction, []);
                    if (!is_wp_error($preview)) {
                        $estimated = (int)$preview;
                    }
                }
            }
            $sim = [
                'status' => 'dry_run',
                'entity' => $entity,
                'direction' => $direction,
                'estimated_total' => $estimated,
                'batch_size' => $batchSize ?? 'default',
                'validation' => $preValidation
            ];
            if ($asJson) {
                WP_CLI::line(json_encode($sim, JSON_PRETTY_PRINT));
            } else {
                WP_CLI::log('Dry-run completado. Estimado de elementos: '.$estimated);
                if (!empty($preValidation['warnings'])) {
                    WP_CLI::warning('Advertencias: '.implode(' | ', $preValidation['warnings']));
                }
                if (!empty($preValidation['issues'])) {
                    WP_CLI::warning('Incidencias: '.implode(' | ', $preValidation['issues']));
                }
            }
            return;
        }

        $result = $syncManager->start_sync($entity, $direction, []);
        if (!$result->isSuccess()) {
            WP_CLI::error('Fallo al iniciar: '.$result->getMessage());
        }
        $out = [
            'operation_id' => $result->getDataValue('operation_id'),
            'total_items' => $result->getDataValue('total_items'),
            'total_batches' => $result->getDataValue('total_batches'),
            'validation' => $preValidation,
        ];
        if ($withMetrics) {
            $out['metrics'] = $syncManager->get_sync_performance_metrics($out['operation_id'] ?? null);
        }
        if ($asJson) {
            WP_CLI::line(json_encode(['status'=>'started'] + $out, JSON_PRETTY_PRINT));
        } else {
            WP_CLI::success('Sincronización iniciada');
            WP_CLI::log('Items totales: '.$out['total_items'].' | Batches: '.$out['total_batches']);
            if (!empty($preValidation['warnings'])) {
                WP_CLI::warning('Advertencias previas: '.implode(' | ', $preValidation['warnings']));
            }
        }
    }

    /**
     * Procesa el siguiente lote de la sincronización en curso.
     *
     * ## OPTIONS
     * [--json]  Salida JSON.
     */
    public function continue(array $args, array $assoc_args) {
        $asJson = isset($assoc_args['json']);
        $withMetrics = isset($assoc_args['metrics']);
        $syncManager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        $status = $syncManager->getSyncStatus();
        if (!$status['current_sync']['in_progress']) {
            WP_CLI::warning('No hay sincronización activa.');
            return;
        }
        $result = $syncManager->process_all_batches_sync(true);
        if ($withMetrics) {
            $opId = $status['current_sync']['operation_id'] ?? null;
            $result['metrics'] = $syncManager->get_sync_performance_metrics($opId);
            $result['batch_metrics'] = $syncManager->get_batch_metrics($opId);
        }
        if ($asJson) {
            WP_CLI::line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            if (!empty($result['success'])) {
                WP_CLI::success('Lote procesado');
                WP_CLI::log('Processed: '.($result['processed'] ?? '?').' Errors: '.($result['errors'] ?? 0));
                if ($withMetrics) {
                    WP_CLI::log('Métricas: '.json_encode($result['metrics']));
                }
            } else {
                WP_CLI::error('Error lote: '.($result['error'] ?? 'desconocido'));
            }
        }
    }

    /**
     * Muestra estado actual.
     *
     * ## OPTIONS
     * [--json]  Salida JSON.
     */
    public function status(array $args, array $assoc_args) {
        $asJson = isset($assoc_args['json']);
        $withMetrics = isset($assoc_args['metrics']);
        $syncManager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        $status = $syncManager->getSyncStatus();
    $opId = $status['current_sync']['operation_id'] ?? null;
    $metrics = $withMetrics ? $syncManager->get_sync_performance_metrics($opId) : null;
    $batchMetrics = $withMetrics ? $syncManager->get_batch_metrics($opId) : null;
        if ($asJson) {
            $payload = $status;
            if ($withMetrics) { $payload['metrics'] = $metrics; $payload['batch_metrics'] = $batchMetrics; }
            WP_CLI::line(json_encode($payload, JSON_PRETTY_PRINT));
            return;
        }
        if ($status['current_sync']['in_progress']) {
            WP_CLI::log('En progreso: '.$status['current_sync']['entity'].' '.$status['current_sync']['direction']);
            WP_CLI::log('Batch actual: '.$status['current_sync']['current_batch'].' / '.$status['current_sync']['total_batches']);
            WP_CLI::log('Items sincronizados: '.$status['current_sync']['items_synced'].' / '.$status['current_sync']['total_items']);
            if ($withMetrics && $metrics) {
                WP_CLI::log('Rendimiento agregado: '.json_encode($metrics));
            }
            if ($withMetrics && $batchMetrics) {
                $batches = $batchMetrics['batches'] ?? [];
                $lastBatch = !empty($batches) ? end($batches) : null;
                if ($lastBatch) {
                    WP_CLI::log('Último lote: '.json_encode($lastBatch));
                }
            }
        } else {
            WP_CLI::log('No hay sincronización en curso.');
        }
    }

    /**
     * Cancela sincronización.
     */
    public function cancel(array $args, array $assoc_args) {
        $syncManager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        $result = $syncManager->cancel_sync();
        if ($result['status'] === 'cancelled') {
            WP_CLI::success('Cancelada');
        } else {
            WP_CLI::warning($result['message'] ?? 'No había sincronización activa');
        }
    }

    /**
     * Reanuda (o fuerza reinicio) usando opciones de recuperación.
     *
     * ## OPTIONS
     * <entity> (products|orders|clientes|pedidos)
     * [--force] Forzar reinicio en lugar de reanudar.
     * [--json]
     */
    public function resume(array $args, array $assoc_args) {
        $entity = $args[0] ?? 'products';
        $force = isset($assoc_args['force']);
        $asJson = isset($assoc_args['json']);
    $coreRouting = defined('MIA_USE_CORE_SYNC') ? (bool) MIA_USE_CORE_SYNC : (bool) get_option('mia_use_core_sync', true);
        if (function_exists('apply_filters')) { $coreRouting = (bool) apply_filters('mia_use_core_sync_routing', $coreRouting); }
        $legacyEntity = $entity === 'products' ? 'productos' : $entity;
        if ($coreRouting && class_exists('MiIntegracionApi\\Core\\Sync_Manager')) {
            $syncManager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
            // Usar método moderno con parámetros apropiados
            $result = $syncManager->resume_sync(null, null, $legacyEntity);
        } else {
            $syncManager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
            $result = $syncManager->resume_sync(null, null, $legacyEntity);
        }
        if ($asJson) {
            WP_CLI::line(json_encode($result->toArray(), JSON_PRETTY_PRINT));
            return;
        }
        if ($result->isSuccess()) {
            WP_CLI::success('Proceso de reanudación lanzado: ' . $result->getMessage());
        } else {
            WP_CLI::error($result->getMessage());
        }
    }

    /**
     * Muestra estadísticas de uso de métodos legacy (tracking de adopción).
     *
     * ## OPTIONS
     * [--days=<n>]  Días hacia atrás (default 7)
     * [--json]      Salida JSON
     */
    public function legacy_stats(array $args, array $assoc_args) {
        $days = isset($assoc_args['days']) ? (int)$assoc_args['days'] : 7;
        $asJson = isset($assoc_args['json']);
        if (!class_exists('MiIntegracionApi\\Helpers\\LegacyCallTracker')) {
            WP_CLI::error('LegacyCallTracker no disponible');
        }
        $stats = \MiIntegracionApi\Helpers\LegacyCallTracker::get_stats($days);
        if ($asJson) {
            WP_CLI::line(json_encode($stats, JSON_PRETTY_PRINT));
            return;
        }
        WP_CLI::log('Periodo días: '.$stats['period']);
        WP_CLI::log('Total llamadas: '.$stats['total_calls']);
        WP_CLI::log('Top métodos: '.json_encode(array_slice($stats['methods'],0,5,true)));
        WP_CLI::log('Top callers: '.json_encode(array_slice($stats['callers'],0,5,true)));
    }

    /**
     * Crea snapshot comparativo del estado core vs legacy-wrapper y lo guarda en logs.
     *
     * ## OPTIONS
     * [--json] Muestra el JSON además de guardarlo.
     */
    public function snapshot_status(array $args, array $assoc_args) {
        $asJson = isset($assoc_args['json']);
        $core = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        // Removemos verificaciones de métodos deprecated
        $coreStatus = $core->getSyncStatus();
        // Eliminamos la comparación con método deprecated
        $payload = [
            'timestamp' => time(),
            'core' => $coreStatus,
            'note' => 'Legacy comparison removed - only using modern methods'
        ];
        $dir = dirname(__DIR__,2).'/logs/status_snapshots';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $file = $dir.'/snapshot_'.date('Ymd_His').'.json';
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        WP_CLI::success('Snapshot guardado: '.$file);
        if ($asJson) {
            WP_CLI::line(json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Ejecuta un flujo completo de snapshots (idle -> start -> batch -> cancel -> resume) y guarda cada estado.
     *
     * ## OPTIONS
     * [--entity=<entity>] Entidad (products|orders) (default: products)
     * [--direction=<direction>] Dirección (verial_to_wc|wc_to_verial) (default: verial_to_wc)
     * [--batches=<n>] Número de lotes a procesar antes de cancelar (default:1)
     * [--json] Salida JSON consolidada
     * [--no-cancel] No realiza cancel, sólo snapshots hasta in-progress
     * [--no-resume] No realiza resume tras cancel
     */
    public function snapshot_flow(array $args, array $assoc_args) {
        $entity    = $assoc_args['entity'] ?? 'products';
        $direction = $assoc_args['direction'] ?? 'verial_to_wc';
        $batches   = isset($assoc_args['batches']) ? max(0,(int)$assoc_args['batches']) : 1;
        $asJson    = isset($assoc_args['json']);
        $doCancel  = !isset($assoc_args['no-cancel']);
        $doResume  = !isset($assoc_args['no-resume']);

        $core = \MiIntegracionApi\Core\Sync_Manager::get_instance();
        // Removemos verificaciones de métodos deprecated

        $results = [];
        $dir = dirname(__DIR__,2).'/logs/status_snapshots';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        $timestampBase = date('Ymd_His');

        $capture = function(string $label) use ($core, $dir, $timestampBase, &$results) {
            $coreStatus = $core->getSyncStatus();
            // Eliminamos uso de métodos deprecated
            $payload = [
                'label' => $label,
                'timestamp' => time(),
                'core' => $coreStatus,
                'note' => 'Legacy comparison removed'
            ];
            $file = $dir.'/snapshot_'.$label.'_'.$timestampBase.'.json';
            file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
            $payload['file'] = $file;
            $results[] = $payload;
            WP_CLI::log("Snapshot [$label] -> $file (differences: ".count($diff).")");
        };

        // Precondición: no sync en curso (si hay, abortar para no contaminar)
        $status = $core->getSyncStatus();
        if (!empty($status['current_sync']['in_progress'])) {
            WP_CLI::error('Hay una sincronización en curso. Cancela o espera antes de ejecutar el flujo de snapshots.');
        }

        // 1. Idle
        $capture('idle');

        // 2. Start
        $start = $core->start_sync($entity, $direction, []);
        if (empty($start['success'])) {
            WP_CLI::error('No se pudo iniciar la sync: '.($start['error'] ?? 'desconocido'));
        }
        $capture('started');

        // 3. Procesar lotes (si se pidió)
        for ($i=0; $i<$batches; $i++) {
            $batchRes = $core->process_all_batches_sync(true);
            if (empty($batchRes['success'])) {
                WP_CLI::warning('Error al procesar lote: '.($batchRes['error'] ?? 'desconocido').' (continuando)');
                break;
            }
        }
        if ($batches>0) {
            $capture('in_progress');
        }

        // 4. Cancel (opcional)
        if ($doCancel) {
            $cancelRes = $core->cancel_sync();
            if (($cancelRes['status'] ?? '') !== 'cancelled') {
                WP_CLI::warning('Cancelación no confirmó estado cancelled');
            }
            $capture('cancelled');
        }

        // 5. Resume (opcional y sólo si cancel) 
        if ($doCancel && $doResume) {
            // Usar método moderno directamente
            $core->resume_sync(['entity' => $entity, 'force' => false]);
            $capture('resumed');
        }

        // Consolidated diff summary (conteo de tipos de diff por snapshot)
        $summary = [];
        foreach ($results as $r) {
            $counts = ['only_in_legacy'=>0,'only_in_core'=>0,'type_mismatch'=>0];
            foreach ($r['key_diff'] as $d) { $counts[$d['type']] = ($counts[$d['type']] ?? 0)+1; }
            $summary[$r['label']] = $counts;
        }

        if ($asJson) {
            WP_CLI::line(json_encode(['snapshots'=>$results,'summary'=>$summary], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        } else {
            WP_CLI::success('Flujo de snapshots completado');
            WP_CLI::log('Resumen diffs: '.json_encode($summary));
        }
    }

    /**
     * Compara dos archivos snapshot_*.json (key_diff y discrepancias de valores importantes).
     *
     * ## OPTIONS
     * <file_a> Ruta archivo snapshot A
     * <file_b> Ruta archivo snapshot B
     * [--json] Salida JSON
     * [--allow-dynamic=<fields>] Campos separados por coma que se ignoran en comparación de valor (defaults operation_id,start_time,last_update,timestamp)
     */
    public function snapshot_compare(array $args, array $assoc_args) {
        $fileA = $args[0] ?? null; $fileB = $args[1] ?? null;
        $asJson = isset($assoc_args['json']);
        $allow = isset($assoc_args['allow-dynamic']) ? explode(',', $assoc_args['allow-dynamic']) : ['operation_id','start_time','last_update','timestamp'];
        if (!$fileA || !$fileB || !is_file($fileA) || !is_file($fileB)) {
            WP_CLI::error('Debe proporcionar dos archivos snapshot válidos');
        }
        $a = json_decode(file_get_contents($fileA), true);
        $b = json_decode(file_get_contents($fileB), true);
        if (!$a || !$b) { WP_CLI::error('No se pudieron decodificar archivos JSON'); }

        $report = [
            'file_a' => $fileA,
            'file_b' => $fileB,
            'key_diff_core' => $this->diff_keys_recursive($a['legacy_wrapper'] ?? [], $a['core'] ?? []),
            'key_diff_core_b' => $this->diff_keys_recursive($b['legacy_wrapper'] ?? [], $b['core'] ?? []),
            'value_diff' => []
        ];

        // Comparar valores núcleo (core vs legacy_wrapper) en cada snapshot para claves compartidas
        $compareValueSets = [ 'A_core_vs_legacy' => [$a['core'] ?? [], $a['legacy_wrapper'] ?? []], 'B_core_vs_legacy' => [$b['core'] ?? [], $b['legacy_wrapper'] ?? []] ];
        foreach ($compareValueSets as $label => [$corePayload, $legacyPayload]) {
            $valueDiffs = [];
            $this->collect_value_diffs($legacyPayload, $corePayload, '', $valueDiffs, $allow);
            $report['value_diff'][$label] = $valueDiffs;
        }

        if ($asJson) {
            WP_CLI::line(json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        } else {
            WP_CLI::log('Comparación: '.basename($fileA).' vs '.basename($fileB));
            WP_CLI::log('Diff valores A: '.count($report['value_diff']['A_core_vs_legacy']).' | B: '.count($report['value_diff']['B_core_vs_legacy']));
            if (count($report['value_diff']['A_core_vs_legacy']) || count($report['value_diff']['B_core_vs_legacy'])) {
                WP_CLI::warning('Existen diferencias de valor relevantes (ver --json para detalle)');
            } else {
                WP_CLI::success('Sin diferencias de valor (ignorando campos dinámicos)');
            }
        }
    }

    private function collect_value_diffs($legacy, $core, string $path, array &$acc, array $ignore) {
        if (is_array($legacy) && is_array($core)) {
            foreach (array_intersect(array_keys($legacy), array_keys($core)) as $k) {
                $newPath = $path === '' ? $k : $path.'.'.$k;
                if (in_array($k, $ignore, true)) { continue; }
                if (is_array($legacy[$k]) && is_array($core[$k])) {
                    $this->collect_value_diffs($legacy[$k], $core[$k], $newPath, $acc, $ignore);
                } else {
                    if ($legacy[$k] !== $core[$k]) {
                        $acc[] = ['path'=>$newPath,'legacy'=>$legacy[$k],'core'=>$core[$k]];
                    }
                }
            }
        }
    }

    /**
     * Diff de claves (presence & structure differences) simple.
     */
    private function diff_keys_recursive($legacy, $core, $path = ''): array {
        $diff = [];
        if (is_array($legacy) && is_array($core)) {
            $legacyKeys = array_keys($legacy);
            $coreKeys = array_keys($core);
            foreach (array_diff($legacyKeys, $coreKeys) as $k) {
                $diff[] = ['type'=>'only_in_legacy','path'=>$path.$k];
            }
            foreach (array_diff($coreKeys, $legacyKeys) as $k) {
                $diff[] = ['type'=>'only_in_core','path'=>$path.$k];
            }
            foreach (array_intersect($legacyKeys, $coreKeys) as $k) {
                if (is_array($legacy[$k]) && is_array($core[$k])) {
                    $nested = $this->diff_keys_recursive($legacy[$k], $core[$k], $path.$k.'.');
                    $diff = array_merge($diff, $nested);
                }
            }
        } elseif (gettype($legacy) !== gettype($core)) {
            $diff[] = ['type'=>'type_mismatch','path'=>$path,'legacy_type'=>gettype($legacy),'core_type'=>gettype($core)];
        }
        return $diff;
    }

    /**
     * Sincroniza todas las imágenes de productos (Fase 1 de arquitectura dos fases).
     *
     * Este comando procesa todas las imágenes de productos desde Verial API,
     * las guarda en la media library de WordPress y las asocia con metadatos
     * para su uso posterior en la Fase 2 (sincronización de productos).
     *
     * ## OPTIONS
     *
     * [--resume]
     * : Continuar desde el último checkpoint guardado.
     *
     * [--json]
     * : Salida en formato JSON.
     *
     * [--batch-size=<n>]
     * : Número de productos a procesar por lote (default: 10).
     *
     * ## EXAMPLES
     *
     *     # Sincronizar todas las imágenes
     *     wp verial sync-images
     *
     *     # Continuar desde checkpoint
     *     wp verial sync-images --resume
     *
     *     # Con salida JSON
     *     wp verial sync-images --json
     *
     *     # Procesar 20 productos por lote
     *     wp verial sync-images --batch-size=20
     *
     * @param array $args Argumentos posicionales (no usados).
     * @param array $assoc_args Argumentos asociativos.
     * @return void
     * @since 1.5.0
     */
    public function sync_images(array $args, array $assoc_args): void {
        $resume = isset($assoc_args['resume']);
        $asJson = isset($assoc_args['json']);
        $batchSize = isset($assoc_args['batch-size']) ? (int)$assoc_args['batch-size'] : 10;

        try {
            // Validar que ImageSyncManager esté disponible
            if (!class_exists('\\MiIntegracionApi\\Sync\\ImageSyncManager')) {
                $error = 'ImageSyncManager no está disponible. Verifica que el autoloader esté actualizado.';
                if ($asJson) {
                    WP_CLI::line(json_encode(['success' => false, 'error' => $error], JSON_PRETTY_PRINT));
                    return;
                }
                WP_CLI::error($error);
            }

            // Obtener instancias necesarias
            $apiConnector = \MiIntegracionApi\Core\ApiConnector::get_instance();
            $logger = \MiIntegracionApi\Helpers\Logger::get_instance();
            $imageSyncManager = new \MiIntegracionApi\Sync\ImageSyncManager($apiConnector, $logger);

            WP_CLI::log('Iniciando sincronización de imágenes (Fase 1)...');
            
            if ($resume) {
                WP_CLI::log('Modo: Continuar desde checkpoint');
            } else {
                WP_CLI::log('Modo: Sincronización completa');
            }

            // Ejecutar sincronización
            $result = $imageSyncManager->syncAllImages($resume, $batchSize);

            if ($asJson) {
                WP_CLI::line(json_encode([
                    'success' => true,
                    'message' => 'Sincronización de imágenes completada',
                    'result' => [
                        'total_processed' => $result['total_processed'] ?? 0,
                        'total_success' => $result['total_success'] ?? 0,
                        'total_errors' => $result['total_errors'] ?? 0,
                        'checkpoint_saved' => $result['checkpoint_saved'] ?? false
                    ]
                ], JSON_PRETTY_PRINT));
            } else {
                WP_CLI::success('Sincronización de imágenes completada');
                WP_CLI::log(sprintf(
                    'Procesados: %d | Exitosos: %d | Errores: %d',
                    $result['total_processed'] ?? 0,
                    $result['total_success'] ?? 0,
                    $result['total_errors'] ?? 0
                ));
                if (!empty($result['checkpoint_saved'])) {
                    WP_CLI::log('Checkpoint guardado para recuperación futura');
                }
            }

        } catch (\Exception $e) {
            $error = 'Error durante sincronización de imágenes: ' . $e->getMessage();
            if ($asJson) {
                WP_CLI::line(json_encode([
                    'success' => false,
                    'error' => $error,
                    'trace' => $e->getTraceAsString()
                ], JSON_PRETTY_PRINT));
            } else {
                WP_CLI::error($error);
            }
        }
    }
}

// Re-registro consolidado (mantener alias si se desea)
WP_CLI::add_command('verial sync', SyncCommands::class);
WP_CLI::add_command('verial sync-start', [SyncCommands::class, 'sync']); // alias
WP_CLI::add_command('verial sync-continue', [SyncCommands::class, 'continue']);
WP_CLI::add_command('verial sync-status', [SyncCommands::class, 'status']);
WP_CLI::add_command('verial sync-cancel', [SyncCommands::class, 'cancel']);
WP_CLI::add_command('verial sync-resume', [SyncCommands::class, 'resume']);
WP_CLI::add_command('verial sync-images', [SyncCommands::class, 'sync_images']);
WP_CLI::add_command('verial legacy-stats', [SyncCommands::class, 'legacy_stats']);
WP_CLI::add_command('verial snapshot-status', [SyncCommands::class, 'snapshot_status']);
WP_CLI::add_command('verial snapshot-flow', [SyncCommands::class, 'snapshot_flow']);
WP_CLI::add_command('verial snapshot-compare', [SyncCommands::class, 'snapshot_compare']);

// Comandos de limpieza de logs
WP_CLI::add_command('verial log', LogCleanupCommand::class);
