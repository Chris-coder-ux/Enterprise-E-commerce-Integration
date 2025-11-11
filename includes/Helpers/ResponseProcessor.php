<?php declare(strict_types=1);

/**
 * Helper para procesar respuestas de la API de Verial
 * 
 * Proporciona métodos para normalizar y procesar respuestas de diferentes endpoints
 * de la API de Verial, convirtiendo las estructuras de datos a un formato estándar
 * y reutilizable para la integración con WooCommerce.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */

namespace MiIntegracionApi\Helpers;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para procesar respuestas de la API de Verial
 * 
 * Implementa métodos estáticos para procesar respuestas de diferentes endpoints
 * y normalizarlas a un formato consistente para su uso en la integración.
 *
 * @since 1.0.0
 */
class ResponseProcessor {
    
    /**
     * Logger estático para registrar eventos y errores
     * 
     * @var Logger|null
     */
    private static $logger = null;
    
    /**
     * Obtiene la instancia del logger
     * 
     * @return \MiIntegracionApi\Logging\Core\LoggerBasic
     */
    private static function getLogger(): \MiIntegracionApi\Logging\Core\LoggerBasic {
        if (self::$logger === null) {
            self::$logger = new Logger('response-processor');
        }
        return self::$logger;
    }
    
    /**
     * @deprecated Este método ha sido eliminado. Usar BatchProcessor::get_product_stock_from_batch() en su lugar.
     * 
     * @param mixed $stock_response Respuesta de la API de stock
     * @return array Array de stock procesado y normalizado
     */
    public static function processStockResponse($stock_response): array {
        // Redirigir a la lógica consolidada
        return \MiIntegracionApi\Core\BatchProcessor::get_product_stock_from_batch(0, ['stock_productos' => $stock_response], 0);
    }

