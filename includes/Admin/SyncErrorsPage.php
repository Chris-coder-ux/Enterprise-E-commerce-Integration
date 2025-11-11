<?php
/**
 * Página de administración para la gestión de errores de sincronización
 *
 * Este archivo contiene las clases necesarias para mostrar y gestionar
 * los errores que ocurren durante los procesos de sincronización.
 *
 * @package    MiIntegracionApi
 * @subpackage Admin
 * @since      1.0.0
 */

declare(strict_types=1);

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use MiIntegracionApi\Core\Installer;
use WP_List_Table;

/**
 * Clase para la gestión de la página de errores de sincronización
 *
 * Esta clase se encarga de renderizar la interfaz de administración
 * que muestra los errores ocurridos durante los procesos de sincronización
 * y proporciona funcionalidades para gestionarlos.
 *
 * @package MiIntegracionApi\Admin
 * @since   1.0.0
 */
class SyncErrorsPage {

    /**
     * Renderiza la página de administración de errores de sincronización
     *
     * Este método se encarga de mostrar la interfaz de administración
     * que contiene la tabla con los errores de sincronización.
     *
     * @return void
     * @since 1.0.0
     */
    public static function render(): void {
        // Verificar que la tabla de errores exista, si no, crearla
        global $wpdb;
        $table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
            Installer::activate();
        }
        
        // Mostrar notificaciones de éxito/error
        settings_errors( 'sync-errors' );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Registro de Errores de Sincronización', 'mi-integracion-api' ); ?></h1>
            <p><?php esc_html_e( 'Aquí se muestran los errores ocurridos durante los procesos de sincronización.', 'mi-integracion-api' ); ?></p>
            
            <form method="post" id="sync-errors-form">
                <?php
                // Crear y mostrar la tabla de errores
                $errors_list_table = new Sync_Errors_List_Table();
                $errors_list_table->prepare_items();
                $errors_list_table->search_box( __( 'Buscar errores', 'mi-integracion-api' ), 'search_id' );
                $errors_list_table->display();
                ?>
            </form>
            
            <?php wp_nonce_field( 'bulk_' . Sync_Errors_List_Table::class, '_wpnonce', false ); ?>
        </div>
        <?php
    }
}

/**
 * Clase para mostrar y gestionar la tabla de errores de sincronización
 *
 * Esta clase extiende WP_List_Table para proporcionar una interfaz
 * personalizada que muestra los errores de sincronización con funcionalidades
 * de búsqueda, ordenación y acciones por lotes.
 *
 * @package MiIntegracionApi\Admin
 * @since 1.0.0
 */
class Sync_Errors_List_Table extends WP_List_Table {

