<?php
/**
 * ✅ NUEVO: Función de reparación para recuperar metadatos de imágenes eliminados
 *
 * Cuando se desinstala el plugin, se eliminan los metadatos `_verial_*` de las imágenes.
 * Esta función intenta recuperar las asociaciones basándose en el nombre del archivo
 * que contiene el ID del artículo de Verial.
 *
 * @package MiIntegracionApi
 * @since 1.5.0
 */

namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\Core\Logger;

/**
 * Repara metadatos de imágenes eliminados por desinstalación del plugin
 *
 * Busca attachments cuyo nombre de archivo contiene el patrón `verial-image-{ID}-`
 * y restaura los metadatos `_verial_article_id` basándose en ese ID.
 *
 * @param   bool $dry_run Si es true, solo muestra qué se repararía sin hacer cambios.
 * @return  array Estadísticas de la reparación.
 */
function repair_image_metadata(bool $dry_run = false): array
{
    global $wpdb;
    
    $stats = [
        'attachments_found' => 0,
        'metadata_repaired' => 0,
        'errors' => 0,
        'skipped' => 0
    ];
    
    // Buscar todos los attachments de imágenes
    $attachments = $wpdb->get_results(
        "SELECT ID, post_title, guid 
         FROM {$wpdb->posts} 
         WHERE post_type = 'attachment' 
         AND post_mime_type LIKE 'image/%'
         ORDER BY ID ASC"
    );
    
    if (empty($attachments)) {
        return $stats;
    }
    
    $stats['attachments_found'] = count($attachments);
    
    $logger = Logger::get_instance();
    
    foreach ($attachments as $attachment) {
        $attachment_id = (int)$attachment->ID;
        $filename = $attachment->post_title;
        
        // Buscar patrón: verial-image-{ID}-{uniqid}.{ext}
        // Ejemplo: verial-image-5-abc123.jpg
        if (preg_match('/verial-image-(\d+)-/', $filename, $matches)) {
            $article_id = (int)$matches[1];
            
            // Verificar si ya tiene el metadato
            $existing_article_id = get_post_meta($attachment_id, '_verial_article_id', true);
            
            if (!empty($existing_article_id)) {
                // Ya tiene metadato, saltar
                $stats['skipped']++;
                continue;
            }
            
            if ($dry_run) {
                $logger->info('DRY-RUN: Repararía metadato', [
                    'attachment_id' => $attachment_id,
                    'filename' => $filename,
                    'article_id' => $article_id
                ]);
                $stats['metadata_repaired']++;
            } else {
                // Restaurar metadato
                $result = update_post_meta($attachment_id, '_verial_article_id', $article_id);
                
                if ($result !== false) {
                    $stats['metadata_repaired']++;
                    $logger->info('Metadato reparado', [
                        'attachment_id' => $attachment_id,
                        'filename' => $filename,
                        'article_id' => $article_id
                    ]);
                } else {
                    $stats['errors']++;
                    $logger->error('Error reparando metadato', [
                        'attachment_id' => $attachment_id,
                        'filename' => $filename,
                        'article_id' => $article_id
                    ]);
                }
            }
        }
    }
    
    return $stats;
}

