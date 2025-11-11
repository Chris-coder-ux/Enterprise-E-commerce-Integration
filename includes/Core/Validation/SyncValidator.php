<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

use Exception;
use MiIntegracionApi\ErrorHandling\Exceptions\SyncError;
use MiIntegracionApi\Logging\Core\LogManager;

/**
 * Clase base abstracta para la validación de datos de sincronización.
 * 
 * Esta clase proporciona una estructura común para implementar validaciones de datos
 * en procesos de sincronización, siguiendo el patrón de diseño Template Method.
 * 
 * Características principales:
 * - Validación en múltiples etapas (estructura, campos requeridos, tipos, etc.)
 * - Manejo centralizado de errores y advertencias
 * - Integración con el sistema de logging
 * - Métodos auxiliares para validaciones comunes
 * 
 * @package     MiIntegracionApi\Core\Validation
 * @version     1.0.0
 * @since       1.0.0
 * 
 * @see         LogManager Para el registro de eventos y errores
 * @see         SyncError Para el manejo de errores de validación
 * 
 * @example
 * // Ejemplo de implementación de un validador concreto:
 * class MiValidador extends SyncValidator {
 *     protected function validateStructure(array $data): void {
 *         if (!isset($data['campo_requerido'])) {
 *             $this->addError('campo_requerido', 'El campo es obligatorio');
 *         }
 *     }
 *     // ... implementar otros métodos abstractos
 * }
 * 
 * // Uso:
 * $validador = new MiValidador();
 * try {
 *     $validador->validate($datos);
 *     $datosValidados = $validador->getValidatedData();
 * } catch (SyncError $e) {
 *     $errores = $validador->getErrors();
 *     $advertencias = $validador->getWarnings();
 *     // Manejar errores
 * }
 */
abstract class SyncValidator
{
    /**
     * Almacena los errores de validación encontrados.
     * 
     * Estructura:
     * ```php
     * [
     *     'nombre_campo' => [
     *         'message' => 'Mensaje de error',
     *         'context' => ['info' => 'adicional']
     *     ],
     *     // ...
     * ]
     * ```
     * 
     * @var array<string, array{
     *     message: string,
     *     context: array<string, mixed>
     * }>
     */
    protected array $errors = [];
    
    /**
     * Almacena las advertencias de validación encontradas durante la sincronización.
     * 
     * Esta propiedad contiene un array asociativo donde las claves son identificadores únicos
     * de validación y los valores son arrays con la información detallada de cada advertencia.
     * 
     * Las advertencias representan problemas no críticos que no impiden la validación
     * pero que podrían requerir atención del usuario o indicar posibles problemas.
     * 
     * @since   1.0.0
     * @see     SyncValidator::$errors Para la estructura de errores críticos
     * @see     SyncValidator::addWarning() Para añadir nuevas advertencias
     * 
     * @var array<string, array{
     *     message: string Mensaje descriptivo de la advertencia
     *     context: array<string, mixed> Datos adicionales relevantes para la advertencia
     * }>
     * 
     * @example
     * [
     *     'invalid_field' => [
     *         'message' => 'El campo contiene un formato no estándar',
     *         'context' => ['field' => 'phone', 'value' => '123-456']
     *     ]
     * ]
     */
    protected array $warnings = [];
    
    /**
     * Almacena los datos que han pasado exitosamente todas las validaciones.
     * 
     * Esta propiedad contiene un array asociativo con los datos que han superado
     * con éxito todas las reglas de validación definidas. Los datos se almacenan
     * en formato clave-valor, donde la clave es el nombre del campo y el valor
     * es el dato validado y saneado según corresponda.
     * 
     * @since   1.0.0
     * @see     SyncValidator::validate() Para el proceso de validación
     * @see     SyncValidator::getValidatedData() Para obtener estos datos
     * 
     * @var array<string, mixed> {
     *     @type string $key   Nombre del campo validado
     *     @type mixed  $value Valor validado y saneado
     * }
     * 
     * @example
     * [
     *     'nombre' => 'Juan Pérez',
     *     'email' => 'usuario@ejemplo.com',
     *     'edad' => 30,
     *     'activo' => true
     * ]
     */
    protected array $validatedData = [];

