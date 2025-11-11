<?php
declare(strict_types=1);

namespace MiIntegracionApi\Admin;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Gestor de configuración de notificaciones
 * 
 * Esta clase maneja toda la configuración relacionada con las notificaciones
 * automáticas y solicitudes de documentos en Verial.
 * 
 * @package MiIntegracionApi\Admin
 * @since 2.0.0
 */
class NotificationConfig
{
    // Constantes para opciones de configuración
    private const NOTIFICATIONS_ENABLED_OPTION = 'mia_notifications_enabled';
    private const NOTIFICATION_TYPES_OPTION = 'mia_notification_types';
    private const AUTO_DOCUMENT_REQUESTS_OPTION = 'mia_auto_document_requests';
    private const NOTIFICATION_SCHEDULE_OPTION = 'mia_notification_schedule';
    private const NOTIFICATION_RETENTION_OPTION = 'mia_notification_retention_days';
    private const NOTIFICATION_TEMPLATES_OPTION = 'mia_notification_templates';
    private const NOTIFICATION_EMAILS_OPTION = 'mia_notification_emails';
    private const NOTIFICATION_THRESHOLDS_OPTION = 'mia_notification_thresholds';
    
    // Tipos de notificaciones disponibles
    public const TYPE_PRODUCT_CREATED = 'product_created';
    public const TYPE_PRODUCT_UPDATED = 'product_updated';
    public const TYPE_PRODUCT_DELETED = 'product_deleted';
    public const TYPE_STOCK_LOW = 'stock_low';
    public const TYPE_SYNC_ERROR = 'sync_error';
    public const TYPE_DOCUMENT_REQUEST = 'document_request';
    
    // Estados de configuración
    public const STATUS_ENABLED = 'enabled';
    public const STATUS_DISABLED = 'disabled';
    public const STATUS_SCHEDULED = 'scheduled';
    
    // Horarios disponibles
    public const SCHEDULE_IMMEDIATE = 'immediate';
    public const SCHEDULE_HOURLY = 'hourly';
    public const SCHEDULE_DAILY = 'daily';
    public const SCHEDULE_WEEKLY = 'weekly';
    
    /**
     * Obtiene la configuración completa de notificaciones
     * 
     * @return array Configuración completa
     */
    public static function get_config(): array
    {
        return [
            'notifications_enabled' => self::is_notifications_enabled(),
            'notification_types' => self::get_enabled_notification_types(),
            'auto_document_requests' => self::is_auto_document_requests_enabled(),
            'notification_schedule' => self::get_notification_schedule(),
            'notification_retention_days' => self::get_notification_retention_days(),
            'notification_templates' => self::get_notification_templates(),
            'notification_emails' => self::get_notification_emails(),
            'notification_thresholds' => self::get_notification_thresholds(),
            'available_types' => self::get_available_notification_types(),
            'available_schedules' => self::get_available_schedules()
        ];
    }
    
    /**
     * Verifica si las notificaciones están habilitadas
     * 
     * @return bool True si están habilitadas
     */
    public static function is_notifications_enabled(): bool
    {
        return (bool) get_option(self::NOTIFICATIONS_ENABLED_OPTION, true);
    }
    
    /**
     * Habilita o deshabilita las notificaciones
     * 
     * @param bool $enabled Estado de habilitación
     * @return bool True si se actualizó correctamente
     */
    public static function set_notifications_enabled(bool $enabled): bool
    {
        return update_option(self::NOTIFICATIONS_ENABLED_OPTION, $enabled);
    }
    
    /**
     * Obtiene los tipos de notificaciones habilitados
     * 
     * @return array Tipos habilitados
     */
    public static function get_enabled_notification_types(): array
    {
        $default_types = [
            self::TYPE_PRODUCT_CREATED => self::STATUS_ENABLED,
            self::TYPE_PRODUCT_UPDATED => self::STATUS_ENABLED,
            self::TYPE_PRODUCT_DELETED => self::STATUS_ENABLED,
            self::TYPE_STOCK_LOW => self::STATUS_DISABLED,
            self::TYPE_SYNC_ERROR => self::STATUS_ENABLED,
            self::TYPE_DOCUMENT_REQUEST => self::STATUS_DISABLED
        ];
        
        return get_option(self::NOTIFICATION_TYPES_OPTION, $default_types);
    }
    
