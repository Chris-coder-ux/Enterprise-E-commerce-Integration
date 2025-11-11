<?php
namespace MiIntegracionApi\Constants;

/**
 * Constantes de Tipos de Verial
 * 
 * Este archivo centraliza todas las constantes relacionadas con tipos de datos
 * específicos de la API de Verial para evitar duplicaciones y hardcodeos.
 * 
 * @package     MiIntegracionApi
 * @subpackage  Constants
 * @version     1.0.0
 * @since       1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase que contiene todas las constantes de tipos de Verial
 */
class VerialTypes
{
    // ========================================
    // TIPOS DE DOCUMENTO
    // ========================================
    
    /**
     * Tipo de documento: Factura
     */
    const DOCUMENT_TYPE_INVOICE = 1;
    
    /**
     * Tipo de documento: Albarán de venta
     */
    const DOCUMENT_TYPE_DELIVERY_NOTE = 3;
    
    /**
     * Tipo de documento: Factura simplificada
     */
    const DOCUMENT_TYPE_SIMPLIFIED_INVOICE = 4;
    
    /**
     * Tipo de documento: Pedido
     */
    const DOCUMENT_TYPE_ORDER = 5;
    
    /**
     * Tipo de documento: Presupuesto
     */
    const DOCUMENT_TYPE_QUOTE = 6;
    
    // ========================================
    // TIPOS DE CLIENTE
    // ========================================
    
    /**
     * Tipo de cliente: Particular
     */
    const CUSTOMER_TYPE_INDIVIDUAL = 1;
    
    /**
     * Tipo de cliente: Empresa
     */
    const CUSTOMER_TYPE_COMPANY = 2;
    
    // ========================================
    // TIPOS DE REGISTRO
    // ========================================
    
    /**
     * Tipo de registro: Producto
     */
    const REGISTRY_TYPE_PRODUCT = 1;
    
    /**
     * Tipo de registro: Comentario
     */
    const REGISTRY_TYPE_COMMENT = 2;
    
    // ========================================
    // TIPOS DE PORTES
    // ========================================
    
    /**
     * Tipo de portes: Incluidos
     */
    const SHIPPING_TYPE_INCLUDED = 1;
    
    /**
     * Tipo de portes: Pagados
     */
    const SHIPPING_TYPE_PAID = 2;
    
    // ========================================
    // TIPOS DE DATOS (CAMPOS CONFIGURABLES)
    // ========================================
    
    /**
     * Tipo de dato: Texto
     */
    const DATA_TYPE_TEXT = 1;
    
    /**
     * Tipo de dato: Número
     */
    const DATA_TYPE_NUMBER = 2;
    
    /**
     * Tipo de dato: Decimal
     */
    const DATA_TYPE_DECIMAL = 3;
    
    /**
     * Tipo de dato: Fecha
     */
    const DATA_TYPE_DATE = 4;
    
    /**
     * Tipo de dato: Lógico (Boolean)
     */
    const DATA_TYPE_BOOLEAN = 5;
    
    /**
     * Tipo de dato: JSON
     */
    const DATA_TYPE_JSON = 6;
    
    /**
     * Tipo de dato: Array de JSON
     */
    const DATA_TYPE_ARRAY_JSON = 7;
    
    // ========================================
    // CÓDIGOS DE ERROR DE VERIAL
    // ========================================
    
    /**
     * Todo correcto, no hay error
     */
    const VERIAL_ERROR_SUCCESS = 0;
    
    /**
     * Error iniciando la sesión
     */
    const VERIAL_ERROR_INVALID_SESSION = 1;
    
    /**
     * Error realizando consultas SQL
     */
    const VERIAL_ERROR_SQL_QUERY = 2;
    
    /**
     * Formato del JSON de entrada incorrecto
     */
    const VERIAL_ERROR_INVALID_JSON_INPUT = 3;
    
    /**
     * Error componiendo el JSON de salida
     */
    const VERIAL_ERROR_JSON_OUTPUT = 4;
    
    /**
     * Configuración incorrecta en el programa de Verial
     */
    const VERIAL_ERROR_CONFIG = 5;
    
    /**
     * Hay más de un cliente con el mismo WebUser
     */
    const VERIAL_ERROR_DUPLICATE_WEBUSER = 6;
    
    /**
     * Cliente no encontrado
     */
    const VERIAL_ERROR_CLIENT_NOT_FOUND = 7;
    
    /**
     * Cliente inactivo en la web
     */
    const VERIAL_ERROR_CLIENT_INACTIVE = 8;
    