    /**
     * Valida los datos de entrada según las reglas definidas.
     * 
     * Este método implementa el algoritmo de validación siguiendo el patrón Template Method.
     * El flujo de validación es el siguiente:
     * 
     * 1. **Validación de estructura**: Verifica que los datos tengan la forma esperada.
     * 2. **Campos requeridos**: Comprueba que todos los campos obligatorios estén presentes.
     * 3. **Tipos de datos**: Valida que los tipos de datos sean los correctos.
     * 4. **Reglas específicas**: Aplica reglas de negocio personalizadas.
     * 5. **Relaciones**: Valida relaciones entre diferentes campos.
     * 6. **Límites**: Verifica restricciones de longitud, rango, etc.
     * 
     * Si se produce algún error de validación, se lanzará una excepción `SyncError`
     * que contendrá todos los errores encontrados. Las advertencias se registrarán
     * pero no interrumpirán el flujo de validación.
     * 
     * @param array<string, mixed> $data Datos a validar. Debe ser un array asociativo
     *                                 donde las claves son los nombres de los campos.
     * @return bool Siempre devuelve `true` si la validación es exitosa.
     * 
     * @throws SyncError Cuando se encuentran uno o más errores de validación.
     *         El mensaje de error y el contexto contienen información detallada
     *         sobre los errores encontrados.
     * @throws Exception Si ocurre un error inesperado durante la validación.
     * 
     * @see self::validateStructure() Para personalizar la validación de estructura
     * @see self::validateRequiredFields() Para definir campos obligatorios
     * @see self::validateDataTypes() Para validar tipos de datos
     * @see self::validateSpecificRules() Para reglas de negocio personalizadas
     * @see self::validateRelationships() Para validar relaciones entre campos
     * @see self::validateLimits() Para validar restricciones de valor
     * 
     * @example
     * $validador = new MiValidador();
     * try {
     *     $validador->validate([
     *         'nombre' => 'Ejemplo',
     *         'edad' => 30
     *     ]);
     *     // Los datos son válidos, continuar con el procesamiento...
     * } catch (SyncError $e) {
     *     // Manejar errores de validación
     *     $errores = $validador->getErrors();
     *     foreach ($errores as $campo => $error) {
     *         echo "Error en $campo: {$error['message']}\n";
     *     }
     * }
     */
    public function validate(array $data): bool
    {
        $this->errors = [];
        $this->warnings = [];
        $this->validatedData = [];

        try {
            // Validar estructura básica
            $this->validateStructure($data);

            // Validar campos requeridos
            $this->validateRequiredFields($data);

            // Validar tipos de datos
            $this->validateDataTypes($data);

            // Validar reglas específicas
            $this->validateSpecificRules($data);

            // Validar relaciones
            $this->validateRelationships($data);

            // Validar límites y restricciones
            $this->validateLimits($data);

            // Procesar advertencias
            $this->processWarnings();

            // Si hay errores, lanzar excepción
            if (!empty($this->errors)) {
                throw SyncError::validationError(
                    "Errores de validación encontrados",
                    [
                        'errors' => $this->errors,
                        'warnings' => $this->warnings
                    ]
                );
            }

            return true;
        } catch (SyncError $e) {
            // Registrar error de validación usando el sistema de logging centralizado
            $logger = LogManager::getInstance()->getLogger('sync-validation');
            $logger->error(
                "Error de validación",
                [
                    'errors' => $this->errors,
                    'warnings' => $this->warnings,
                    'data' => $data
                ]
            );
            throw $e;
        } catch (Exception $e) {
            // Registrar error inesperado usando el sistema de logging centralizado
            $logger = LogManager::getInstance()->getLogger('sync-validation');
            $logger->error(
                "Error inesperado en validación",
                [
                    'error' => $e->getMessage(),
                    'data' => $data
                ]
            );
            throw new SyncError(
                "Error inesperado en validación: " . $e->getMessage(),
                500,
                ['data' => $data]
            );
        }
    }

    /**
     * Obtiene los datos que han pasado exitosamente la validación.
     * 
     * Este método devuelve un array asociativo con los datos que han superado
     * todas las validaciones. Los campos que no pasaron la validación no se incluirán.
     * 
     * @return array<string, mixed> Datos validados. El array estará vacío en los siguientes casos:
     *                             - Si no se ha ejecutado el método validate()
     *                             - Si la validación falló
     *                             - Si no hay datos que cumplan con todas las validaciones
     * 
     * @see self::validate() Para realizar la validación de los datos
     * 
     * @example
     * $validador = new MiValidador();
     * try {
     *     $validador->validate($datos);
     *     $datosLimpios = $validador->getValidatedData();
     *     // Usar $datosLimpios sabiendo que son seguros
     * } catch (SyncError $e) {
     *     // Manejar error
     * }
     */
    public function getValidatedData(): array
    {
        return $this->validatedData;
    }

    /**
     * Obtiene los errores de validación encontrados.
     * 
     * Este método devuelve un array asociativo donde las claves son los nombres de los campos
     * que fallaron la validación, y los valores son arrays con información detallada de cada error.
     * 
     * Estructura del array devuelto:
     * ```php
     * [
     *     'nombre_campo' => [
     *         'message' => 'Mensaje de error descriptivo',
     *         'context' => [
     *             // Información adicional sobre el error
     *             'valor_recibido' => 'valor_invalido',
     *             'tipo_esperado' => 'string',
     *             // ... otros metadatos relevantes
     *         ]
     *     ],
     *     // ...
     * ]
     * ```
     * 
     * @return array<string, array{
     *     message: string,
     *     context: array<string, mixed>
     * }> Array asociativo de errores con su contexto
     * 
     * @see self::validate() Para realizar la validación
     * @see self::addError() Para agregar errores personalizados
     * 
     * @example
     * $errores = $validador->getErrors();
     * foreach ($errores as $campo => $error) {
     *     echo "Error en $campo: {$error['message']}\n";
     *     if (!empty($error['context'])) {
     *         echo "Detalles: " . json_encode($error['context']) . "\n";
     *     }
     * }
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Obtiene las advertencias de validación encontradas.
     * 
     * Las advertencias son problemas que no impiden la validación pero que podrían
     * indicar posibles problemas o áreas de mejora. A diferencia de los errores,
     * las advertencias no interrumpen el flujo de validación.
     * 
     * Estructura del array devuelto:
     * ```php
     * [
     *     'nombre_campo' => [
     *         'message' => 'Mensaje de advertencia',
     *         'context' => [
     *             // Información adicional sobre la advertencia
     *             'valor_actual' => 'valor_sospechoso',
     *             'recomendacion' => 'Se recomienda usar un valor más específico',
     *             // ... otros metadatos relevantes
     *         ]
     *     ],
     *     // ...
     * ]
     * ```
     * 
     * @return array<string, array{
     *     message: string,
     *     context: array<string, mixed>
     * }> Array asociativo de advertencias con su contexto
     * 
     * @see self::validate() Para realizar la validación
     * @see self::addWarning() Para agregar advertencias personalizadas
     * @see self::processWarnings() Para personalizar el procesamiento de advertencias
     * 
     * @example
     * $advertencias = $validador->getWarnings();
     * if (!empty($advertencias)) {
     *     echo "Advertencias de validación:\n";
     *     foreach ($advertencias as $campo => $advertencia) {
     *         echo "- $campo: {$advertencia['message']}\n";
     *     }
     * }
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Valida la estructura básica de los datos.
     * 
     * Este método es el primer paso en el proceso de validación y debe implementarse
     * por las clases hijas para verificar que los datos tengan la estructura esperada.
     * 
     * Debe validar:
     * - Que los datos sean un array no vacío
     * - Que contengan las claves principales esperadas
     * - Que los tipos de datos de nivel superior sean correctos
     * - Cualquier otra validación estructural necesaria
     * 
     * @param array<string, mixed> $data Datos a validar. Es el array bruto recibido.
     * @return void
     * @throws SyncError Si la estructura de los datos no es válida. Debe incluir un
     *                  mensaje descriptivo y contexto relevante.
     * 
     * @see self::validate() Para el flujo completo de validación
     * 
     * @example
     * protected function validateStructure(array $data): void {
     *     if (!is_array($data)) {
     *         throw new SyncError('Los datos deben ser un array');
     *     }
     *     
     *     $camposRequeridos = ['id', 'nombre', 'email'];
     *     foreach ($camposRequeridos as $campo) {
     *         if (!array_key_exists($campo, $data)) {
     *             $this->addError($campo, 'El campo es obligatorio');
     *         }
     *     }
     * }
     * 
     * */
    abstract protected function validateStructure(array $data): void;