    /**
     * Actualiza los tipos de notificaciones habilitados
     * 
     * @param array $types Tipos y sus estados
     * @return bool True si se actualizó correctamente
     */
    public static function set_notification_types(array $types): bool
    {
        $validated_types = [];
        $available_types = self::get_available_notification_types();
        
        foreach ($types as $type => $status) {
            if (isset($available_types[$type]) && in_array($status, [self::STATUS_ENABLED, self::STATUS_DISABLED, self::STATUS_SCHEDULED])) {
                $validated_types[$type] = $status;
            }
        }
        
        return update_option(self::NOTIFICATION_TYPES_OPTION, $validated_types);
    }
    
    /**
     * Verifica si un tipo de notificación está habilitado
     * 
     * @param string $type Tipo de notificación
     * @return bool True si está habilitado
     */
    public static function is_notification_type_enabled(string $type): bool
    {
        $types = self::get_enabled_notification_types();
        return isset($types[$type]) && $types[$type] === self::STATUS_ENABLED;
    }
    
    /**
     * Verifica si las solicitudes de documentos automáticas están habilitadas
     * 
     * @return bool True si están habilitadas
     */
    public static function is_auto_document_requests_enabled(): bool
    {
        return (bool) get_option(self::AUTO_DOCUMENT_REQUESTS_OPTION, false);
    }
    
    /**
     * Habilita o deshabilita las solicitudes de documentos automáticas
     * 
     * @param bool $enabled Estado de habilitación
     * @return bool True si se actualizó correctamente
     */
    public static function set_auto_document_requests_enabled(bool $enabled): bool
    {
        return update_option(self::AUTO_DOCUMENT_REQUESTS_OPTION, $enabled);
    }
    
    /**
     * Obtiene la configuración de horarios de notificaciones
     * 
     * @return array Configuración de horarios
     */
    public static function get_notification_schedule(): array
    {
        $default_schedule = [
            'type' => self::SCHEDULE_IMMEDIATE,
            'time' => '09:00',
            'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'timezone' => wp_timezone_string()
        ];
        
        return get_option(self::NOTIFICATION_SCHEDULE_OPTION, $default_schedule);
    }
    
    /**
     * Actualiza la configuración de horarios
     * 
     * @param array $schedule Configuración de horarios
     * @return bool True si se actualizó correctamente
     */
    public static function set_notification_schedule(array $schedule): bool
    {
        $validated_schedule = [
            'type' => $schedule['type'] ?? self::SCHEDULE_IMMEDIATE,
            'time' => $schedule['time'] ?? '09:00',
            'days' => is_array($schedule['days'] ?? []) ? $schedule['days'] : [],
            'timezone' => $schedule['timezone'] ?? wp_timezone_string()
        ];
        
        return update_option(self::NOTIFICATION_SCHEDULE_OPTION, $validated_schedule);
    }
    
    /**
     * Obtiene los días de retención de notificaciones
     * 
     * @return int Días de retención
     */
    public static function get_notification_retention_days(): int
    {
        return (int) get_option(self::NOTIFICATION_RETENTION_OPTION, 30);
    }
    
    /**
     * Actualiza los días de retención
     * 
     * @param int $days Días de retención
     * @return bool True si se actualizó correctamente
     */
    public static function set_notification_retention_days(int $days): bool
    {
        $days = max(1, min(365, $days)); // Entre 1 y 365 días
        return update_option(self::NOTIFICATION_RETENTION_OPTION, $days);
    }
    
    /**
     * Obtiene las plantillas de notificaciones
     * 
     * @return array Plantillas
     */
    public static function get_notification_templates(): array
    {
        $default_templates = [
            self::TYPE_PRODUCT_CREATED => [
                'subject' => 'Nuevo producto creado: {product_name}',
                'message' => 'Se ha creado un nuevo producto en WooCommerce:\n\nNombre: {product_name}\nSKU: {product_sku}\nPrecio: {product_price}\n\nVer producto: {product_edit_link}',
                'email_template' => 'product_created.html'
            ],
            self::TYPE_PRODUCT_UPDATED => [
                'subject' => 'Producto actualizado: {product_name}',
                'message' => 'Se ha actualizado un producto en WooCommerce:\n\nNombre: {product_name}\nSKU: {product_sku}\nPrecio: {product_price}\n\nVer producto: {product_edit_link}',
                'email_template' => 'product_updated.html'
            ],
            self::TYPE_PRODUCT_DELETED => [
                'subject' => 'Producto eliminado: {product_name}',
                'message' => 'Se ha eliminado un producto de WooCommerce:\n\nNombre: {product_name}\nSKU: {product_sku}\n\nEsta acción no se puede deshacer.',
                'email_template' => 'product_deleted.html'
            ],
            self::TYPE_STOCK_LOW => [
                'subject' => 'Stock bajo: {product_name}',
                'message' => 'El producto {product_name} tiene stock bajo:\n\nStock actual: {stock_quantity}\nStock mínimo: {stock_minimum}\n\nVer producto: {product_edit_link}',
                'email_template' => 'stock_low.html'
            ],
            self::TYPE_SYNC_ERROR => [
                'subject' => 'Error de sincronización con Verial',
                'message' => 'Se ha producido un error durante la sincronización:\n\nError: {error_message}\nProducto: {product_name}\nTimestamp: {timestamp}\n\nRevisar logs para más detalles.',
                'email_template' => 'sync_error.html'
            ],
            self::TYPE_DOCUMENT_REQUEST => [
                'subject' => 'Solicitud de documento creada en Verial',
                'message' => 'Se ha creado una solicitud de documento en Verial:\n\nProducto: {product_name}\nSKU: {product_sku}\nReferencia: {document_reference}\n\nVer solicitud en Verial.',
                'email_template' => 'document_request.html'
            ]
        ];
        
        return get_option(self::NOTIFICATION_TEMPLATES_OPTION, $default_templates);
    }
    
