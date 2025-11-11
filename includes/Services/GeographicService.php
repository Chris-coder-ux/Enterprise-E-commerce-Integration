<?php
/**
 * Servicio para la gestión de datos geográficos de Verial
 *
 * Este servicio proporciona métodos para interactuar con la información geográfica
 * de Verial, incluyendo países, provincias y localidades. Maneja el cacheo de
 * datos para optimizar las consultas a la API.
 *
 * @package    MiIntegracionApi
 * @subpackage Services
 * @since      1.0.0
 * @version    1.1.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Services;

use MiIntegracionApi\Services\VerialApiClient;
use MiIntegracionApi\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase GeographicService
 *
 * Gestiona la información geográfica del sistema, incluyendo la obtención,
 * búsqueda y creación de países, provincias y localidades.
 *
 * @package MiIntegracionApi\Services
 * @since   1.0.0
 */
class GeographicService {
    /**
     * Cliente de la API de Verial
     *
     * @var VerialApiClient
     * @since 1.0.0
     */
    private VerialApiClient $api_client;

    /**
     * Instancia del logger para registro de eventos
     *
     * @var Logger
     * @since 1.0.0
     */
    private Logger $logger;

    /**
     * Cache interno para almacenar datos geográficos
     *
     * @var array<string, array>
     * @since 1.0.0
     */
    private array $cache = [];

    /**
     * Constructor del servicio geográfico
     *
     * @param VerialApiClient $api_client Cliente de la API de Verial
     * @param Logger|null $logger Instancia del logger (opcional)
     * @since 1.0.0
     */
    public function __construct(VerialApiClient $api_client, ?Logger $logger = null) {
        $this->api_client = $api_client;
        $this->logger = $logger ?: new Logger('geographic_service');
    }

    /**
     * Obtiene la lista de países desde la API de Verial
     *
     * Los resultados se almacenan en caché para optimizar consultas posteriores.
     *
     * @return array<
     *     array{
     *         Id: int,
     *         Nombre: string,
     *         ISO2: string,
     *         ISO3: string
     *     }
     * > Lista de países con sus datos básicos
     * @since 1.0.0
     */
    public function getPaises(): array {
        if (isset($this->cache['paises'])) {
            return $this->cache['paises'];
        }

        $response = $this->api_client->get('GetPaisesWS');
        
        if ($this->api_client->isSuccess($response)) {
            $this->cache['paises'] = $response['Paises'] ?? [];
            $this->logger->info('Países obtenidos de Verial', ['count' => count($this->cache['paises'])]);
            return $this->cache['paises'];
        }

        $this->logger->error('Error obteniendo países', ['error' => $this->api_client->getErrorMessage($response)]);
        return [];
    }

    /**
     * Obtiene la lista de provincias desde la API de Verial
     *
     * Los resultados se almacenan en caché para optimizar consultas posteriores.
     *
     * @return array<
     *     array{
     *         Id: int,
     *         Nombre: string,
     *         ID_Pais: int,
     *         CodigoNUTS: string
     *     }
     * > Lista de provincias con sus datos básicos
     * @since 1.0.0
     */
    public function getProvincias(): array {
        if (isset($this->cache['provincias'])) {
            return $this->cache['provincias'];
        }

        $response = $this->api_client->get('GetProvinciasWS');
        
        if ($this->api_client->isSuccess($response)) {
            $this->cache['provincias'] = $response['Provincias'] ?? [];
            $this->logger->info('Provincias obtenidas de Verial', ['count' => count($this->cache['provincias'])]);
            return $this->cache['provincias'];
        }

        $this->logger->error('Error obteniendo provincias', ['error' => $this->api_client->getErrorMessage($response)]);
        return [];
    }

