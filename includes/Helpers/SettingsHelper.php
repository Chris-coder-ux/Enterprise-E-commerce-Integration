<?php

declare(strict_types=1);

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper para gestionar configuraciones del plugin
 */
class SettingsHelper
{
    /**
     * Guarda la API key de forma segura
     * 
     * @param string $api_key La API key a guardar
     * @return bool True si se guardó correctamente
     */
    public static function save_api_key(string $api_key): bool
    {
        try {
            // Cifrar la API key antes de guardarla
            $encrypted_key = self::encrypt_api_key($api_key);
            
            // Guardar en las opciones de WordPress
            $result = update_option('mi_integracion_api_key', $encrypted_key);
            
            if ($result) {
                // Log de la acción
                if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
                    $logger = new Logger('settings');
                    $logger->info('API Key guardada exitosamente', [
                        'action' => 'save_api_key',
                        'user_id' => get_current_user_id()
                    ]);
                }
            }
            
            return $result;
        } catch (\Exception $e) {
            // Log del error
            if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
                $logger = new Logger('settings');
                $logger->error('Error al guardar API Key', [
                    'error' => $e->getMessage(),
                    'user_id' => get_current_user_id()
                ]);
            }
            
            return false;
        }
    }
    
    /**
     * Obtiene la API key descifrada
     * 
     * @return string|null La API key o null si no existe
     */
    public static function get_api_key(): ?string
    {
        try {
            $encrypted_key = get_option('mi_integracion_api_key');
            
            if (!$encrypted_key) {
                return null;
            }
            
            return self::decrypt_api_key($encrypted_key);
        } catch (\Exception $e) {
            // Log del error
            if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
                $logger = new Logger('settings');
                $logger->error('Error al obtener API Key', [
                    'error' => $e->getMessage()
                ]);
            }
            
            return null;
        }
    }
    
    /**
     * Verifica si la API key está revocada
     * 
     * @param string $api_key La API key a verificar
     * @return bool True si está revocada
     */
    public static function is_api_key_revoked(string $api_key): bool
    {
        // Por ahora, siempre retornar false
        // En el futuro se puede implementar una verificación real
        return false;
    }
    
    /**
     * Cifra la API key usando una clave de cifrado simple
     * 
     * @param string $api_key La API key a cifrar
     * @return string La API key cifrada
     */
    private static function encrypt_api_key(string $api_key): string
    {
        // Usar una clave de cifrado basada en la sal de WordPress
        $key = wp_salt('AUTH_KEY');
        
        // Cifrado simple usando base64 y XOR
        $encrypted = '';
        $key_length = strlen($key);
        
        for ($i = 0; $i < strlen($api_key); $i++) {
            $encrypted .= chr(ord($api_key[$i]) ^ ord($key[$i % $key_length]));
        }
        
        return base64_encode($encrypted);
    }
    
    /**
     * Descifra la API key
     * 
     * @param string $encrypted_key La API key cifrada
     * @return string La API key descifrada
     */
    private static function decrypt_api_key(string $encrypted_key): string
    {
        // Usar la misma clave de cifrado
        $key = wp_salt('AUTH_KEY');
        
        // Descifrado
        $decoded = base64_decode($encrypted_key);
        $decrypted = '';
        $key_length = strlen($key);
        
        for ($i = 0; $i < strlen($decoded); $i++) {
            $decrypted .= chr(ord($decoded[$i]) ^ ord($key[$i % $key_length]));
        }
        
        return $decrypted;
    }
}
