<?php
declare(strict_types=1);

namespace Verial\Endpoints;

use WP_REST_Request;
use WP_REST_Response;
use Verial\ApiConnector;
use MiIntegracionApi\Traits\ErrorHandler;

class GetDocumentosClienteWS {
    use ErrorHandler;
    public static function register() {
        register_rest_route('verial/v1', '/get-documentos-cliente', [
            'methods' => 'GET',
            'callback' => [self::class, 'handle'],
            'permission_callback' => function () {
                return current_user_can('manage_woocommerce');
            },
            'args' => [
                'id_cliente' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) { return $param > 0; }
                ],
                'fechainicio' => [
                    'required' => false,
                    'type' => 'string',
                ],
                'fechafin' => [
                    'required' => false,
                    'type' => 'string',
                ],
            ],
        ]);
    }

    public static function handle(WP_REST_Request $request): WP_REST_Response {
        $id_cliente = (int) $request->get_param('id_cliente');
        $fechainicio = $request->get_param('fechainicio');
        $fechafin = $request->get_param('fechafin');
        
        // Obtener sesión desde configuración centralizada
        $session = 18; // Valor por defecto
        try {
            if (class_exists('VerialApiConfig')) {
                $verial_config = \VerialApiConfig::getInstance();
                $session = $verial_config->getVerialSessionId();
            }
        } catch (\Exception $e) {
            // Usar valor por defecto si hay error
            error_log('Error obteniendo sesión desde VerialApiConfig: ' . $e->getMessage());
        }

        $params = [
            'x' => $session,
            'id_cliente' => $id_cliente
        ];
        if ($fechainicio && $fechafin) {
            $params['fechainicio'] = $fechainicio;
            $params['fechafin'] = $fechafin;
        }

        try {
            $api = ApiConnector::getInstance();
            $response = $api->call('GetDocumentosClienteWS', $params);
            if (isset($response['InfoError']) && $response['InfoError']['Error']) {
                return self::create_rest_error('api_error', $response['InfoError']['Mensaje'], 400);
            }
            return new WP_REST_Response([
                'documentos' => $response['Documentos'] ?? [],
                'info_error' => $response['InfoError'] ?? null
            ], 200);
        } catch (\Throwable $e) {
            return self::create_server_error($e->getMessage());
        }
    }
}