    /**
     * Error creando el nuevo cliente
     */
    const VERIAL_ERROR_CREATE_CLIENT = 9;
    
    /**
     * Falta un dato requerido
     */
    const VERIAL_ERROR_MISSING_REQUIRED_DATA = 10;
    
    /**
     * Dato incorrecto
     */
    const VERIAL_ERROR_INVALID_DATA = 11;
    
    /**
     * Error creando un nuevo documento de cliente
     */
    const VERIAL_ERROR_CREATE_DOC = 12;
    
    /**
     * Modificación no permitida
     */
    const VERIAL_ERROR_MODIFICATION_NOT_ALLOWED = 13;
    
    /**
     * Error al comprobar si el pedido es modificable
     */
    const VERIAL_ERROR_CHECK_MODIFIABLE = 14;
    
    /**
     * El documento que se quiere modificar no se ha encontrado
     */
    const VERIAL_ERROR_DOC_NOT_FOUND = 15;
    
    /**
     * El importe total del documento no es correcto
     */
    const VERIAL_ERROR_TOTAL_AMOUNT_INCORRECT = 16;
    
    /**
     * Error guardando el documento de cliente en la base de datos
     */
    const VERIAL_ERROR_SAVING_DOC_DB = 17;
    
    /**
     * Ya existe un documento con el mismo número en el mismo ejercicio
     */
    const VERIAL_ERROR_DUPLICATE_DOC_NUMBER = 18;
    
    /**
     * Se está intentando crear un documento con número y falta el anterior
     */
    const VERIAL_ERROR_MISSING_PREVIOUS_DOC = 19;
    
    /**
     * Módulo no contratado. No tiene permiso para usar el Servicio Web
     */
    const VERIAL_ERROR_MODULE_NOT_CONTRACTED = 20;
    
    /**
     * Error creando un pago
     */
    const VERIAL_ERROR_CREATE_PAYMENT = 21;
    
    /**
     * Formato de fecha incorrecto
     */
    const VERIAL_ERROR_INVALID_DATE_FORMAT = 22;
    
    /**
     * Alta de nuevo registro no permitida
     */
    const VERIAL_ERROR_NEW_RECORD_NOT_ALLOWED = 23;
    
    // ========================================
    // LÍMITES DE LONGITUD
    // ========================================
    
    /**
     * Límite de longitud para nombre
     */
    const MAX_LENGTH_NOMBRE = 50;
    
    /**
     * Límite de longitud para apellido
     */
    const MAX_LENGTH_APELLIDO = 50;
    
    /**
     * Límite de longitud para NIF/DNI
     */
    const MAX_LENGTH_NIF = 20;
    
    /**
     * Límite de longitud para razón social
     */
    const MAX_LENGTH_RAZON_SOCIAL = 50;
    
    /**
     * Límite de longitud para dirección
     */
    const MAX_LENGTH_DIRECCION = 75;
    
    /**
     * Límite de longitud para código postal
     */
    const MAX_LENGTH_CP = 10;
    
    /**
     * Límite de longitud para teléfono
     */
    const MAX_LENGTH_TELEFONO = 20;
    
    /**
     * Límite de longitud para email
     */
    const MAX_LENGTH_EMAIL = 100;
    
    /**
     * Límite de longitud para usuario web
     */
    const MAX_LENGTH_WEB_USER = 100;
    
    /**
     * Límite de longitud para contraseña web
     */
    const MAX_LENGTH_WEB_PASS = 50;
    
    /**
     * Límite de longitud para referencia
     */
    const MAX_LENGTH_REFERENCIA = 40;
    
    /**
     * Límite de longitud para comentario
     */
    const MAX_LENGTH_COMENTARIO = 255;
    
    /**
     * Límite de longitud para campo auxiliar
     */
    const MAX_LENGTH_AUX = 50;
    
    /**
     * Límite de longitud para provincia manual
     */
    const MAX_LENGTH_PROVINCIA_MANUAL = 50;
    
    /**
     * Límite de longitud para localidad manual
     */
    const MAX_LENGTH_LOCALIDAD_MANUAL = 100;
    
    /**
     * Límite de longitud para localidad auxiliar
     */
    const MAX_LENGTH_LOCALIDAD_AUX = 50;
    
    /**
     * Límite de longitud para código NUTS
     */
    const MAX_LENGTH_CODIGO_NUTS = 5;
    
    /**
     * Límite de longitud para código municipio INE
     */
    const MAX_LENGTH_CODIGO_MUNICIPIO_INE = 5;
    