    /**
     * Actualiza las plantillas de notificaciones
     * 
     * @param array $templates Plantillas
     * @return bool True si se actualizó correctamente
     */
    public static function set_notification_templates(array $templates): bool
    {
        return update_option(self::NOTIFICATION_TEMPLATES_OPTION, $templates);
    }
    
    /**
     * Obtiene las direcciones de email para notificaciones
     * 
     * @return array Direcciones de email
     */
    public static function get_notification_emails(): array
    {
        $default_emails = [
            'admin' => get_option('admin_email', ''),
            'additional' => [],
            'by_type' => []
        ];
        
        return get_option(self::NOTIFICATION_EMAILS_OPTION, $default_emails);
    }
    
    /**
     * Actualiza las direcciones de email
     * 
     * @param array $emails Direcciones de email
     * @return bool True si se actualizó correctamente
     */
    public static function set_notification_emails(array $emails): bool
    {
        $validated_emails = [
            'admin' => sanitize_email($emails['admin'] ?? get_option('admin_email', '')),
            'additional' => array_map('sanitize_email', $emails['additional'] ?? []),
            'by_type' => $emails['by_type'] ?? []
        ];
        
        return update_option(self::NOTIFICATION_EMAILS_OPTION, $validated_emails);
    }
    
    /**
     * Obtiene los umbrales de notificaciones
     * 
     * @return array Umbrales
     */
    public static function get_notification_thresholds(): array
    {
        $default_thresholds = [
            'stock_low_quantity' => 5,
            'sync_error_threshold' => 3,
            'notification_frequency_limit' => 10, // Máximo por hora
            'batch_size' => 50
        ];
        
        return get_option(self::NOTIFICATION_THRESHOLDS_OPTION, $default_thresholds);
    }
    
    /**
     * Actualiza los umbrales de notificaciones
     * 
     * @param array $thresholds Umbrales
     * @return bool True si se actualizó correctamente
     */
    public static function set_notification_thresholds(array $thresholds): bool
    {
        $validated_thresholds = [
            'stock_low_quantity' => max(0, (int) ($thresholds['stock_low_quantity'] ?? 5)),
            'sync_error_threshold' => max(1, (int) ($thresholds['sync_error_threshold'] ?? 3)),
            'notification_frequency_limit' => max(1, (int) ($thresholds['notification_frequency_limit'] ?? 10)),
            'batch_size' => max(1, (int) ($thresholds['batch_size'] ?? 50))
        ];
        
        return update_option(self::NOTIFICATION_THRESHOLDS_OPTION, $validated_thresholds);
    }
    
    /**
     * Obtiene los tipos de notificaciones disponibles
     * 
     * @return array Tipos disponibles
     */
    public static function get_available_notification_types(): array
    {
        return [
            self::TYPE_PRODUCT_CREATED => [
                'label' => 'Productos Nuevos',
                'description' => 'Notificar cuando se crean productos nuevos en WooCommerce',
                'icon' => '🆕',
                'default_enabled' => true
            ],
            self::TYPE_PRODUCT_UPDATED => [
                'label' => 'Productos Actualizados',
                'description' => 'Notificar cuando se actualizan productos existentes',
                'icon' => '✏️',
                'default_enabled' => true
            ],
            self::TYPE_PRODUCT_DELETED => [
                'label' => 'Productos Eliminados',
                'description' => 'Notificar cuando se eliminan productos',
                'icon' => '🗑️',
                'default_enabled' => true
            ],
            self::TYPE_STOCK_LOW => [
                'label' => 'Stock Bajo',
                'description' => 'Notificar cuando el stock de productos es bajo',
                'icon' => '⚠️',
                'default_enabled' => false
            ],
            self::TYPE_SYNC_ERROR => [
                'label' => 'Errores de Sincronización',
                'description' => 'Notificar cuando hay errores en la sincronización con Verial',
                'icon' => '❌',
                'default_enabled' => true
            ],
            self::TYPE_DOCUMENT_REQUEST => [
                'label' => 'Solicitudes de Documentos',
                'description' => 'Notificar cuando se crean solicitudes de documentos en Verial',
                'icon' => '📄',
                'default_enabled' => false
            ]
        ];
    }
    