    /**
     * Valida que todos los campos requeridos estén presentes en los datos.
     * 
     * Este método debe implementarse para verificar que todos los campos marcados como
     * obligatorios estén presentes en los datos de entrada y no estén vacíos.
     * 
     * Puntos clave a validar:
     * - Presencia de todos los campos obligatorios
     * - Que los campos requeridos no estén vacíos (null, cadena vacía, array vacío)
     * - Que los valores cumplan con los requisitos básicos de formato
     * 
     * @param array<string, mixed> $data Datos a validar. Ya se asume que tienen
     *                                 la estructura básica correcta.
     * @return void
     * @throws SyncError Si faltan campos requeridos o están vacíos. Debe incluir
     *                  información detallada sobre qué campos faltan.
     * 
     * @see self::validate()
     */
    abstract protected function validateRequiredFields(array $data): void;

    /**
     * Valida que los tipos de datos sean los esperados.
     * 
     * Este método debe ser implementado por las clases hijas para validar que los
     * tipos de datos de los valores coincidan con los esperados.
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     * @throws SyncError Si los tipos de datos no coinciden con los esperados
     * 
     * @see self::validate()
     */
    abstract protected function validateDataTypes(array $data): void;

    /**
     * Valida reglas específicas de negocio.
     * 
     * Este método debe ser implementado por las clases hijas para validar reglas
     * específicas del dominio de la aplicación que no están cubiertas por las
     * validaciones de estructura, tipos o requeridos.
     * 
     * @param array<string, mixed> $data Datos a validar. Ya se asume que han pasado
     *                                 las validaciones de estructura, requeridos y tipos.
     * @return void
     * @throws SyncError Si alguna regla de negocio no se cumple. Debe incluir
     *                  un mensaje claro sobre la regla que no se cumplió.
     * 
     * @see self::validate() Para el flujo completo de validación
     * @see self::addError() Para registrar errores de validación
     * @see self::addWarning() Para registrar advertencias de validación
     * 
     * @example
     * protected function validateSpecificRules(array $data): void {
     *     // Validar que la fecha de nacimiento sea en el pasado
     *     if (isset($data['fecha_nacimiento'])) {
     *         $fechaNacimiento = new DateTime($data['fecha_nacimiento']);
     *         $hoy = new DateTime();
     *         if ($fechaNacimiento > $hoy) {
     *             $this->addError('fecha_nacimiento', 'La fecha de nacimiento debe ser en el pasado');
     *         }
     *     }
     *     
     *     // Validar que la contraseña cumpla con los requisitos de complejidad
     *     if (isset($data['password']) && strlen($data['password']) < 8) {
     *         $this->addError('password', 'La contraseña debe tener al menos 8 caracteres');
     *     }
     * }
     * 
     * */
    abstract protected function validateSpecificRules(array $data): void;

    /**
     * Valida las relaciones entre diferentes campos de los datos.
     * 
     * Este método debe implementarse para validar las relaciones y dependencias
     * entre diferentes campos de los datos. Es útil para validar reglas que involucran
     * múltiples campos que están relacionados entre sí.
     * 
     * Casos de uso comunes:
     * - Fechas de inicio deben ser anteriores a fechas de fin
     * - Campos condicionales basados en valores de otros campos
     * - Validaciones cruzadas entre múltiples campos
     * - Dependencias entre campos (si A tiene valor X, entonces B es requerido)
     * 
     * @param array<string, mixed> $data Datos a validar. Ya se asume que han pasado
     *                                 las validaciones previas de estructura, tipos, etc.
     * @return void
     * @throws SyncError Si las relaciones entre campos no son válidas. Debe incluir
     *                  información clara sobre qué campos están en conflicto.
     * 
     * @see self::validate() Para el flujo completo de validación
     * @see self::addError() Para registrar errores de validación
     * 
     * @example
     * protected function validateRelationships(array $data): void {
     *     // Validar que fecha_inicio sea anterior a fecha_fin
     *     if (isset($data['fecha_inicio']) && isset($data['fecha_fin'])) {
     *         $inicio = new DateTime($data['fecha_inicio']);
     *         $fin = new DateTime($data['fecha_fin']);
     *         
     *         if ($inicio >= $fin) {
     *             $this->addError('fecha_inicio', 'La fecha de inicio debe ser anterior a la fecha de fin');
     *             $this->addError('fecha_fin', 'La fecha de fin debe ser posterior a la fecha de inicio');
     *         }
     *     }
     *     
     *     // Validar que si el tipo es 'especial', el campo codigo_especial sea requerido
     *     if (($data['tipo'] ?? '') === 'especial' && empty($data['codigo_especial'])) {
     *         $this->addError('codigo_especial', 'El código especial es requerido para este tipo');
     *     }
     * }
     * 
     * 
     */
    abstract protected function validateRelationships(array $data): void;