    /**
     * Procesa respuesta de artículos en formato unificado
     * 
     * @param mixed $articulos_response Respuesta de la API de artículos
     * @return array Array de artículos procesado y normalizado
     */
    public static function processArticulosResponse($articulos_response): array {
        $articulos_list = [];
        
        if (is_array($articulos_response)) {
            // Formato 1: Respuesta con índice 'Articulos'
            if (isset($articulos_response['Articulos']) && is_array($articulos_response['Articulos'])) {
                foreach ($articulos_response['Articulos'] as $articulo) {
                    if (isset($articulo['Id']) || isset($articulo['id']) || isset($articulo['ID_Articulo'])) {
                        $articulos_list[] = [
                            'id' => $articulo['ID_Articulo'] ?? $articulo['Id'] ?? $articulo['id'] ?? '',
                            'nombre' => $articulo['Nombre'] ?? $articulo['nombre'] ?? '',
                            'sku' => $articulo['SKU'] ?? $articulo['sku'] ?? '',
                            'precio' => $articulo['Precio'] ?? $articulo['precio'] ?? 0,
                            'categoria_id' => $articulo['ID_Categoria'] ?? $articulo['categoria_id'] ?? '',
                            'fabricante_id' => $articulo['ID_Fabricante'] ?? $articulo['fabricante_id'] ?? ''
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de artículos
            elseif (isset($articulos_response[0]) && is_array($articulos_response[0])) {
                foreach ($articulos_response as $articulo) {
                    if (is_array($articulo) && (isset($articulo['Id']) || isset($articulo['id']) || isset($articulo['ID_Articulo']))) {
                        $articulos_list[] = [
                            'id' => $articulo['ID_Articulo'] ?? $articulo['Id'] ?? $articulo['id'] ?? '',
                            'nombre' => $articulo['Nombre'] ?? $articulo['nombre'] ?? '',
                            'sku' => $articulo['SKU'] ?? $articulo['sku'] ?? '',
                            'precio' => $articulo['Precio'] ?? $articulo['precio'] ?? 0,
                            'categoria_id' => $articulo['ID_Categoria'] ?? $articulo['categoria_id'] ?? '',
                            'fabricante_id' => $articulo['ID_Fabricante'] ?? $articulo['fabricante_id'] ?? ''
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Artículos procesados exitosamente', [
            'total_items' => count($articulos_list),
            'response_type' => is_array($articulos_response) ? 'array' : gettype($articulos_response)
        ]);
        
        return $articulos_list;
    }

    /**
     * Procesa respuesta de imágenes en formato unificado
     * 
     * @param mixed $imagenes_response Respuesta de la API de imágenes
     * @return array Array de imágenes procesado y normalizado
     */
    public static function processImagenesResponse($imagenes_response): array {
        $imagenes_list = [];
        
        if (is_array($imagenes_response)) {
            // Formato 1: Respuesta con índice 'Imagenes'
            if (isset($imagenes_response['Imagenes']) && is_array($imagenes_response['Imagenes'])) {
                foreach ($imagenes_response['Imagenes'] as $imagen) {
                    if (isset($imagen['Id']) || isset($imagen['id']) || isset($imagen['ID_Articulo']) || isset($imagen['URL'])) {
                        $imagenes_list[] = [
                            'id_articulo' => $imagen['ID_Articulo'] ?? $imagen['Id'] ?? $imagen['id'] ?? '',
                            'url' => $imagen['URL'] ?? $imagen['url'] ?? '',
                            'tipo' => $imagen['Tipo'] ?? $imagen['tipo'] ?? 'principal',
                            'orden' => $imagen['Orden'] ?? $imagen['orden'] ?? 1
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de imágenes
            elseif (isset($imagenes_response[0]) && is_array($imagenes_response[0])) {
                foreach ($imagenes_response as $imagen) {
                    if (is_array($imagen) && (isset($imagen['Id']) || isset($imagen['id']) || isset($imagen['ID_Articulo']) || isset($imagen['URL']))) {
                        $imagenes_list[] = [
                            'id_articulo' => $imagen['ID_Articulo'] ?? $imagen['Id'] ?? $imagen['id'] ?? '',
                            'url' => $imagen['URL'] ?? $imagen['url'] ?? '',
                            'tipo' => $imagen['Tipo'] ?? $imagen['tipo'] ?? 'principal',
                            'orden' => $imagen['Orden'] ?? $imagen['orden'] ?? 1
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Imágenes procesadas exitosamente', [
            'total_items' => count($imagenes_list),
            'response_type' => is_array($imagenes_response) ? 'array' : gettype($imagenes_response)
        ]);
        
        return $imagenes_list;
    }

    /**
     * Procesa respuesta de condiciones de tarifa en formato unificado
     * 
     * @param mixed $condiciones_response Respuesta de la API de condiciones de tarifa
     * @return array Array de condiciones procesado y normalizado
     */
    public static function processCondicionesResponse($condiciones_response): array {
        $condiciones_list = [];
        
        if (is_array($condiciones_response)) {
            // Formato 1: Respuesta con índice 'Condiciones'
            if (isset($condiciones_response['Condiciones']) && is_array($condiciones_response['Condiciones'])) {
                foreach ($condiciones_response['Condiciones'] as $condicion) {
                    if (isset($condicion['Id']) || isset($condicion['id']) || isset($condicion['ID_Articulo'])) {
                        $condiciones_list[] = [
                            'id_articulo' => $condicion['ID_Articulo'] ?? $condicion['Id'] ?? $condicion['id'] ?? '',
                            'condicion' => $condicion['Condicion'] ?? $condicion['condicion'] ?? '',
                            'descuento' => $condicion['Descuento'] ?? $condicion['descuento'] ?? 0,
                            'vigente' => $condicion['Vigente'] ?? $condicion['vigente'] ?? true
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de condiciones
            elseif (isset($condiciones_response[0]) && is_array($condiciones_response[0])) {
                foreach ($condiciones_response as $condicion) {
                    if (is_array($condicion) && (isset($condicion['Id']) || isset($condicion['id']) || isset($condicion['ID_Articulo']))) {
                        $condiciones_list[] = [
                            'id_articulo' => $condicion['ID_Articulo'] ?? $condicion['Id'] ?? $condicion['id'] ?? '',
                            'condicion' => $condicion['Condicion'] ?? $condicion['condicion'] ?? '',
                            'descuento' => $condicion['Descuento'] ?? $condicion['descuento'] ?? 0,
                            'vigente' => $condicion['Vigente'] ?? $condicion['vigente'] ?? true
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Condiciones de tarifa procesadas exitosamente', [
            'total_items' => count($condiciones_list),
            'response_type' => is_array($condiciones_response) ? 'array' : gettype($condiciones_response)
        ]);
        
        return $condiciones_list;
    }

    /**
     * Procesa respuesta de árboles de campos configurables en formato unificado
     * 
     * @param mixed $arboles_response Respuesta de la API de árboles de campos configurables
     * @return array Array de árboles procesado y normalizado
     */
    public static function processArbolesResponse($arboles_response): array {
        $arboles_list = [];
        
        if (is_array($arboles_response)) {
            // Formato 1: Respuesta con índice 'Arboles'
            if (isset($arboles_response['Arboles']) && is_array($arboles_response['Arboles'])) {
                foreach ($arboles_response['Arboles'] as $arbol) {
                    if (isset($arbol['Id']) || isset($arbol['id']) || isset($arbol['ID_Arbol'])) {
                        $arboles_list[] = [
                            'id' => $arbol['ID_Arbol'] ?? $arbol['Id'] ?? $arbol['id'] ?? '',
                            'nombre' => $arbol['Nombre'] ?? $arbol['nombre'] ?? '',
                            'descripcion' => $arbol['Descripcion'] ?? $arbol['descripcion'] ?? '',
                            'nivel' => $arbol['Nivel'] ?? $arbol['nivel'] ?? 1
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de árboles
            elseif (isset($arboles_response[0]) && is_array($arboles_response[0])) {
                foreach ($arboles_response as $arbol) {
                    if (is_array($arbol) && (isset($arbol['Id']) || isset($arbol['id']) || isset($arbol['ID_Arbol']))) {
                        $arboles_list[] = [
                            'id' => $arbol['ID_Arbol'] ?? $arbol['Id'] ?? $arbol['id'] ?? '',
                            'nombre' => $arbol['Nombre'] ?? $arbol['nombre'] ?? '',
                            'descripcion' => $arbol['Descripcion'] ?? $arbol['descripcion'] ?? '',
                            'nivel' => $arbol['Nivel'] ?? $arbol['nivel'] ?? 1
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Árboles de campos configurables procesados exitosamente', [
            'total_items' => count($arboles_list),
            'response_type' => is_array($arboles_response) ? 'array' : gettype($arboles_response)
        ]);
        
        return $arboles_list;
    }

    /**
     * Procesa respuesta de valores validados en formato unificado
     * 
     * @param mixed $valores_response Respuesta de la API de valores validados
     * @return array Array de valores procesado y normalizado
     */
    public static function processValoresValidadosResponse($valores_response): array {
        $valores_list = [];
        
        if (is_array($valores_response)) {
            // Formato 1: Respuesta con índice 'Valores'
            if (isset($valores_response['Valores']) && is_array($valores_response['Valores'])) {
                foreach ($valores_response['Valores'] as $valor) {
                    if (isset($valor['Id']) || isset($valor['id']) || isset($valor['ID_Valor'])) {
                        $valores_list[] = [
                            'id' => $valor['ID_Valor'] ?? $valor['Id'] ?? $valor['id'] ?? '',
                            'valor' => $valor['Valor'] ?? $valor['valor'] ?? '',
                            'campo_id' => $valor['ID_Campo'] ?? $valor['campo_id'] ?? '',
                            'activo' => $valor['Activo'] ?? $valor['activo'] ?? true
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de valores
            elseif (isset($valores_response[0]) && is_array($valores_response[0])) {
                foreach ($valores_response as $valor) {
                    if (is_array($valor) && (isset($valor['Id']) || isset($valor['id']) || isset($valor['ID_Valor']))) {
                        $valores_list[] = [
                            'id' => $valor['ID_Valor'] ?? $valor['Id'] ?? $valor['id'] ?? '',
                            'valor' => $valor['Valor'] ?? $valor['valor'] ?? '',
                            'campo_id' => $valor['ID_Campo'] ?? $valor['campo_id'] ?? '',
                            'activo' => $valor['Activo'] ?? $valor['activo'] ?? true
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Valores validados procesados exitosamente', [
            'total_items' => count($valores_list),
            'response_type' => is_array($valores_response) ? 'array' : gettype($valores_response)
        ]);
        
        return $valores_list;
    }

    /**
     * Procesa respuesta de categorías en formato unificado
     * 
     * @param mixed $categorias_response Respuesta de la API de categorías
     * @return array Array de categorías procesado y normalizado
     */
    public static function processCategoriasResponse($categorias_response): array {
        $categorias_list = [];
        
        if (is_array($categorias_response)) {
            // Formato 1: Respuesta con índice 'Categorias'
            if (isset($categorias_response['Categorias']) && is_array($categorias_response['Categorias'])) {
                foreach ($categorias_response['Categorias'] as $categoria) {
                    if (isset($categoria['Id']) || isset($categoria['id']) || isset($categoria['ID_Categoria'])) {
                        $categorias_list[] = [
                            'id' => $categoria['ID_Categoria'] ?? $categoria['Id'] ?? $categoria['id'] ?? '',
                            'nombre' => $categoria['Nombre'] ?? $categoria['nombre'] ?? '',
                            'descripcion' => $categoria['Descripcion'] ?? $categoria['descripcion'] ?? '',
                            'padre_id' => $categoria['ID_Padre'] ?? $categoria['padre_id'] ?? null
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de categorías
            elseif (isset($categorias_response[0]) && is_array($categorias_response[0])) {
                foreach ($categorias_response as $categoria) {
                    if (is_array($categoria) && (isset($categoria['Id']) || isset($categoria['id']) || isset($categoria['ID_Categoria']))) {
                        $categorias_list[] = [
                            'id' => $categoria['ID_Categoria'] ?? $categoria['Id'] ?? $categoria['id'] ?? '',
                            'nombre' => $categoria['Nombre'] ?? $categoria['nombre'] ?? '',
                            'descripcion' => $categoria['Descripcion'] ?? $categoria['descripcion'] ?? '',
                            'padre_id' => $categoria['ID_Padre'] ?? $categoria['padre_id'] ?? null
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Categorías procesadas exitosamente', [
            'total_items' => count($categorias_list),
            'response_type' => is_array($categorias_response) ? 'array' : gettype($categorias_response)
        ]);
        
        return $categorias_list;
    }

    /**
     * Procesa respuesta de fabricantes en formato unificado
     * 
     * @param mixed $fabricantes_response Respuesta de la API de fabricantes
     * @return array Array de fabricantes procesado y normalizado
     */
    public static function processFabricantesResponse($fabricantes_response): array {
        $fabricantes_list = [];
        
        if (is_array($fabricantes_response)) {
            // Formato 1: Respuesta con índice 'Fabricantes'
            if (isset($fabricantes_response['Fabricantes']) && is_array($fabricantes_response['Fabricantes'])) {
                foreach ($fabricantes_response['Fabricantes'] as $fabricante) {
                    if (isset($fabricante['Id']) || isset($fabricante['id']) || isset($fabricante['ID_Fabricante'])) {
                        $fabricantes_list[] = [
                            'id' => $fabricante['ID_Fabricante'] ?? $fabricante['Id'] ?? $fabricante['id'] ?? '',
                            'nombre' => $fabricante['Nombre'] ?? $fabricante['nombre'] ?? '',
                            'descripcion' => $fabricante['Descripcion'] ?? $fabricante['descripcion'] ?? '',
                            'activo' => $fabricante['Activo'] ?? $fabricante['activo'] ?? true
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de fabricantes
            elseif (isset($fabricantes_response[0]) && is_array($fabricantes_response[0])) {
                foreach ($fabricantes_response as $fabricante) {
                    if (is_array($fabricante) && (isset($fabricante['Id']) || isset($fabricante['id']) || isset($fabricante['ID_Fabricante']))) {
                        $fabricantes_list[] = [
                            'id' => $fabricante['ID_Fabricante'] ?? $fabricante['Id'] ?? $fabricante['id'] ?? '',
                            'nombre' => $fabricante['Nombre'] ?? $fabricante['nombre'] ?? '',
                            'descripcion' => $fabricante['Descripcion'] ?? $fabricante['descripcion'] ?? '',
                            'activo' => $fabricante['Activo'] ?? $fabricante['activo'] ?? true
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Fabricantes procesados exitosamente', [
            'total_items' => count($fabricantes_list),
            'response_type' => is_array($fabricantes_response) ? 'array' : gettype($fabricantes_response)
        ]);
        
        return $fabricantes_list;
    }

    /**
     * Procesa respuesta de colecciones en formato unificado
     * 
     * @param mixed $colecciones_response Respuesta de la API de colecciones
     * @return array Array de colecciones procesado y normalizado
     */
    public static function processColeccionesResponse($colecciones_response): array {
        $colecciones_list = [];
        
        if (is_array($colecciones_response)) {
            // Formato 1: Respuesta con índice 'Colecciones'
            if (isset($colecciones_response['Colecciones']) && is_array($colecciones_response['Colecciones'])) {
                foreach ($colecciones_response['Colecciones'] as $coleccion) {
                    if (isset($coleccion['Id']) || isset($coleccion['id']) || isset($coleccion['ID_Coleccion'])) {
                        $colecciones_list[] = [
                            'id' => $coleccion['ID_Coleccion'] ?? $coleccion['Id'] ?? $coleccion['id'] ?? '',
                            'nombre' => $coleccion['Nombre'] ?? $coleccion['nombre'] ?? '',
                            'descripcion' => $coleccion['Descripcion'] ?? $coleccion['descripcion'] ?? '',
                            'fabricante_id' => $coleccion['ID_Fabricante'] ?? $coleccion['fabricante_id'] ?? null
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de colecciones
            elseif (isset($colecciones_response[0]) && is_array($colecciones_response[0])) {
                foreach ($colecciones_response as $coleccion) {
                    if (is_array($coleccion) && (isset($coleccion['Id']) || isset($coleccion['id']) || isset($coleccion['ID_Coleccion']))) {
                        $colecciones_list[] = [
                            'id' => $coleccion['ID_Coleccion'] ?? $coleccion['Id'] ?? $coleccion['id'] ?? '',
                            'nombre' => $coleccion['Nombre'] ?? $coleccion['nombre'] ?? '',
                            'descripcion' => $coleccion['Descripcion'] ?? $coleccion['descripcion'] ?? '',
                            'fabricante_id' => $coleccion['ID_Fabricante'] ?? $coleccion['fabricante_id'] ?? null
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Colecciones procesadas exitosamente', [
            'total_items' => count($colecciones_list),
            'response_type' => is_array($colecciones_response) ? 'array' : gettype($colecciones_response)
        ]);
        
        return $colecciones_list;
    }

    /**
     * Procesa respuesta de cursos en formato unificado
     * 
     * @param mixed $cursos_response Respuesta de la API de cursos
     * @return array Array de cursos procesado y normalizado
     */
    public static function processCursosResponse($cursos_response): array {
        $cursos_list = [];
        
        if (is_array($cursos_response)) {
            // Formato 1: Respuesta con índice 'Cursos'
            if (isset($cursos_response['Cursos']) && is_array($cursos_response['Cursos'])) {
                foreach ($cursos_response['Cursos'] as $curso) {
                    if (isset($curso['Id']) || isset($curso['id']) || isset($curso['ID_Curso'])) {
                        $cursos_list[] = [
                            'id' => $curso['ID_Curso'] ?? $curso['Id'] ?? $curso['id'] ?? '',
                            'nombre' => $curso['Nombre'] ?? $curso['nombre'] ?? '',
                            'descripcion' => $curso['Descripcion'] ?? $curso['descripcion'] ?? '',
                            'nivel' => $curso['Nivel'] ?? $curso['nivel'] ?? 1
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de cursos
            elseif (isset($cursos_response[0]) && is_array($cursos_response[0])) {
                foreach ($cursos_response as $curso) {
                    if (is_array($curso) && (isset($curso['Id']) || isset($curso['id']) || isset($curso['ID_Curso']))) {
                        $cursos_list[] = [
                            'id' => $curso['ID_Curso'] ?? $curso['Id'] ?? $curso['id'] ?? '',
                            'nombre' => $curso['Nombre'] ?? $curso['nombre'] ?? '',
                            'descripcion' => $curso['Descripcion'] ?? $curso['descripcion'] ?? '',
                            'nivel' => $curso['Nivel'] ?? $curso['nivel'] ?? 1
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Cursos procesados exitosamente', [
            'total_items' => count($cursos_list),
            'response_type' => is_array($cursos_response) ? 'array' : gettype($cursos_response)
        ]);
        
        return $cursos_list;
    }

    /**
     * Procesa respuesta de asignaturas en formato unificado
     * 
     * @param mixed $asignaturas_response Respuesta de la API de asignaturas
     * @return array Array de asignaturas procesado y normalizado
     */
    public static function processAsignaturasResponse($asignaturas_response): array {
        $asignaturas_list = [];
        
        if (is_array($asignaturas_response)) {
            // Formato 1: Respuesta con índice 'Asignaturas'
            if (isset($asignaturas_response['Asignaturas']) && is_array($asignaturas_response['Asignaturas'])) {
                foreach ($asignaturas_response['Asignaturas'] as $asignatura) {
                    if (isset($asignatura['Id']) || isset($asignatura['id']) || isset($asignatura['ID_Asignatura'])) {
                        $asignaturas_list[] = [
                            'id' => $asignatura['ID_Asignatura'] ?? $asignatura['Id'] ?? $asignatura['id'] ?? '',
                            'nombre' => $asignatura['Nombre'] ?? $asignatura['nombre'] ?? '',
                            'descripcion' => $asignatura['Descripcion'] ?? $asignatura['descripcion'] ?? '',
                            'curso_id' => $asignatura['ID_Curso'] ?? $asignatura['curso_id'] ?? null
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de asignaturas
            elseif (isset($asignaturas_response[0]) && is_array($asignaturas_response[0])) {
                foreach ($asignaturas_response as $asignatura) {
                    if (is_array($asignatura) && (isset($asignatura['Id']) || isset($asignatura['id']) || isset($asignatura['ID_Asignatura']))) {
                        $asignaturas_list[] = [
                            'id' => $asignatura['ID_Asignatura'] ?? $asignatura['Id'] ?? $asignatura['id'] ?? '',
                            'nombre' => $asignatura['Nombre'] ?? $asignatura['nombre'] ?? '',
                            'descripcion' => $asignatura['Descripcion'] ?? $asignatura['descripcion'] ?? '',
                            'curso_id' => $asignatura['ID_Curso'] ?? $asignatura['curso_id'] ?? null
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Asignaturas procesadas exitosamente', [
            'total_items' => count($asignaturas_list),
            'response_type' => is_array($asignaturas_response) ? 'array' : gettype($asignaturas_response)
        ]);
        
        return $asignaturas_list;
    }

    /**
     * Procesa respuesta de campos configurables en formato unificado
     * 
     * @param mixed $campos_configurables_response Respuesta de la API de campos configurables
     * @return array Array de campos configurables procesado y normalizado
     */
    public static function processCamposConfigurablesResponse($campos_configurables_response): array {
        $campos_list = [];
        
        if (is_array($campos_configurables_response)) {
            // Formato 1: Respuesta con índice 'Campos'
            if (isset($campos_configurables_response['Campos']) && is_array($campos_configurables_response['Campos'])) {
                foreach ($campos_configurables_response['Campos'] as $campo) {
                    if (isset($campo['Id']) || isset($campo['id']) || isset($campo['ID_Campo']) || isset($campo['Nombre']) || isset($campo['nombre'])) {
                        $campos_list[] = [
                            'id' => $campo['ID_Campo'] ?? $campo['Id'] ?? $campo['id'] ?? '',
                            'nombre' => $campo['Nombre'] ?? $campo['nombre'] ?? '',
                        ];
                    }
                }
            } 
            // Formato 2: Array directo de campos
            elseif (isset($campos_configurables_response[0]) && is_array($campos_configurables_response[0])) {
                foreach ($campos_configurables_response as $campo) {
                    if (is_array($campo) && (isset($campo['Id']) || isset($campo['id']) || isset($campo['ID_Campo']) || isset($campo['Nombre']) || isset($campo['nombre']))) {
                        $campos_list[] = [
                            'id' => $campo['ID_Campo'] ?? $campo['Id'] ?? $campo['id'] ?? '',
                            'nombre' => $campo['Nombre'] ?? $campo['nombre'] ?? '',
                        ];
                    }
                }
            }
        }
        
        self::getLogger()->info('Campos configurables procesados exitosamente', [
            'total_items' => count($campos_list),
            'response_type' => is_array($campos_configurables_response) ? 'array' : gettype($campos_configurables_response)
        ]);
        
        return $campos_list;
    }
}