    /**
     * Obtiene los horarios disponibles
     * 
     * @return array Horarios disponibles
     */
    public static function get_available_schedules(): array
    {
        return [
            self::SCHEDULE_IMMEDIATE => [
                'label' => 'Inmediato',
                'description' => 'Enviar notificaciones inmediatamente'
            ],
            self::SCHEDULE_HOURLY => [
                'label' => 'Cada Hora',
                'description' => 'Enviar notificaciones cada hora'
            ],
            self::SCHEDULE_DAILY => [
                'label' => 'Diario',
                'description' => 'Enviar notificaciones una vez al día'
            ],
            self::SCHEDULE_WEEKLY => [
                'label' => 'Semanal',
                'description' => 'Enviar notificaciones una vez a la semana'
            ]
        ];
    }
    
    /**
     * Resetea la configuración a los valores por defecto
     * 
     * @return bool True si se reseteó correctamente
     */
    public static function reset_to_defaults(): bool
    {
        $defaults = [
            self::NOTIFICATIONS_ENABLED_OPTION => true,
            self::NOTIFICATION_TYPES_OPTION => [
                self::TYPE_PRODUCT_CREATED => self::STATUS_ENABLED,
                self::TYPE_PRODUCT_UPDATED => self::STATUS_ENABLED,
                self::TYPE_PRODUCT_DELETED => self::STATUS_ENABLED,
                self::TYPE_STOCK_LOW => self::STATUS_DISABLED,
                self::TYPE_SYNC_ERROR => self::STATUS_ENABLED,
                self::TYPE_DOCUMENT_REQUEST => self::STATUS_DISABLED
            ],
            self::AUTO_DOCUMENT_REQUESTS_OPTION => false,
            self::NOTIFICATION_SCHEDULE_OPTION => [
                'type' => self::SCHEDULE_IMMEDIATE,
                'time' => '09:00',
                'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'timezone' => wp_timezone_string()
            ],
            self::NOTIFICATION_RETENTION_OPTION => 30,
            self::NOTIFICATION_TEMPLATES_OPTION => self::get_notification_templates(),
            self::NOTIFICATION_EMAILS_OPTION => [
                'admin' => get_option('admin_email', ''),
                'additional' => [],
                'by_type' => []
            ],
            self::NOTIFICATION_THRESHOLDS_OPTION => [
                'stock_low_quantity' => 5,
                'sync_error_threshold' => 3,
                'notification_frequency_limit' => 10,
                'batch_size' => 50
            ]
        ];
        
        $success = true;
        foreach ($defaults as $option => $value) {
            if (!update_option($option, $value)) {
                $success = false;
            }
        }
        
        return $success;
    }
    
    /**
     * Valida la configuración completa
     * 
     * @return array Resultado de la validación
     */
    public static function validate_config(): array
    {
        $errors = [];
        $warnings = [];
        
        // Validar emails
        $emails = self::get_notification_emails();
        if (empty($emails['admin']) || !is_email($emails['admin'])) {
            $errors[] = 'Email de administrador no válido';
        }
        
        foreach ($emails['additional'] as $email) {
            if (!is_email($email)) {
                $warnings[] = "Email adicional no válido: {$email}";
            }
        }
        
        // Validar umbrales
        $thresholds = self::get_notification_thresholds();
        if ($thresholds['stock_low_quantity'] < 0) {
            $errors[] = 'Cantidad de stock bajo no puede ser negativa';
        }
        
        if ($thresholds['notification_frequency_limit'] < 1) {
            $errors[] = 'Límite de frecuencia de notificaciones debe ser al menos 1';
        }
        
        // Validar retención
        $retention = self::get_notification_retention_days();
        if ($retention < 1 || $retention > 365) {
            $errors[] = 'Días de retención deben estar entre 1 y 365';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'warnings' => $warnings
        ];
    }
}