    /**
     * Valida límites y restricciones en los valores de los campos.
     * 
     * Este método debe implementarse para validar restricciones de valor en los campos,
     * como longitudes, rangos, formatos específicos y otras limitaciones.
     * 
     * Tipos de restricciones a validar:
     * - Longitud mínima/máxima de cadenas
     * - Valores mínimos/máximos para números
     * - Expresiones regulares para formatos específicos
     * - Tamaño de arrays o colecciones
     * - Valores permitidos (enums, listas de valores)
     * 
     * @param array<string, mixed> $data Datos a validar. Ya se asume que han pasado
     *                                 las validaciones de estructura, tipos, etc.
     * @return void
     * @throws SyncError Si algún valor excede los límites establecidos. Debe incluir
     *                  información sobre el límite que se superó.
     * 
     * @see self::validate() Para el flujo completo de validación
     * @see self::validateRange() Método auxiliar para validar rangos
     * @see self::addError() Para registrar errores de validación
     * @see self::addWarning() Para registrar advertencias de validación
     * 
     * @example
     * protected function validateLimits(array $data): void {
     *     // Validar longitud de nombre (entre 2 y 100 caracteres)
     *     if (isset($data['nombre'])) {
     *         $longitud = mb_strlen($data['nombre']);
     *         if ($longitud < 2 || $longitud > 100) {
     *             $this->addError('nombre', 'El nombre debe tener entre 2 y 100 caracteres');
     *         }
     *     }
     *     
     *     // Validar rango de edad (entre 18 y 120 años)
     *     if (isset($data['edad']) && !$this->validateRange($data['edad'], 18, 120, 'edad')) {
     *         $this->addError('edad', 'La edad debe estar entre 18 y 120 años');
     *     }
     *     
     *     // Validar formato de email
     *     if (isset($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
     *         $this->addError('email', 'El formato del correo electrónico no es válido');
     *     }
     * }
     */
    abstract protected function validateLimits(array $data): void;

    /**
     * Procesa y registra las advertencias de validación en el sistema de logging.
     * 
     * Este método se encarga de registrar las advertencias de validación a través
     * del sistema de logging centralizado cuando se detectan problemas que no
     * impiden la validación pero que podrían requerir atención del administrador.
     * 
     * Características principales:
     * - Solo realiza el registro si existen advertencias pendientes
     * - Utiliza el canal de logging 'sync-validation' para agrupar estos mensajes
     * - Incluye todas las advertencias en un array estructurado para su análisis
     * - Utiliza el nivel de severidad WARNING para estos registros
     * 
     * @return void
     * 
     * @throws \RuntimeException Si ocurre un error al acceder al sistema de logging
     * @since 1.0.0
     * 
     * @example
     * // Ejemplo de uso típico
     * $validador->validate($datos);
     * $validador->processWarnings(); // Registrará las advertencias si existen
     * 
     * @see LogManager Para más información sobre el sistema de logging
     * @see self::validate() Para el proceso de validación completo
     * @see self::$warnings Para la estructura de datos de las advertencias
     * 
     * @uses \Psr\Log\LoggerInterface Interfaz del logger utilizado internamente
     */
    protected function processWarnings(): void
    {
        if (!empty($this->warnings)) {
            // Registrar advertencias usando el sistema de logging centralizado
            $logger = LogManager::getInstance()->getLogger('sync-validation');
            $logger->warning(
                "Advertencias de validación",
                [
                    'warnings' => $this->warnings
                ]
            );
        }
    }

    /**
     * Registra un error de validación para un campo específico.
     * 
     * Este método permite registrar errores de validación que ocurren durante el proceso
     * de validación. Cada error se asocia a un campo específico y puede incluir
     * información adicional de contexto para facilitar el diagnóstico y la presentación
     * al usuario final.
     * 
     * Características principales:
     * - Sobrescribe cualquier error previo para el mismo campo
     * - Almacena el mensaje de error y el contexto asociado
     * - Los errores se pueden recuperar posteriormente con getErrors()
     * 
     * @param string $field Nombre del campo que falló la validación.
     *                      Debe ser un identificador único que identifique claramente el campo.
     * @param string $message Mensaje descriptivo del error que se mostrará al usuario.
     *                       Debe ser claro y orientado al usuario final.
     * @param array<string, mixed> $context Datos adicionales sobre el error que pueden ser
     *                                    útiles para el diagnóstico o para personalizar
     *                                    mensajes de error. Los datos típicos incluyen:
     *                                    - 'value': El valor que causó el error
     *                                    - 'constraint': La restricción que falló
     *                                    - 'expected': El valor o tipo esperado
     * @return void
     * 
     * @since 1.0.0
     * 
     * @example
     * // Ejemplo de uso básico
     * $this->addError('email', 'El correo electrónico es obligatorio');
     * 
     * // Ejemplo con contexto adicional
     * $this->addError('edad', 'La edad debe ser mayor o igual a 18', [
     *     'value' => $edad,
     *     'min' => 18,
     *     'provided' => $edad
     * ]);
     * 
     * @see self::getErrors() Para recuperar los errores registrados
     * @see self::hasErrors() Para verificar si hay errores
     * @see self::clearErrors() Para limpiar los errores registrados
     */
    protected function addError(string $field, string $message, array $context = []): void
    {
        $this->errors[$field] = [
            'message' => $message,
            'context' => $context
        ];
    }