    /**
     * Límite de longitud para cargo
     */
    const MAX_LENGTH_CARGO = 50;
    
    /**
     * Límite de longitud para etiqueta cliente
     */
    const MAX_LENGTH_ETIQUETA_CLIENTE = 500;
    
    /**
     * Límite de longitud para descripción documento
     */
    const MAX_LENGTH_DESCRIPCION_DOC = 100;
    
    /**
     * Límite de longitud para comentario línea
     */
    const MAX_LENGTH_COMENTARIO_LINEA = 100;
    
    /**
     * Límite de longitud para descripción amplia línea
     */
    const MAX_LENGTH_DESCRIPCION_AMPLIA_LINEA = 250;
    
    /**
     * Límite de longitud para concepto línea
     */
    const MAX_LENGTH_CONCEPTO_LINEA = 100;
    
    /**
     * Límite de longitud para observaciones
     */
    const MAX_LENGTH_OBSERVACIONES = 255;
    
    // ========================================
    // MÉTODOS DE UTILIDAD
    // ========================================
    
    /**
     * Obtiene todos los tipos de documento válidos
     * 
     * @return array Array con los tipos de documento
     */
    public static function getDocumentTypes(): array
    {
        return [
            self::DOCUMENT_TYPE_INVOICE,
            self::DOCUMENT_TYPE_DELIVERY_NOTE,
            self::DOCUMENT_TYPE_SIMPLIFIED_INVOICE,
            self::DOCUMENT_TYPE_ORDER,
            self::DOCUMENT_TYPE_QUOTE,
        ];
    }
    
    /**
     * Obtiene todos los tipos de cliente válidos
     * 
     * @return array Array con los tipos de cliente
     */
    public static function getCustomerTypes(): array
    {
        return [
            self::CUSTOMER_TYPE_INDIVIDUAL,
            self::CUSTOMER_TYPE_COMPANY,
        ];
    }
    
    /**
     * Obtiene todos los tipos de registro válidos
     * 
     * @return array Array con los tipos de registro
     */
    public static function getRegistryTypes(): array
    {
        return [
            self::REGISTRY_TYPE_PRODUCT,
            self::REGISTRY_TYPE_COMMENT,
        ];
    }
    
    /**
     * Obtiene todos los tipos de portes válidos
     * 
     * @return array Array con los tipos de portes
     */
    public static function getShippingTypes(): array
    {
        return [
            self::SHIPPING_TYPE_INCLUDED,
            self::SHIPPING_TYPE_PAID,
        ];
    }
    
    /**
     * Obtiene todos los tipos de datos válidos
     * 
     * @return array Array con los tipos de datos
     */
    public static function getDataTypes(): array
    {
        return [
            self::DATA_TYPE_TEXT,
            self::DATA_TYPE_NUMBER,
            self::DATA_TYPE_DECIMAL,
            self::DATA_TYPE_DATE,
            self::DATA_TYPE_BOOLEAN,
            self::DATA_TYPE_JSON,
            self::DATA_TYPE_ARRAY_JSON,
        ];
    }
    
    /**
     * Obtiene todos los códigos de error de Verial
     * 
     * @return array Array con los códigos de error
     */
    public static function getVerialErrors(): array
    {
        return [
            self::VERIAL_ERROR_SUCCESS,
            self::VERIAL_ERROR_INVALID_SESSION,
            self::VERIAL_ERROR_SQL_QUERY,
            self::VERIAL_ERROR_INVALID_JSON_INPUT,
            self::VERIAL_ERROR_JSON_OUTPUT,
            self::VERIAL_ERROR_CONFIG,
            self::VERIAL_ERROR_DUPLICATE_WEBUSER,
            self::VERIAL_ERROR_CLIENT_NOT_FOUND,
            self::VERIAL_ERROR_CLIENT_INACTIVE,
            self::VERIAL_ERROR_CREATE_CLIENT,
            self::VERIAL_ERROR_MISSING_REQUIRED_DATA,
            self::VERIAL_ERROR_INVALID_DATA,
            self::VERIAL_ERROR_CREATE_DOC,
            self::VERIAL_ERROR_MODIFICATION_NOT_ALLOWED,
            self::VERIAL_ERROR_CHECK_MODIFIABLE,
            self::VERIAL_ERROR_DOC_NOT_FOUND,
            self::VERIAL_ERROR_TOTAL_AMOUNT_INCORRECT,
            self::VERIAL_ERROR_SAVING_DOC_DB,
            self::VERIAL_ERROR_DUPLICATE_DOC_NUMBER,
            self::VERIAL_ERROR_MISSING_PREVIOUS_DOC,
            self::VERIAL_ERROR_MODULE_NOT_CONTRACTED,
            self::VERIAL_ERROR_CREATE_PAYMENT,
            self::VERIAL_ERROR_INVALID_DATE_FORMAT,
            self::VERIAL_ERROR_NEW_RECORD_NOT_ALLOWED,
        ];
    }
    
