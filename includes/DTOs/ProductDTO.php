<?php
/**
 * Data Transfer Object (DTO) para productos
 *
 * Este DTO representa un producto en el sistema, proporcionando una capa de abstracción
 * para la validación y manipulación de datos de productos. Extiende de BaseDTO
 * para heredar funcionalidades comunes de validación y manejo de datos.
 *
 * @package    MiIntegracionApi
 * @subpackage DTOs
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\DTOs;

/**
 * Clase ProductDTO
 *
 * Representa un producto en el sistema con sus propiedades y reglas de validación.
 * Incluye métodos para acceder y manipular los datos del producto de forma segura.
 *
 * @package MiIntegracionApi\DTOs
 * @since   1.0.0
 */
class ProductDTO extends BaseDTO {
    /**
     * Esquema de validación para el DTO de producto
     *
     * Define las reglas de validación, tipos de datos y requisitos
     * para cada propiedad del producto.
     *
     * @var array<string, array<string, mixed>>
     * @since 1.0.0
     */
    protected static $schema = [
        'id' => [
            'type' => 'integer',
            'required' => false
        ],
        'name' => [
            'type' => 'string',
            'required' => true,
            'min' => 1
        ],
        'sku' => [
            'type' => 'string',
            'required' => true,
            'pattern' => '/^[A-Za-z0-9-_]+$/'
        ],
        'price' => [
            'type' => 'float',
            'required' => true,
            'min' => 0
        ],
        'regular_price' => [
            'type' => 'float',
            'required' => false,
            'min' => 0
        ],
        'sale_price' => [
            'type' => 'float',
            'required' => false,
            'min' => 0
        ],
        'description' => [
            'type' => 'string',
            'required' => false
        ],
        'short_description' => [
            'type' => 'string',
            'required' => false
        ],
        'categories' => [
            'type' => 'array',
            'required' => false
        ],
        'tags' => [
            'type' => 'array',
            'required' => false
        ],
        'images' => [
            'type' => 'array',
            'required' => false
        ],
        'stock_quantity' => [
            'type' => 'integer',
            'required' => false,
            'min' => 0
        ],
        'stock_status' => [
            'type' => 'string',
            'required' => false,
            'enum' => ['instock', 'outofstock', 'onbackorder']
        ],
        'weight' => [
            'type' => 'float',
            'required' => false,
            'min' => 0
        ],
        'dimensions' => [
            'type' => 'object',
            'required' => false
        ],
        'attributes' => [
            'type' => 'array',
            'required' => false
        ],
        'status' => [
            'type' => 'string',
            'required' => false,
            'enum' => ['draft', 'pending', 'private', 'publish']
        ],
        'external_id' => [
            'type' => 'string',
            'required' => false
        ],
        'sync_status' => [
            'type' => 'string',
            'required' => false,
            'enum' => ['pending', 'synced', 'failed']
        ],
        'last_sync' => [
            'type' => 'string',
            'required' => false
        ]
    ];

    /**
     * Obtiene el ID único del producto
     *
     * @return int|null ID del producto o null si no está definido
     * @since 1.0.0
     */
    public function getId(): ?int {
        return $this->get('id');
    }

    /**
     * Obtiene el nombre del producto
     *
     * @return string Nombre del producto
     * @throws \RuntimeException Si el nombre no está definido
     * @since 1.0.0
     */
    public function getName(): string {
        return $this->get('name');
    }

    /**
     * Obtiene el SKU (Stock Keeping Unit) del producto
     *
     * El SKU debe coincidir con el patrón: /^[A-Za-z0-9-_]+$/
     *
     * @return string SKU del producto
     * @throws \RuntimeException Si el SKU no está definido o no es válido
     * @since 1.0.0
     */
    public function getSku(): string {
        return $this->get('sku');
    }

    /**
     * Obtiene el precio actual del producto
     *
     * Si está definido un precio de oferta (sale_price), este método
     * devolverá ese valor en lugar del precio regular.
     *
     * @return float Precio del producto (siempre mayor o igual a 0)
     * @throws \RuntimeException Si el precio no está definido o es inválido
     * @since 1.0.0
     */
    public function getPrice(): float {
        return $this->get('price');
    }

    /**
     * Obtiene el precio regular del producto
     *
     * @return float|null Precio regular o null si no está definido
     * @since 1.0.0
     */
    public function getRegularPrice(): ?float {
        return $this->get('regular_price');
    }

    /**
     * Obtiene el precio de oferta del producto
     *
     * @return float|null Precio de oferta o null si no hay oferta activa
     * @since 1.0.0
     */
    public function getSalePrice(): ?float {
        return $this->get('sale_price');
    }

    /**
     * Obtiene la descripción completa del producto
     *
     * Puede contener HTML para formateo avanzado.
     *
     * @return string|null Descripción del producto o null si no está definida
     * @since 1.0.0
     */
    public function getDescription(): ?string {
        return $this->get('description');
    }