    /**
     * Registra una advertencia de validación para un campo específico sin fallar la validación.
     * 
     * Este método permite registrar advertencias sobre problemas potenciales en los datos
     * que no son lo suficientemente graves como para fallar la validación, pero que podrían
     * requerir atención o revisión.
     * 
     * Características principales:
     * - No impide que la validación sea exitosa
     * - Útil para notificar sobre datos inusuales o dudosos
     * - Las advertencias se pueden registrar y procesar por separado de los errores
     * - Se pueden incluir datos de contexto para facilitar el diagnóstico
     * 
     * @param string $field Nombre del campo relacionado con la advertencia.
     *                     Debe ser un identificador único que identifique claramente el campo.
     * @param string $message Mensaje descriptivo de la advertencia.
     *                      Debe ser claro y orientado al usuario final.
     * @param array<string, mixed> $context Datos adicionales sobre la advertencia que pueden ser
     *                                    útiles para el diagnóstico o para personalizar
     *                                    mensajes. Los datos típicos incluyen:
     *                                    - 'value': El valor que generó la advertencia
     *                                    - 'reason': La razón específica de la advertencia
     *                                    - 'suggestion': Sugerencia para el usuario
     * @return void
     * 
     * @since 1.0.0
     * 
     * @example
     * // Ejemplo de advertencia básica
     * $this->addWarning('telefono', 'El formato del teléfono es inusual');
     * 
     * // Ejemplo con contexto adicional
     * $this->addWarning('fecha_nacimiento', 'La fecha de nacimiento parece incorrecta', [
     *     'value' => $fecha,
     *     'reason' => 'La fecha está en el futuro',
     *     'suggestion' => 'Verifique la fecha de nacimiento ingresada'
     * ]);
     * 
     * @see self::processWarnings() Para procesar las advertencias registradas
     * @see self::getWarnings() Para recuperar las advertencias registradas
     * @see self::addError() Para registrar errores que impiden la validación
     */
    protected function addWarning(string $field, string $message, array $context = []): void
    {
        $this->warnings[$field] = [
            'message' => $message,
            'context' => $context
        ];
    }

    /**
     * Valida que un valor coincida con un tipo de dato específico.
     * 
     * Este método realiza una validación de tipos flexible, permitiendo ciertas conversiones
     * implícitas para facilitar la validación de datos de entrada que pueden venir en
     * diferentes formatos (por ejemplo, desde formularios web o APIs).
     * 
     * Tipos soportados y su comportamiento:
     * - 'string': Cualquier valor que sea string o pueda convertirse a string
     * - 'int': Números enteros o strings numéricos enteros (ej: '123')
     * - 'float': Números decimales o strings numéricos (ej: '123.45')
     * - 'bool': Valores booleanos o sus representaciones comunes ('0', '1', 'true', 'false')
     * - 'array': Arrays de PHP
     * - 'object': Objetos de PHP
     * 
     * @param mixed $value Valor que se va a validar. Puede ser de cualquier tipo.
     * @param string $type Tipo esperado. Debe ser uno de: 'string', 'int', 'float', 'bool', 'array', 'object'.
     * @param string $field Nombre del campo que se está validando. Se usa para generar mensajes de error.
     * @return bool Devuelve `true` si el valor es del tipo especificado o puede convertirse a él,
     *              `false` en caso contrario. Los errores se registran automáticamente.
     * 
     * @since 1.0.0
     * 
     * @example
     * // Ejemplos de validación exitosa
     * $this->validateType('123', 'int', 'edad');        // true
     * $this->validateType('123.45', 'float', 'precio'); // true
     * $this->validateType('true', 'bool', 'activo');    // true
     * 
     * // Ejemplo de validación fallida (registra un error)
     * $this->validateType('no es un número', 'int', 'edad'); // false
     * 
     * @see self::addError() Para entender cómo se registran los errores de validación
     * @see gettype() Función de PHP utilizada internamente para la validación de tipos
     */
    protected function validateType(mixed $value, string $type, string $field): bool
    {
        $valid = match($type) {
            'string' => is_string($value),
            'int' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'float' => is_float($value) || (is_string($value) && is_numeric($value)),
            'bool' => is_bool($value) || in_array($value, ['0', '1', 'true', 'false'], true),
            'array' => is_array($value),
            'object' => is_object($value),
            default => false
        };

        if (!$valid) {
            $this->addError(
                $field,
                "El campo debe ser de tipo $type",
                ['value' => $value, 'type' => gettype($value)]
            );
        }

        return $valid;
    }

    /**
     * Valida que un valor esté dentro de un rango numérico o de cadena especificado.
     * 
     * Este método realiza una validación de rango inclusivo, donde el valor debe ser mayor o igual
     * que el mínimo y menor o igual que el máximo. Es compatible con diferentes tipos de datos
     * siempre que sean comparables mediante los operadores de comparación estándar de PHP.
     * 
     * Características principales:
     * - Validación inclusiva (el valor puede ser igual a los límites)
     * - Soporte para diferentes tipos de datos (números, strings, fechas, etc.)
     * - Registro automático de errores con contexto detallado
     * - Mensajes de error claros y personalizables
     * 
     * @param mixed $value Valor que se va a validar. Debe ser comparable con $min y $max.
     * @param mixed $min Límite inferior del rango (inclusive).
     * @param mixed $max Límite superior del rango (inclusive).
     * @param string $field Nombre del campo que se está validando. Se usa para generar mensajes de error.
     * @return bool Devuelve `true` si el valor está dentro del rango especificado,
     *              `false` en caso contrario. Los errores se registran automáticamente.
     * 
     * @throws \TypeError Si los valores no son comparables entre sí
     * @since 1.0.0
     * 
     * @example
     * // Validación numérica
     * $this->validateRange(5, 1, 10, 'puntuacion');     // true
     * $this->validateRange(15, 1, 10, 'puntuacion');    // false
     * 
     * // Validación de cadenas (orden alfabético)
     * $this->validateRange('mango', 'manzana', 'pera', 'fruta');  // true
     * 
     * // Validación de fechas
     * $hoy = new DateTime();
     * $ayer = (clone $hoy)->modify('-1 day');
     * $manana = (clone $hoy)->modify('+1 day');
     * $this->validateRange($hoy, $ayer, $manana, 'fecha');  // true
     * 
     * @see self::addError() Para entender cómo se registran los errores de validación
     * @see https://www.php.net/manual/en/language.operators.comparison.php Operadores de comparación de PHP
     */
    protected function validateRange(mixed $value, mixed $min, mixed $max, string $field): bool
    {
        if ($value < $min || $value > $max) {
            $this->addError(
                $field,
                "El valor debe estar entre $min y $max",
                ['value' => $value, 'min' => $min, 'max' => $max]
            );
            return false;
        }

        return true;
    }