    /**
     * Obtiene la lista de localidades desde la API de Verial
     *
     * Los resultados se almacenan en caché para optimizar consultas posteriores.
     *
     * @return array<
     *     array{
     *         Id: int,
     *         Nombre: string,
     *         ID_Provincia: int,
     *         ID_Pais: int,
     *         CodigoNUTS: string,
     *         CodigoMunicipioINE: string
     *     }
     * > Lista de localidades con sus datos básicos
     * @since 1.0.0
     */
    public function getLocalidades(): array {
        if (isset($this->cache['localidades'])) {
            return $this->cache['localidades'];
        }

        $response = $this->api_client->get('GetLocalidadesWS');
        
        if ($this->api_client->isSuccess($response)) {
            $this->cache['localidades'] = $response['Localidades'] ?? [];
            $this->logger->info('Localidades obtenidas de Verial', ['count' => count($this->cache['localidades'])]);
            return $this->cache['localidades'];
        }

        $this->logger->error('Error obteniendo localidades', ['error' => $this->api_client->getErrorMessage($response)]);
        return [];
    }

    /**
     * Busca un país por su código ISO 3166-1 alpha-2
     *
     * La búsqueda no distingue entre mayúsculas y minúsculas.
     *
     * @param string $iso2 Código de país de dos letras (ej: 'ES', 'FR', 'DE')
     * @return array{
     *     Id: int,
     *     Nombre: string,
     *     ISO2: string,
     *     ISO3: string
     * }|null Datos del país o null si no se encuentra
     * @since 1.0.0
     */
    public function findPaisByISO2(string $iso2): ?array {
        $paises = $this->getPaises();
        
        foreach ($paises as $pais) {
            if (strtoupper($pais['ISO2'] ?? '') === strtoupper($iso2)) {
                return $pais;
            }
        }

        return null;
    }

    /**
     * Busca una provincia por su nombre
     *
     * La búsqueda es insensible a mayúsculas/minúsculas y puede filtrarse por país.
     *
     * @param string $nombre Nombre o parte del nombre de la provincia a buscar
     * @param int|null $id_pais ID del país para filtrar (opcional)
     * @return array{
     *     Id: int,
     *     Nombre: string,
     *     ID_Pais: int,
     *     CodigoNUTS: string
     * }|null Datos de la provincia o null si no se encuentra
     * @since 1.0.0
     */
    public function findProvinciaByName(string $nombre, int $id_pais = null): ?array {
        $provincias = $this->getProvincias();
        
        foreach ($provincias as $provincia) {
            $match_nombre = stripos($provincia['Nombre'] ?? '', $nombre) !== false;
            $match_pais = $id_pais === null || ($provincia['ID_Pais'] ?? 0) === $id_pais;
            
            if ($match_nombre && $match_pais) {
                return $provincia;
            }
        }

        return null;
    }

    /**
     * Busca una localidad por su nombre
     *
     * La búsqueda es insensible a mayúsculas/minúsculas y puede filtrarse por provincia.
     *
     * @param string $nombre Nombre o parte del nombre de la localidad a buscar
     * @param int|null $id_provincia ID de la provincia para filtrar (opcional)
     * @return array{
     *     Id: int,
     *     Nombre: string,
     *     ID_Provincia: int,
     *     ID_Pais: int,
     *     CodigoNUTS: string,
     *     CodigoMunicipioINE: string
     * }|null Datos de la localidad o null si no se encuentra
     * @since 1.0.0
     */
    public function findLocalidadByName(string $nombre, int $id_provincia = null): ?array {
        $localidades = $this->getLocalidades();
        
        foreach ($localidades as $localidad) {
            $match_nombre = stripos($localidad['Nombre'] ?? '', $nombre) !== false;
            $match_provincia = $id_provincia === null || ($localidad['ID_Provincia'] ?? 0) === $id_provincia;
            
            if ($match_nombre && $match_provincia) {
                return $localidad;
            }
        }

        return null;
    }

