<?php
declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

use Exception;
use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;

/**
 * SyncEntityValidator - Validador de entidades y direcciones de sincronización
 *
 * Esta clase se encarga de validar los parámetros de sincronización para las distintas
 * entidades del sistema (productos, clientes, pedidos, etc.) y las direcciones de sincronización
 * (hacia/desde Verial). Extiende SyncValidator implementando el patrón Template Method
 * para la validación en múltiples pasos.
 *
 * @package     MiIntegracionApi\Core\Validation
 * @category    Validation
 * @since       1.0.0
 * @see         SyncValidator
 * @version     1.0.0
 * @author      [Author Name] <[author@example.com]>
 * @license     [License Name] https://opensource.org/licenses/[License-Short-Name]
 * @link        [Documentation Link]
 */
class SyncEntityValidator extends SyncValidator
{
    /**
     * Entidades del sistema que pueden ser sincronizadas con la API de Verial
     *
     * Este mapa define todas las entidades del sistema que pueden ser sincronizadas,
     * junto con una descripción legible de cada una. Estas entidades se utilizan
     * en los procesos de sincronización para validar y procesar los datos.
     *
     * @var array<string, string> Mapa de entidades soportadas donde:
     *   - Clave (string): Identificador único de la entidad, usado internamente
     *   - Valor (string): Descripción legible para propósitos de documentación
     *
     * @example
     * // Verificar si una entidad es soportada
     * if (array_key_exists($entity, self::SUPPORTED_ENTITIES)) {
     *     // La entidad es válida
     * }
     *
     * @see SyncEntityValidator::validateSpecificRules() Donde se utiliza para validación
     * @since 1.0.0
     * @version 1.0.0
     */
    private const SUPPORTED_ENTITIES = [
        'products' => 'Productos/artículos del catálogo',
        'clients' => 'Información de clientes',
        'orders' => 'Pedidos/ventas',
        'categories' => 'Categorías de productos',
        'geo' => 'Datos geográficos',
        'config' => 'Configuración del sistema',
        'media' => 'Archivos multimedia'
    ];

    /**
     * Direcciones de sincronización soportadas entre Verial y WooCommerce
     *
     * Define los posibles sentidos de sincronización permitidos en la integración,
     * especificando la dirección del flujo de datos entre los sistemas.
     *
     * @var array<string, string> Mapa de direcciones soportadas donde:
     *   - Clave (string): Identificador único de la dirección de sincronización
     *   - Valor (string): Descripción legible de la dirección
     *
     * @example
     * // Verificar si una dirección de sincronización es soportada
     * if (in_array($direction, array_keys(self::SUPPORTED_DIRECTIONS), true)) {
     *     // La dirección es válida
     * }
     *
     * @see SyncEntityValidator::validateSpecificRules() Donde se valida la dirección
     * @see SyncEntityValidator::SUPPORTED_ENTITIES Para las entidades sincronizables
     * @since 1.0.0
     * @version 1.0.0
     */
    private const SUPPORTED_DIRECTIONS = [
        'verial_to_wc' => 'Sincronización desde Verial hacia WooCommerce',
        'wc_to_verial' => 'Sincronización desde WooCommerce hacia Verial'
    ];

    /**
     * Valida la estructura básica de los datos de sincronización
     *
     * Realiza una validación inicial de la estructura del array de datos, asegurando que:
     * 1. El array no esté vacío
     * 2. Contenga los campos obligatorios 'entity' y 'direction'
     *
     * @param array<string, mixed> $data Datos a validar con la siguiente estructura esperada:
     *   - 'entity' (string): Identificador de la entidad a sincronizar
     *   - 'direction' (string): Dirección de la sincronización
     *   - 'filters' (array, opcional): Filtros adicionales para la sincronización
     *
     * @return void No retorna ningún valor, pero registra errores a través de addError()
     *
     * @throws \InvalidArgumentException Si la estructura de datos es inválida o faltan campos requeridos
     *
     * @example
     * ```php
     * $data = [
     *     'entity' => 'products',
     *     'direction' => 'verial_to_wc'
     * ];
     * $validator->validateStructure($data);
     * ```
     *
     * @see SyncEntityValidator::SUPPORTED_ENTITIES Para las entidades válidas
     * @see SyncEntityValidator::SUPPORTED_DIRECTIONS Para las direcciones válidas
     * @since 1.0.0
     * @version 1.0.0
     */
    protected function validateStructure(array $data): void
    {
        if (empty($data)) {
            $this->addError('data', 'Los datos de sincronización no pueden estar vacíos');
            return;
        }

        // Validar que contenga entity y direction
        if (!isset($data['entity'])) {
            $this->addError('entity', 'El campo "entity" es requerido');
        }

        if (!isset($data['direction'])) {
            $this->addError('direction', 'El campo "direction" es requerido');
        }
    }