    /**
     * Valida que un valor de cadena cumpla con un patrón de expresión regular.
     * 
     * Este método verifica que un valor de tipo string cumpla con el patrón de expresión regular
     * proporcionado. Si el valor no es una cadena o no coincide con el patrón, se registrará
     * un error de validación.
     * 
     * Características principales:
     * - Validación mediante expresiones regulares PCRE
     * - Soporte para patrones complejos de validación
     * - Mensajes de error claros con contexto detallado
     * - Registro automático de errores
     * 
     * @param mixed $value Valor que se va a validar. Se convierte a string si es posible.
     * @param string $pattern Patrón de expresión regular PCRE contra el que se validará el valor.
     *                       Debe incluir los delimitadores de la expresión regular (ej: '/^\d+$/')
     * @param string $field Nombre del campo que se está validando. Se usa para generar mensajes de error.
     * @return bool Devuelve `true` si el valor es una cadena y coincide con el patrón,
     *              `false` en caso contrario. Los errores se registran automáticamente.
     * 
     * @throws \InvalidArgumentException Si el patrón no es una cadena válida
     * @throws \RuntimeException Si ocurre un error al ejecutar la expresión regular
     * @since 1.0.0
     * 
     * @example
     * // Validación de código postal español (5 dígitos)
     * $this->validatePattern('28001', '/^\d{5}$/', 'codigo_postal');  // true
     * 
     * // Validación de email
     * $emailPattern = '/^[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}$/';
     * $this->validatePattern('usuario@ejemplo.com', $emailPattern, 'email');  // true
     * 
     * // Validación fallida (registra un error)
     * $this->validatePattern('no-es-un-email', $emailPattern, 'email');  // false
     * 
     * @see preg_match() Función de PHP utilizada internamente
     * @see https://www.php.net/manual/es/pcre.pattern.php Documentación de patrones PCRE
     * @see self::addError() Para entender cómo se registran los errores de validación
     */
    protected function validatePattern(mixed $value, string $pattern, string $field): bool
    {
        if (!is_string($value) || !preg_match($pattern, $value)) {
            $this->addError(
                $field,
                "El valor no cumple con el patrón requerido",
                ['value' => $value, 'pattern' => $pattern]
            );
            return false;
        }

        return true;
    }

    /**
     * Valida que una lista de campos esté presente y no esté vacía en los datos de entrada.
     * 
     * Este método helper centraliza la validación de campos obligatorios, permitiendo
     * validar múltiples campos a la vez con soporte para estructuras de datos anidadas
     * mediante el uso de prefijos.
     * 
     * Características principales:
     * - Validación de múltiples campos en una sola llamada
     * - Soporte para campos anidados mediante prefijos
     * - Manejo consistente de valores nulos o vacíos
     * - Integración con el sistema de registro de errores
     
     * Un campo se considera inválido si:
     * - No existe en los datos de entrada
     * - Es explícitamente `null`
     * - Es una cadena vacía `''`
     
     * @param array<string, mixed> $data Datos a validar. Puede ser un array plano o multidimensional.
     * @param array<int, string> $requiredFields Lista de nombres de campos obligatorios.
     *                     Los nombres pueden incluir notación de puntos para acceder a campos anidados
     *                     si no se usa el parámetro $prefix.
     * @param string $prefix Prefijo opcional para acceder a campos dentro de una estructura anidada.
     *                      Ejemplo: Con $prefix = 'usuario' y $field = 'nombre', buscará en $data['usuario']['nombre']
     * @return void
     * 
     * @throws \InvalidArgumentException Si $data no es un array
     * @since 1.0.0
     * 
     * @example
     * // Validación de campos en el nivel raíz
     * $data = ['nombre' => 'Juan', 'email' => ''];
     * $this->validateRequiredFieldsList($data, ['nombre', 'email']);
     * // Registrará un error para 'email' porque está vacío
     * 
     * // Validación de campos anidados con prefijo
     * $data = [
     *     'usuario' => [
     *         'nombre' => 'Ana',
     *         'email' => null
     *     ]
     * ];
     * $this->validateRequiredFieldsList($data, ['nombre', 'email'], 'usuario');
     * // Registrará un error para 'usuario.email' porque es null
     * 
     * @see self::addError() Para entender cómo se registran los errores
     * @see self::validateRequiredFields() Para la validación de campos requeridos principal
     */
    protected function validateRequiredFieldsList(array $data, array $requiredFields, string $prefix = ''): void
    {
        foreach ($requiredFields as $field) {
            $fieldPath = $prefix ? "$prefix.$field" : $field;
            $fieldValue = $prefix ? ($data[$prefix][$field] ?? null) : ($data[$field] ?? null);
            
            if (!isset($fieldValue) || $fieldValue === '') {
                $this->addError($fieldPath, "El campo es requerido");
            }
        }
    }