    /**
     * Mapea una dirección a los IDs correspondientes en Verial
     *
     * Este método toma un código de país, provincia y localidad, y devuelve
     * los IDs correspondientes según la base de datos de Verial.
     *
     * @param string $country_code Código de país ISO 3166-1 alpha-2 (ej: 'ES')
     * @param string $state Nombre de la provincia/estado (opcional)
     * @param string $city Nombre de la localidad/ciudad (opcional)
     * @return array{
     *     ID_Pais: int,
     *     ID_Provincia: int,
     *     ID_Localidad: int,
     *     Pais: string,
     *     Provincia: string,
     *     Localidad: string
     * } Datos de la dirección mapeados a IDs de Verial
     * @since 1.0.0
     */
    public function mapAddress(string $country_code, string $state = '', string $city = ''): array {
        $result = [
            'ID_Pais' => 0,
            'ID_Provincia' => 0,
            'ID_Localidad' => 0,
            'Pais' => '',
            'Provincia' => $state,
            'Localidad' => $city
        ];

        // Buscar país
        $pais = $this->findPaisByISO2($country_code);
        if ($pais) {
            $result['ID_Pais'] = $pais['Id'];
            $result['Pais'] = $pais['Nombre'];

            // Buscar provincia
            if (!empty($state)) {
                $provincia = $this->findProvinciaByName($state, $pais['Id']);
                if ($provincia) {
                    $result['ID_Provincia'] = $provincia['Id'];
                    $result['Provincia'] = $provincia['Nombre'];

                    // Buscar localidad
                    if (!empty($city)) {
                        $localidad = $this->findLocalidadByName($city, $provincia['Id']);
                        if ($localidad) {
                            $result['ID_Localidad'] = $localidad['Id'];
                            $result['Localidad'] = $localidad['Nombre'];
                        }
                    }
                }
            }
        }

        $this->logger->debug('Dirección mapeada', [
            'input' => compact('country_code', 'state', 'city'),
            'output' => $result
        ]);

        return $result;
    }

    /**
     * Crea una nueva provincia en Verial
     *
     * @param string $nombre Nombre de la provincia
     * @param int $id_pais ID del país al que pertenece la provincia
     * @param string $codigo_nuts Código NUTS de la provincia (opcional)
     * @return array|null Respuesta de la API o null en caso de error
     * @since 1.0.0
     */
    public function createProvincia(string $nombre, int $id_pais, string $codigo_nuts = ''): ?array {
        $data = [
            'Nombre' => $nombre,
            'ID_Pais' => $id_pais,
            'CodigoNUTS' => $codigo_nuts
        ];

        $response = $this->api_client->post('NuevaProvinciaWS', $data);
        
        if ($this->api_client->isSuccess($response)) {
            $this->logger->info('Nueva provincia creada', ['nombre' => $nombre, 'id_pais' => $id_pais]);
            // Limpiar cache para refrescar datos
            unset($this->cache['provincias']);
            return $response;
        }

        $this->logger->error('Error creando provincia', [
            'nombre' => $nombre,
            'error' => $this->api_client->getErrorMessage($response)
        ]);
        return null;
    }

    /**
     * Crea una nueva localidad en Verial
     *
     * @param string $nombre Nombre de la localidad
     * @param int $id_pais ID del país al que pertenece la localidad
     * @param int $id_provincia ID de la provincia a la que pertenece la localidad
     * @param string $codigo_nuts Código NUTS de la localidad (opcional)
     * @param string $codigo_municipio_ine Código INE del municipio (opcional)
     * @return array|null Respuesta de la API o null en caso de error
     * @since 1.0.0
     */
    public function createLocalidad(string $nombre, int $id_pais, int $id_provincia = 0, string $codigo_nuts = '', string $codigo_municipio_ine = ''): ?array {
        $data = [
            'Nombre' => $nombre,
            'ID_Pais' => $id_pais,
            'ID_Provincia' => $id_provincia,
            'CodigoNUTS' => $codigo_nuts,
            'CodigoMunicipioINE' => $codigo_municipio_ine
        ];

        $response = $this->api_client->post('NuevaLocalidadWS', $data);
        
        if ($this->api_client->isSuccess($response)) {
            $this->logger->info('Nueva localidad creada', ['nombre' => $nombre, 'id_provincia' => $id_provincia]);
            // Limpiar cache para refrescar datos
            unset($this->cache['localidades']);
            return $response;
        }

        $this->logger->error('Error creando localidad', [
            'nombre' => $nombre,
            'error' => $this->api_client->getErrorMessage($response)
        ]);
        return null;
    }

    /**
     * Limpia la caché interna del servicio geográfico
     *
     * Útil para forzar la actualización de datos desde la API en la próxima petición.
     *
     * @return void
     * @since 1.0.0
     */
    public function clearCache(): void {
        $this->cache = [];
        $this->logger->info('Cache geográfico limpiado');
    }
}