    /**
     * Obtiene la descripción corta del producto
     *
     * Ideal para vistas resumidas o listados de productos.
     *
     * @return string|null Descripción corta o null si no está definida
     * @since 1.0.0
     */
    public function getShortDescription(): ?string {
        return $this->get('short_description');
    }

    /**
     * Obtiene las categorías del producto
     *
     * @return array<array<string, mixed>>|null Array de categorías o null si no hay categorías definidas
     * @since 1.0.0
     */
    public function getCategories(): ?array {
        return $this->get('categories');
    }

    /**
     * Obtiene las etiquetas del producto
     *
     * @return array<array<string, mixed>>|null Array de etiquetas o null si no hay etiquetas definidas
     * @since 1.0.0
     */
    public function getTags(): ?array {
        return $this->get('tags');
    }

    /**
     * Obtiene las imágenes asociadas al producto
     *
     * Cada imagen en el array puede contener las siguientes claves:
     * - src: URL de la imagen
     * - alt: Texto alternativo
     * - position: Posición de la imagen en la galería
     *
     * @return array<array<string, mixed>>|null Array de imágenes o null si no hay imágenes definidas
     * @since 1.0.0
     */
    public function getImages(): ?array {
        return $this->get('images');
    }

    /**
     * Obtiene la cantidad disponible en stock del producto
     *
     * @return int|null Cantidad en stock o null si no está definida
     * @since 1.0.0
     */
    public function getStockQuantity(): ?int {
        return $this->get('stock_quantity');
    }

    /**
     * Obtiene el estado de stock del producto
     *
     * Valores posibles:
     * - 'instock': En stock
     * - 'outofstock': Sin stock
     * - 'onbackorder': Bajo pedido
     *
     * @return string|null Estado del stock o null si no está definido
     * @since 1.0.0
     */
    public function getStockStatus(): ?string {
        return $this->get('stock_status');
    }

    /**
     * Obtiene el peso del producto en la unidad configurada
     *
     * @return float|null Peso del producto o null si no está definido
     * @since 1.0.0
     */
    public function getWeight(): ?float {
        return $this->get('weight');
    }

    /**
     * Obtiene las dimensiones físicas del producto
     *
     * El array devuelto puede contener las siguientes claves:
     * - length: Largo
     * - width: Ancho
     * - height: Alto
     * - unit: Unidad de medida (cm, m, in, etc.)
     *
     * @return array{length?: float, width?: float, height?: float, unit?: string}|null
     *         Dimensiones del producto o null si no están definidas
     * @since 1.0.0
     */
    public function getDimensions(): ?object {
        return $this->get('dimensions');
    }

    /**
     * Obtiene los atributos personalizados del producto
     *
     * Cada atributo es un array con la siguiente estructura:
     * - id: ID del atributo (opcional)
     * - name: Nombre del atributo
     * - position: Posición del atributo (opcional)
     * - visible: Si el atributo es visible en la ficha del producto
     * - variation: Si el atributo se usa para variaciones
     * - options: Array de opciones del atributo
     *
     * @return array<array{id?: int, name: string, position?: int, visible: bool, variation: bool, options: string[]}>|null
     *         Array de atributos o null si no hay atributos definidos
     * @since 1.0.0
     */
    public function getAttributes(): ?array {
        return $this->get('attributes');
    }

    /**
     * Obtiene el estado de publicación del producto
     *
     * Valores posibles:
     * - 'draft': Borrador
     * - 'pending': Pendiente de revisión
     * - 'private': Privado
     * - 'publish': Publicado
     *
     * @return 'draft'|'pending'|'private'|'publish'|null Estado del producto o null si no está definido
     * @since 1.0.0
     */
    public function getStatus(): ?string {
        return $this->get('status');
    }

    /**
     * Obtiene el ID del producto en un sistema externo
     *
     * Útil para integraciones con sistemas de gestión de inventario o ERPs.
     *
     * @return string|null ID externo del producto o null si no está definido
     * @since 1.0.0
     */
    public function getExternalId(): ?string {
        return $this->get('external_id');
    }

    /**
     * Obtiene el estado de sincronización del producto con sistemas externos
     *
     * Valores posibles:
     * - 'pending': Pendiente de sincronizar
     * - 'synced': Sincronizado correctamente
     * - 'failed': Error en la sincronización
     *
     * @return 'pending'|'synced'|'failed'|null Estado de sincronización o null si no está definido
     * @since 1.0.0
     */
    public function getSyncStatus(): ?string {
        return $this->get('sync_status');
    }

    /**
     * Obtiene la fecha y hora de la última sincronización
     *
     * El formato de la fecha es una cadena compatible con strtotime().
     *
     * @return string|null Fecha de la última sincronización o null si nunca se ha sincronizado
     * @since 1.0.0
     */
    public function getLastSync(): ?string {
        return $this->get('last_sync');
    }
}