    /**
     * Valida campos obligatorios dentro de una sección anidada de los datos.
     * 
     * Este método especializado valida la presencia de campos requeridos dentro de una
     * sección específica de los datos (como 'billing' o 'shipping'). La validación
     * solo se realiza si la sección existe y es un array, lo que hace que este método
     * sea seguro para estructuras de datos opcionales.
     * 
     * Características principales:
     * - Validación condicional basada en la existencia de la sección
     * - Integración con `validateRequiredFieldsList` para la lógica de validación
     * - Manejo seguro de secciones opcionales
     * - Prevención de errores con estructuras de datos incompletas
     * 
     * Comportamiento:
     * - Si la sección no existe o no es un array, no se realiza ninguna validación
     * - Si la sección existe, valida que los campos requeridos estén presentes y no vacíos
     * - Los errores se registran con la ruta completa (ej: 'billing.nombre')
     * 
     * @param array<string, mixed> $data Datos completos a validar. Debe ser un array que pueda
     *                                 contener la sección especificada.
     * @param string $section Nombre de la sección anidada que contiene los campos a validar.
     *                      Ejemplos: 'billing', 'shipping', 'usuario', etc.
     * @param array<int, string> $requiredFields Lista de nombres de campos obligatorios dentro de la sección.
     *                                         No es necesario incluir el nombre de la sección en los nombres de campo.
     * @return void
     * 
     * @throws \InvalidArgumentException Si $data no es un array
     * @since 1.0.0
     * 
     * @example
     * // Ejemplo con sección existente
     * $data = [
     *     'billing' => [
     *         'first_name' => 'Juan',
     *         'last_name' => ''  // Campo requerido faltante
     *     ]
     * ];
     * $this->validateNestedRequiredFields($data, 'billing', ['first_name', 'last_name']);
     * // Registrará un error para 'billing.last_name'
     * 
     * // Ejemplo con sección opcional no presente
     * $data = ['user' => ['name' => 'Ana']];
     * $this->validateNestedRequiredFields($data, 'billing', ['address', 'city']);
     * // No se realizará ninguna validación (no hay error)
     * 
     * @see self::validateRequiredFieldsList() Para la implementación base de validación
     * @see self::validateRequiredFields() Para la validación de campos requeridos en el nivel raíz
     */
    protected function validateNestedRequiredFields(array $data, string $section, array $requiredFields): void
    {
        if (isset($data[$section]) && is_array($data[$section])) {
            $this->validateRequiredFieldsList($data[$section], $requiredFields, $section);
        }
    }

    /**
     * Valida el formato de una dirección de email dentro de una sección anidada de los datos.
     * 
     * Este método especializado verifica que una dirección de email tenga un formato válido
     * según los estándares de Internet (RFC 5322) cuando se encuentra dentro de una sección
     * específica de los datos (como 'billing' o 'shipping').
     * 
     * Características principales:
     * - Validación de formato de email estándar
     * - Validación condicional basada en la existencia del campo
     * - Soporte para campos de email personalizados
     * - Integración con el sistema de registro de errores
     
     * Comportamiento:
     * - Si la sección o el campo no existen, no se realiza ninguna validación
     * - Si el campo existe pero está vacío, no se considera un error
     * - Si el campo contiene un valor, debe ser un email válido
     * - Los errores se registran con la ruta completa (ej: 'billing.email')
     * 
     * @param array<string, mixed> $data Datos completos a validar. Debe ser un array que pueda
     *                                 contener la sección y campo especificados.
     * @param string $section Nombre de la sección anidada que contiene el campo de email.
     *                      Ejemplos: 'billing', 'shipping', 'usuario', etc.
     * @param string $field Nombre del campo que contiene la dirección de email.
     *                    Por defecto es 'email'.
     * @return void
     * 
     * @throws \InvalidArgumentException Si $data no es un array
     * @since 1.0.0
     * 
     * @example
     * // Validación de email estándar
     * $data = [
     *     'billing' => [
     *         'email' => 'usuario@ejemplo.com'  // Válido
     *     ]
     * ];
     * $this->validateNestedEmail($data, 'billing');
     * 
     * // Validación con campo personalizado
     * $data = [
     *     'contact' => [
     *         'contact_email' => 'no-es-un-email'  // Inválido
     *     ]
     * ];
     * $this->validateNestedEmail($data, 'contact', 'contact_email');
     * // Registrará un error para 'contact.contact_email'
     * 
     * @see filter_var() con FILTER_VALIDATE_EMAIL para la validación de email
     * @see self::addError() Para entender cómo se registran los errores
     * @see https://www.ietf.org/rfc/rfc5322.txt RFC 5322 - Formato de email
     */
    protected function validateNestedEmail(array $data, string $section, string $field = 'email'): void
    {
        if (isset($data[$section][$field])) {
            if (!filter_var($data[$section][$field], FILTER_VALIDATE_EMAIL)) {
                $this->addError(
                    "$section.$field",
                    "Email de $section inválido",
                    ['value' => $data[$section][$field]]
                );
            }
        }
    }