    /**
     * Constructor de la clase
     *
     * Inicializa la tabla con los parámetros básicos.
     */
    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Error de Sincronización', 'mi-integracion-api' ),
            'plural'   => __( 'Errores de Sincronización', 'mi-integracion-api' ),
            'ajax'     => false,
            'screen'   => 'sync-errors'
        ] );
    }

    /**
     * Define las columnas de la tabla
     *
     * @return array Lista de columnas con sus etiquetas
     * @since 1.0.0
     */
    public function get_columns(): array {
        return [
            'cb'            => '<input type="checkbox" />',
            'item_sku'      => __( 'SKU del Producto', 'mi-integracion-api' ),
            'error_message' => __( 'Mensaje de Error', 'mi-integracion-api' ),
            'timestamp'     => __( 'Fecha y Hora', 'mi-integracion-api' ),
            'sync_run_id'   => __( 'ID de Sincronización', 'mi-integracion-api' ),
            'error_type'    => __( 'Tipo de Error', 'mi-integracion-api' ),
            'attempts'      => __( 'Intentos', 'mi-integracion-api' ),
        ];
    }

    /**
     * Prepara los elementos para mostrarlos en la tabla
     *
     * Realiza la consulta a la base de datos, configura la paginación
     * y prepara los datos para mostrarlos en la tabla.
     *
     * @return void
     * @since 1.0.0
     */
    public function prepare_items(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;
        $per_page = $this->get_items_per_page( 'sync_errors_per_page', 20 );
        $current_page = $this->get_pagenum();
        
        // Ordenación
        $orderby = isset( $_REQUEST['orderby'] ) ? sanitize_sql_orderby( $_REQUEST['orderby'] ) : 'timestamp';
        $order = ( isset( $_REQUEST['order'] ) && in_array( strtoupper( $_REQUEST['order'] ), [ 'ASC', 'DESC' ], true ) ) 
               ? strtoupper( $_REQUEST['order'] ) 
               : 'DESC';

        // Búsqueda
        $search = '';
        if ( ! empty( $_REQUEST['s'] ) ) {
            $search = sanitize_text_field( wp_unslash( $_REQUEST['s'] ) );
        }

        // Consulta base
        $query = "FROM {$table_name} WHERE 1=1";
        $query_params = [];

        // Aplicar búsqueda
        if ( ! empty( $search ) ) {
            $query .= " AND (
                item_sku LIKE %s OR 
                error_message LIKE %s OR 
                error_type LIKE %s
            )";
            $search_term = '%' . $wpdb->esc_like( $search ) . '%';
            $query_params = array_merge( $query_params, [ $search_term, $search_term, $search_term ] );
        }

        // Obtener el total de elementos
        $total_items = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(id) {$query}", $query_params ) );

        // Configurar paginación
        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page )
        ] );

        // Obtener los elementos para la página actual
        $offset = ( $current_page - 1 ) * $per_page;
        $query = "SELECT * {$query} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $query_params = array_merge( $query_params, [ $per_page, $offset ] );
        
        $this->items = $wpdb->get_results(
            $wpdb->prepare( $query, $query_params ),
            ARRAY_A
        );

        // Configurar encabezados de columnas
        $this->_column_headers = [ 
            $this->get_columns(), 
            $this->get_hidden_columns(), 
            $this->get_sortable_columns() 
        ];
    }

    /**
     * Muestra el contenido de una columna cuando no hay un método específico para ella
     *
     * Este método se utiliza para mostrar el contenido de las columnas que no tienen
     * un método específico para mostrar su contenido.
     *
     * @param array $item El elemento actual
     * @param string $column_name El nombre de la columna
     * @return string El contenido de la celda
     * @since 1.0.0
     */
    public function column_default( $item, $column_name ) {
        switch ( $column_name ) {
            case 'timestamp':
                return $item[ $column_name ] 
                    ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item[ $column_name ] ) )
                    : '';
            case 'error_message':
                return '<div class="error-message">' . esc_html( $item[ $column_name ] ) . '</div>';
            default:
                return isset( $item[ $column_name ] ) ? esc_html( $item[ $column_name ] ) : '';
        }
    }

    /**
     * Renderiza la columna de checkbox para selección múltiple
     *
     * @param array $item El elemento actual
     * @return string HTML del checkbox
     * @since 1.0.0
     */
    public function column_cb( $item ): string {
        return sprintf(
            '<input type="checkbox" name="error[]" value="%s" />', 
            absint( $item['id'] )
        );
    }

    /**
     * Define las acciones por lotes disponibles
     *
     * @return array Lista de acciones con sus etiquetas
     * @since 1.0.0
     */
    public function get_bulk_actions(): array {
        return [
            'retry'  => __( 'Reintentar Sincronización', 'mi-integracion-api' ),
            'delete' => __( 'Eliminar', 'mi-integracion-api' ),
        ];
    }

    /**
     * Procesa las acciones por lotes
     *
     * Maneja las acciones de eliminación y reintento de sincronización
     * para los elementos seleccionados.
     *
     * @return void
     * @since 1.0.0
     */
    public function process_bulk_action(): void {
        // Verificar nonce
        if ( ! check_admin_referer( 'bulk_' . __CLASS__, '_wpnonce' ) ) {
            return;
        }

        // Obtener los IDs de los elementos seleccionados
        $ids = isset( $_REQUEST['error'] ) ? array_map( 'intval', (array) $_REQUEST['error'] ) : [];
        $ids = array_filter( $ids, 'absint' );

        if ( empty( $ids ) ) {
            add_settings_error(
                'sync-errors',
                'no-items-selected',
                __( 'No se seleccionaron elementos para procesar.', 'mi-integracion-api' ),
                'error'
            );
            return;
        }

        if ( 'delete' === $this->current_action() ) {
            $this->process_bulk_delete( $ids );
        } elseif ( 'retry' === $this->current_action() ) {
            $this->process_bulk_retry( $ids );
        }
    }

    /**
     * Procesa la eliminación por lotes de errores
     *
     * @param int[] $ids IDs de los errores a eliminar
     * @return void
     * @since 1.0.0
     */
    protected function process_bulk_delete( array $ids ): void {
        global $wpdb;
        $table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        
        // Preparar y ejecutar la consulta de eliminación
        $query = $wpdb->prepare(
            "DELETE FROM {$table_name} WHERE id IN ({$placeholders})",
            $ids
        );
        
        $result = $wpdb->query( $query );
        
        if ( false === $result ) {
            add_settings_error(
                'sync-errors',
                'delete-failed',
                __( 'Error al intentar eliminar los registros seleccionados.', 'mi-integracion-api' ),
                'error'
            );
        } else {
            add_settings_error(
                'sync-errors',
                'items-deleted',
                sprintf(
                    // translators: %d: Número de elementos eliminados
                    _n( '%d elemento eliminado correctamente.', '%d elementos eliminados correctamente.', $result, 'mi-integracion-api' ),
                    $result
                ),
                'updated'
            );
        }
    }

    /**
     * Procesa el reintento de sincronización por lotes
     *
     * @param int[] $ids IDs de los errores a reintentar
     * @return void
     * @since 1.0.0
     */
    protected function process_bulk_retry( array $ids ): void {
        // Verificar si la función de reintento está disponible
        if ( ! function_exists( 'mi_integracion_retry_sync_errors' ) ) {
            add_settings_error(
                'sync-errors',
                'retry-not-available',
                __( 'La función de reintento no está disponible actualmente.', 'mi-integracion-api' ),
                'error'
            );
            return;
        }

        // Inicializar el script de reintento
        wp_enqueue_script( 'mi-integracion-api-sync-errors' );
        
        // Pasar datos al script
        wp_localize_script( 'mi-integracion-api-sync-errors', 'syncErrors', [
            'nonce'     => wp_create_nonce( 'wp_rest' ),
            'retry_url' => esc_url_raw( rest_url( 'mi-integracion-api/v1/sync/retry' ) ),
            'error_ids' => $ids,
            'messages'  => [
                'success' => __( 'Reintento completado correctamente.', 'mi-integracion-api' ),
                'error'   => __( 'Error al reintentar la sincronización.', 'mi-integracion-api' ),
                'partial' => __( 'Algunos elementos no pudieron ser procesados.', 'mi-integracion-api' )
            ]
        ] );

        // Mostrar mensaje informativo
        add_settings_error(
            'sync-errors',
            'sync-errors-retrying',
            sprintf(
                // translators: %d: Número de elementos a reintentar
                _n( 
                    'Iniciando reintento para %d error seleccionado...', 
                    'Iniciando reintento para %d errores seleccionados...', 
                    count( $ids ), 
                    'mi-integracion-api' 
                ),
                count( $ids )
            ),
            'info'
        );
    }
    
    /**
     * Define las columnas que pueden ser ordenadas
     *
     * @return array Lista de columnas ordenables
     * @since 1.0.0
     */
    public function get_sortable_columns(): array {
        return [
            'item_sku'    => [ 'item_sku', false ],
            'timestamp'   => [ 'timestamp', true ],
            'error_type'  => [ 'error_type', false ],
            'sync_run_id' => [ 'sync_run_id', false ]
        ];
    }
    
    /**
     * Define las columnas ocultas por defecto
     *
     * @return array Lista de columnas ocultas
     * @since 1.0.0
     */
    public function get_hidden_columns(): array {
        return [ 'sync_run_id' ];
    }
}