    /**
     * Obtiene todos los límites de longitud
     * 
     * @return array Array asociativo con los límites de longitud
     */
    public static function getMaxLengths(): array
    {
        return [
            'nombre' => self::MAX_LENGTH_NOMBRE,
            'apellido' => self::MAX_LENGTH_APELLIDO,
            'nif' => self::MAX_LENGTH_NIF,
            'razon_social' => self::MAX_LENGTH_RAZON_SOCIAL,
            'direccion' => self::MAX_LENGTH_DIRECCION,
            'cp' => self::MAX_LENGTH_CP,
            'telefono' => self::MAX_LENGTH_TELEFONO,
            'email' => self::MAX_LENGTH_EMAIL,
            'web_user' => self::MAX_LENGTH_WEB_USER,
            'web_pass' => self::MAX_LENGTH_WEB_PASS,
            'referencia' => self::MAX_LENGTH_REFERENCIA,
            'comentario' => self::MAX_LENGTH_COMENTARIO,
            'aux' => self::MAX_LENGTH_AUX,
            'provincia_manual' => self::MAX_LENGTH_PROVINCIA_MANUAL,
            'localidad_manual' => self::MAX_LENGTH_LOCALIDAD_MANUAL,
            'localidad_aux' => self::MAX_LENGTH_LOCALIDAD_AUX,
            'codigo_nuts' => self::MAX_LENGTH_CODIGO_NUTS,
            'codigo_municipio_ine' => self::MAX_LENGTH_CODIGO_MUNICIPIO_INE,
            'cargo' => self::MAX_LENGTH_CARGO,
            'etiqueta_cliente' => self::MAX_LENGTH_ETIQUETA_CLIENTE,
            'descripcion_doc' => self::MAX_LENGTH_DESCRIPCION_DOC,
            'comentario_linea' => self::MAX_LENGTH_COMENTARIO_LINEA,
            'descripcion_amplia_linea' => self::MAX_LENGTH_DESCRIPCION_AMPLIA_LINEA,
            'concepto_linea' => self::MAX_LENGTH_CONCEPTO_LINEA,
            'observaciones' => self::MAX_LENGTH_OBSERVACIONES,
        ];
    }
    
    /**
     * Verifica si un tipo de documento es válido
     * 
     * @param int $type Tipo de documento a verificar
     * @return bool True si es válido, false en caso contrario
     */
    public static function isValidDocumentType(int $type): bool
    {
        return in_array($type, self::getDocumentTypes(), true);
    }
    
    /**
     * Verifica si un tipo de cliente es válido
     * 
     * @param int $type Tipo de cliente a verificar
     * @return bool True si es válido, false en caso contrario
     */
    public static function isValidCustomerType(int $type): bool
    {
        return in_array($type, self::getCustomerTypes(), true);
    }
    
    /**
     * Verifica si un tipo de registro es válido
     * 
     * @param int $type Tipo de registro a verificar
     * @return bool True si es válido, false en caso contrario
     */
    public static function isValidRegistryType(int $type): bool
    {
        return in_array($type, self::getRegistryTypes(), true);
    }
    
    /**
     * Verifica si un tipo de portes es válido
     * 
     * @param int $type Tipo de portes a verificar
     * @return bool True si es válido, false en caso contrario
     */
    public static function isValidShippingType(int $type): bool
    {
        return in_array($type, self::getShippingTypes(), true);
    }
    
    /**
     * Verifica si un tipo de dato es válido
     * 
     * @param int $type Tipo de dato a verificar
     * @return bool True si es válido, false en caso contrario
     */
    public static function isValidDataType(int $type): bool
    {
        return in_array($type, self::getDataTypes(), true);
    }
    
    /**
     * Verifica si un código de error de Verial es válido
     * 
     * @param int $errorCode Código de error a verificar
     * @return bool True si es válido, false en caso contrario
     */
    public static function isValidVerialError(int $errorCode): bool
    {
        return in_array($errorCode, self::getVerialErrors(), true);
    }
}