    /**
     * Valida el formato de un número de teléfono dentro de una sección anidada de los datos.
     * 
     * Este método especializado verifica que un número de teléfono cumpla con un formato básico
     * cuando se encuentra dentro de una sección específica de los datos (como 'billing' o 'shipping').
     * La validación es flexible para admitir diferentes formatos internacionales.
     * 
     * Características principales:
     * - Validación de formato de teléfono internacional
     * - Soporte para diferentes formatos de números (con/sin espacios, guiones, paréntesis)
     * - Validación condicional basada en la existencia del campo
     * - Integración con el sistema de registro de errores
     * 
     * Formato aceptado (expresión regular):
     * - `^[0-9+\-\s()]{6,20}$`
     * - Mínimo 6, máximo 20 caracteres
     * - Caracteres permitidos: dígitos (0-9), signo más (+), guiones (-), espacios y paréntesis
     * 
     * Comportamiento:
     * - Si la sección o el campo no existen, no se realiza ninguna validación
     * - Si el campo existe pero está vacío, no se considera un error
     * - Si el campo contiene un valor, debe coincidir con el patrón de teléfono
     * - Los errores se registran con la ruta completa (ej: 'billing.phone')
     * 
     * @param array<string, mixed> $data Datos completos a validar. Debe ser un array que pueda
     *                                 contener la sección y campo especificados.
     * @param string $section Nombre de la sección anidada que contiene el campo de teléfono.
     *                      Ejemplos: 'billing', 'shipping', 'contacto', etc.
     * @param string $field Nombre del campo que contiene el número de teléfono.
     *                    Por defecto es 'phone'.
     * @return void
     * 
     * @throws \InvalidArgumentException Si $data no es un array
     * @since 1.0.0
     * 
     * @example
     * // Validación de teléfono estándar
     * $data = [
     *     'billing' => [
     *         'phone' => '+34 912 34 56 78'  // Válido
     *     ]
     * ];
     * $this->validateNestedPhone($data, 'billing');
     * 
     * // Validación con campo personalizado
     * $data = [
     *     'contact' => [
     *         'telefono' => 'abc123'  // Inválido (contiene letras)
     *     ]
     * ];
     * $this->validateNestedPhone($data, 'contact', 'telefono');
     * // Registrará un error para 'contact.telefono'
     * 
     * @see preg_match() Para la validación mediante expresiones regulares
     * @see self::addError() Para entender cómo se registran los errores
     * @see self::validateNestedEmail() Para validación de emails en secciones anidadas
     */
    protected function validateNestedPhone(array $data, string $section, string $field = 'phone'): void
    {
        if (isset($data[$section][$field])) {
            if (!preg_match('/^[0-9+\-\s()]{6,20}$/', $data[$section][$field])) {
                $this->addError(
                    "$section.$field",
                    "Teléfono de $section inválido",
                    ['value' => $data[$section][$field]]
                );
            }
        }
    }

    /**
     * Valida los límites de longitud para los campos de dirección de facturación y envío.
     * 
     * Este método centraliza la validación de longitud para todos los campos de dirección,
     * asegurando que cumplan con los requisitos mínimos y máximos de longitud definidos.
     * 
     * Características principales:
     * - Validación de longitud para campos de facturación y envío
     * - Límites personalizados por tipo de campo
     * - Validación condicional basada en la existencia de los campos
     * - Integración con el sistema de registro de errores
     * 
     * Campos validados y sus límites:
     * - first_name: 2-50 caracteres
     * - last_name: 2-50 caracteres
     * - address_1: 5-100 caracteres
     * - city: 2-50 caracteres
     * - state: 2-50 caracteres
     * - postcode: 3-20 caracteres
     * - country: 2 caracteres (código ISO)
     * 
     * Comportamiento:
     * - Solo valida los campos que existen en los datos de entrada
     * - Los campos opcionales que no estén presentes se ignoran
     * - Los errores se registran con la ruta completa (ej: 'billing.first_name')
     * - Utiliza el método validateRange internamente para la validación
     * 
     * @param array<string, mixed> $data Datos completos del pedido que contienen las secciones
     *                                 'billing' y 'shipping' con sus respectivos campos.
     *                                 Ejemplo:
     *                                 [
     *                                     'billing' => [
     *                                         'first_name' => 'Juan',
     *                                         'last_name' => 'Pérez',
     *                                         // ...otros campos
     *                                     ],
     *                                     'shipping' => [
     *                                         // ...campos de envío
     *                                     ]
     *                                 ]
     * @return void
     * 
     * @throws \InvalidArgumentException Si $data no es un array
     * @since 1.0.0
     * 
     * @example
     * // Validación de direcciones
     * $data = [
     *     'billing' => [
     *         'first_name' => 'J',  // Demasiado corto (mínimo 2 caracteres)
     *         'last_name' => 'Pérez',
     *         'address_1' => 'Calle Falsa 123',
     *         'city' => 'Madrid',
     *         'postcode' => '28001',
     *         'country' => 'ES'
     *     ]
     * ];
     * $this->validateAddressFieldsLimits($data);
     * // Registrará un error para 'billing.first_name' por ser demasiado corto
     * 
     * @see self::validateRange() Para la implementación de la validación de rangos
     * @see self::addError() Para entender cómo se registran los errores
     */
    protected function validateAddressFieldsLimits(array $data): void
    {
        $addressFields = [
            'billing.first_name' => ['min' => 2, 'max' => 50],
            'billing.last_name' => ['min' => 2, 'max' => 50],
            'billing.address_1' => ['min' => 5, 'max' => 100],
            'billing.city' => ['min' => 2, 'max' => 50],
            'billing.state' => ['min' => 2, 'max' => 50],
            'billing.postcode' => ['min' => 3, 'max' => 20],
            'billing.country' => ['min' => 2, 'max' => 2],
            'shipping.first_name' => ['min' => 2, 'max' => 50],
            'shipping.last_name' => ['min' => 2, 'max' => 50],
            'shipping.address_1' => ['min' => 5, 'max' => 100],
            'shipping.city' => ['min' => 2, 'max' => 50],
            'shipping.state' => ['min' => 2, 'max' => 50],
            'shipping.postcode' => ['min' => 3, 'max' => 20],
            'shipping.country' => ['min' => 2, 'max' => 2]
        ];

        foreach ($addressFields as $field => $limits) {
            $parts = explode('.', $field);
            if (isset($data[$parts[0]][$parts[1]])) {
                $this->validateRange(
                    strlen($data[$parts[0]][$parts[1]]),
                    $limits['min'],
                    $limits['max'],
                    $field
                );
            }
        }
    }
} 