    /**
     * Valida que los campos obligatorios tengan valores no vacíos
     *
     * Este método verifica que los campos definidos como obligatorios:
     * 1. Existan en el array de datos
     * 2. Tengan un valor que no sea considerado vacío según PHP (empty())
     * 
     * Los campos obligatorios actuales son:
     * - 'entity': Identificador de la entidad a sincronizar
     * - 'direction': Dirección de la sincronización
     *
     * @param array<string, mixed> $data Datos a validar. Debe contener al menos:
     *   - 'entity' (string): No debe estar vacío
     *   - 'direction' (string): No debe estar vacío
     *
     * @return void No retorna ningún valor, pero registra errores a través de addError()
     *              para cada campo obligatorio que esté vacío
     *
     * @example
     * ```php
     * // Datos válidos
     * $data = [
     *     'entity' => 'products',
     *     'direction' => 'verial_to_wc'
     * ];
     * $validator->validateRequiredFields($data); // No genera errores
     * 
     * // Datos inválidos
     * $invalidData = [
     *     'entity' => '',
     *     'direction' => 'verial_to_wc'
     * ];
     * $validator->validateRequiredFields($invalidData); // Genera error para 'entity'
     * ```
     *
     * @see empty() Para la definición de valores considerados vacíos en PHP
     * @since 1.0.0
     * @version 1.0.0
     */
    protected function validateRequiredFields(array $data): void
    {
        $required = ['entity', 'direction'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                $this->addError($field, "El campo '$field' es obligatorio");
            }
        }
    }

    /**
     * Valida los tipos de datos de los campos proporcionados
     *
     * Este método realiza una validación de tipos estricta sobre los campos del array de entrada,
     * asegurando que cada campo tenga el tipo de dato esperado según la especificación.
     *
     * Validaciones realizadas:
     * 1. 'entity' (opcional): Debe ser de tipo string si está presente
     * 2. 'direction' (opcional): Debe ser de tipo string si está presente
     * 3. 'filters' (opcional): Debe ser de tipo array si está presente
     *
     * @param array<string, mixed> $data Datos a validar con los siguientes campos opcionales:
     *   - 'entity' (string|null): Identificador de la entidad
     *   - 'direction' (string|null): Dirección de sincronización
     *   - 'filters' (array|null, opcional): Filtros adicionales
     *
     * @return void No retorna ningún valor, pero registra errores a través de addError()
     *              para cada campo con tipo de dato incorrecto
     *
     * @throws \TypeError Si algún campo no cumple con el tipo de dato esperado
     *
     * @example
     * ```php
     * // Datos con tipos correctos
     * $validData = [
     *     'entity' => 'products',
     *     'direction' => 'verial_to_wc',
     *     'filters' => ['category' => 'electronics']
     * ];
     * $validator->validateDataTypes($validData); // No genera errores
     *
     * // Datos con tipos incorrectos
     * $invalidData = [
     *     'entity' => 123, // Debería ser string
     *     'direction' => ['verial_to_wc'], // Debería ser string
     *     'filters' => 'category=electronics' // Debería ser array
     * ];
     * $validator->validateDataTypes($invalidData); // Genera errores para todos los campos
     * ```
     *
     * @see is_string() Para la validación de cadenas
     * @see is_array() Para la validación de arrays
     * @since 1.0.0
     * @version 1.0.0
     */
    protected function validateDataTypes(array $data): void
    {
        if (isset($data['entity']) && !is_string($data['entity'])) {
            $this->addError('entity', 'El campo "entity" debe ser una cadena de texto');
        }

        if (isset($data['direction']) && !is_string($data['direction'])) {
            $this->addError('direction', 'El campo "direction" debe ser una cadena de texto');
        }

        if (isset($data['filters']) && !is_array($data['filters'])) {
            $this->addError('filters', 'El campo "filters" debe ser un array');
        }
    }

    /**
     * Valida reglas específicas de negocio para las entidades y direcciones
     *
     * Este método implementa las reglas de validación específicas del dominio,
     * asegurando que los valores proporcionados sean coherentes con las reglas
     * de negocio definidas en las constantes de clase.
     *
     * Validaciones realizadas:
     * 1. Verifica que la entidad esté en la lista de entidades soportadas (SUPPORTED_ENTITIES)
     * 2. Verifica que la dirección esté en la lista de direcciones soportadas (SUPPORTED_DIRECTIONS)
     *
     * @param array<string, mixed> $data Datos a validar, que deben contener:
     *   - 'entity' (string, opcional): Identificador de la entidad a validar
     *   - 'direction' (string, opcional): Dirección de sincronización a validar
     *
     * @return void No retorna ningún valor, pero registra errores a través de addError()
     *              cuando se encuentran valores no soportados
     *
     * @throws \UnexpectedValueException Cuando los valores no coinciden con las constantes definidas
     *
     * @example
     * ```php
     * // Datos válidos
     * $validData = [
     *     'entity' => 'products',
     *     'direction' => 'verial_to_wc'
     * ];
     * $validator->validateSpecificRules($validData); // No genera errores
     *
     * // Datos inválidos
     * $invalidData = [
     *     'entity' => 'non_existent_entity',
     *     'direction' => 'invalid_direction'
     * ];
     * $validator->validateSpecificRules($invalidData); // Genera errores para ambos campos
     * ```
     *
     * @see SyncEntityValidator::SUPPORTED_ENTITIES Para la lista completa de entidades soportadas
     * @see SyncEntityValidator::SUPPORTED_DIRECTIONS Para la lista completa de direcciones soportadas
     * @since 1.0.0
     * @version 1.0.0
     */
    protected function validateSpecificRules(array $data): void
    {
        // Validar entidad
        if (isset($data['entity']) && is_string($data['entity'])) {
            if (!in_array($data['entity'], array_keys(self::SUPPORTED_ENTITIES), true)) {
                $this->addError('entity', 
                    sprintf('Entidad "%s" no soportada. Entidades válidas: %s', 
                        $data['entity'], 
                        implode(', ', array_keys(self::SUPPORTED_ENTITIES))
                    )
                );
            }
        }

        // Validar dirección
        if (isset($data['direction']) && is_string($data['direction'])) {
            if (!in_array($data['direction'], array_keys(self::SUPPORTED_DIRECTIONS), true)) {
                $this->addError('direction', 
                    sprintf('Dirección "%s" no soportada. Direcciones válidas: %s', 
                        $data['direction'], 
                        implode(', ', array_keys(self::SUPPORTED_DIRECTIONS))
                    )
                );
            }
        }
    }

    /**
     * Valida las relaciones y dependencias entre los campos de datos
     *
     * Este método implementa reglas de validación que dependen de la relación
     * entre diferentes campos, como restricciones específicas de entidad-dirección.
     *
     * Actualmente valida:
     * 1. Restricciones de dirección por entidad:
     *    - 'geo': Solo permite sincronización desde Verial (verial_to_wc)
     *    - 'config': Solo permite sincronización desde Verial (verial_to_wc)
     *
     * @param array<string, mixed> $data Datos a validar, que deben contener:
     *   - 'entity' (string, opcional): Identificador de la entidad
     *   - 'direction' (string, opcional): Dirección de sincronización
     *
     * @return void No retorna ningún valor, pero registra errores a través de addError()
     *              cuando se encuentran relaciones inválidas entre campos
     *
     * @throws \DomainException Cuando se detecta una combinación de campos no permitida
     *
     * @example
     * ```php
     * // Combinación válida
     * $validData = [
     *     'entity' => 'products',
     *     'direction' => 'verial_to_wc' // Productos pueden sincronizarse en ambas direcciones
     * ];
     * $validator->validateRelationships($validData); // No genera errores
     *
     * // Combinación inválida
     * $invalidData = [
     *     'entity' => 'geo',
     *     'direction' => 'wc_to_verial' // Datos geo solo pueden sincronizarse desde Verial
     * ];
     * $validator->validateRelationships($invalidData); // Genera error
     * ```
     *
     * @see SyncEntityValidator::SUPPORTED_ENTITIES Para las entidades soportadas
     * @see SyncEntityValidator::SUPPORTED_DIRECTIONS Para las direcciones soportadas
     * @since 1.0.0
     * @version 1.0.0
     */
    protected function validateRelationships(array $data): void
    {
        // Validar que la combinación entity + direction sea válida
        if (isset($data['entity']) && isset($data['direction'])) {
            // Algunas entidades pueden no soportar ciertas direcciones
            $restrictions = [
                'geo' => ['verial_to_wc'], // Solo desde Verial
                'config' => ['verial_to_wc'], // Solo desde Verial
            ];

            if (isset($restrictions[$data['entity']]) && 
                !in_array($data['direction'], $restrictions[$data['entity']], true)) {
                $this->addError('direction', 
                    sprintf('La entidad "%s" no soporta la dirección "%s"', 
                        $data['entity'], 
                        $data['direction']
                    )
                );
            }
        }
    }

    /**
     * Valida los límites de longitud y restricciones de tamaño en los campos
     *
     * Este método se encarga de aplicar restricciones de longitud máxima a los
     * campos de texto, asegurando que no excedan los límites permitidos.
     *
     * Límites actuales:
     * - 'entity': Máximo 50 caracteres
     * - 'direction': Máximo 50 caracteres
     *
     * @param array<string, mixed> $data Datos a validar, que pueden contener:
     *   - 'entity' (string, opcional): Identificador de la entidad (max 50 caracteres)
     *   - 'direction' (string, opcional): Dirección de sincronización (max 50 caracteres)
     *
     * @return void No retorna ningún valor, pero registra errores a través de addError()
     *              cuando se superan los límites establecidos
     *
     * @throws \LengthException Cuando algún campo excede la longitud máxima permitida
     *
     * @example
     * ```php
     * // Datos dentro de los límites
     * $validData = [
     *     'entity' => 'products', // 8 caracteres < 50
     *     'direction' => 'verial_to_wc' // 13 caracteres < 50
     * ];
     * $validator->validateLimits($validData); // No genera errores
     *
     * // Datos que exceden los límites
     * $invalidData = [
     *     'entity' => str_repeat('a', 51), // 51 caracteres > 50
     *     'direction' => str_repeat('b', 55) // 55 caracteres > 50
     * ];
     * $validator->validateLimits($invalidData); // Genera errores para ambos campos
     * ```
     *
     * @see strlen() Función utilizada para calcular la longitud de las cadenas
     * @since 1.0.0
     * @version 1.0.0
     */
    protected function validateLimits(array $data): void
    {
        // Validar longitud de strings
        if (isset($data['entity']) && strlen($data['entity']) > 50) {
            $this->addError('entity', 'El nombre de la entidad no puede exceder 50 caracteres');
        }

        if (isset($data['direction']) && strlen($data['direction']) > 50) {
            $this->addError('direction', 'El nombre de la dirección no puede exceder 50 caracteres');
        }
    }

    /**
     * Obtiene la lista de identificadores de entidades soportadas para sincronización
     *
     * Este método devuelve un array con las claves de las entidades definidas en la constante
     * SUPPORTED_ENTITIES, que representan los tipos de datos que pueden ser sincronizados
     * a través de la API.
     *
     * @return array<string> Array indexado con los identificadores de las entidades soportadas.
     *                      Cada elemento es una cadena que identifica un tipo de entidad.
     *
     * @example
     * ```php
     * $entidades = SyncEntityValidator::getSupportedEntities();
     * // Ejemplo de resultado:
     * // ['products', 'clients', 'orders', 'categories', 'geo', 'config', 'media']
     * 
     * // Verificar si una entidad está soportada:
     * if (in_array('products', SyncEntityValidator::getSupportedEntities(), true)) {
     *     // La entidad 'products' está soportada
     * }
     * ```
     *
     * @see SyncEntityValidator::SUPPORTED_ENTITIES Para obtener las entidades con sus descripciones
     * @see SyncEntityValidator::validateEntityAndDirection() Para validar una entidad específica
     * @since 1.0.0
     * @version 1.0.0
     */
    public static function getSupportedEntities(): array
    {
        return array_keys(self::SUPPORTED_ENTITIES);
    }

    /**
     * Obtiene la lista de direcciones de sincronización soportadas
     *
     * Este método devuelve un array con las claves de las direcciones definidas en la constante
     * SUPPORTED_DIRECTIONS, que representan los sentidos de sincronización permitidos
     * entre los sistemas integrados.
     *
     * @return array<string> Array indexado con los identificadores de las direcciones soportadas.
     *                      Cada elemento es una cadena que identifica una dirección de sincronización.
     *
     * @example
     * ```php
     * $direcciones = SyncEntityValidator::getSupportedDirections();
     * // Ejemplo de resultado:
     * // ['verial_to_wc', 'wc_to_verial']
     * 
     * // Verificar si una dirección está soportada:
     * if (in_array('verial_to_wc', SyncEntityValidator::getSupportedDirections(), true)) {
     *     // La dirección 'verial_to_wc' está soportada
     * }
     * ```
     *
     * @see SyncEntityValidator::SUPPORTED_DIRECTIONS Para obtener las direcciones con sus descripciones
     * @see SyncEntityValidator::validateEntityAndDirection() Para validar una dirección específica
     * @since 1.0.0
     * @version 1.0.0
     */
    public static function getSupportedDirections(): array
    {
        return array_keys(self::SUPPORTED_DIRECTIONS);
    }

    /**
     * Valida una combinación de entidad y dirección de sincronización de forma estática
     *
     * Este método proporciona una interfaz estática y conveniente para validar
     * rápidamente si una combinación específica de entidad y dirección es válida
     * según las reglas de negocio definidas en la clase.
     *
     * @param string $entity Identificador de la entidad a validar (ej: 'products', 'clients')
     * @param string $direction Dirección de sincronización a validar (ej: 'verial_to_wc', 'wc_to_verial')
     *
     * @return bool `true` si la combinación es válida, `false` en caso contrario
     *
     * @throws \InvalidArgumentException Si los parámetros no son cadenas de texto
     * @throws \Exception Si ocurre un error durante la validación
     *
     * @example
     * ```php
     * // Validación exitosa
     * if (SyncEntityValidator::validateEntityAndDirection('products', 'verial_to_wc')) {
     *     echo 'La combinación es válida';
     * }
     * 
     * // Validación fallida
     * if (!SyncEntityValidator::validateEntityAndDirection('entidad_inexistente', 'direccion_invalida')) {
     *     echo 'La combinación no es válida';
     * }
     * ```
     *
     * @see SyncEntityValidator::getSupportedEntities() Para obtener la lista de entidades soportadas
     * @see SyncEntityValidator::getSupportedDirections() Para obtener la lista de direcciones soportadas
     * @since 1.0.0
     * @version 1.0.0
     */
    public static function validateEntityAndDirection(string $entity, string $direction): bool
    {
        try {
            $validator = new self();
            return $validator->validate(['entity' => $entity, 'direction' => $direction]);
        } catch (SyncError) {
            return false;
        }
    }
}