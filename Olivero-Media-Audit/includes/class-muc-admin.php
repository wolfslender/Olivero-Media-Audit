<?php
/**
 * Media Usage Checker Admin UI
 * 
 * @package Oliverodev_Media_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Oliverodev_Media_Audit_Admin {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'handle_actions']);
        add_action('wp_ajax_oliverodev_media_audit_delete_item', [$this, 'delete_item_ajax']);
        add_action('wp_ajax_oliverodev_media_audit_load_more_files', [$this, 'load_more_files_ajax']);

        // Batch Scanning Handlers
        add_action('wp_ajax_oliverodev_media_audit_start_scan', [$this, 'start_scan_ajax']);
        add_action('wp_ajax_oliverodev_media_audit_process_batch', [$this, 'process_batch_ajax']);
        add_action('wp_ajax_oliverodev_media_audit_finish_scan', [$this, 'finish_scan_ajax']);
        add_action('wp_ajax_oliverodev_media_audit_load_tab', [$this, 'load_tab_ajax']);

        // Feature: usage locations
        add_action('wp_ajax_oliverodev_media_audit_get_locations', [$this, 'get_locations_ajax']);

        // Feature: CSV export (handled in admin_init via GET param)
        add_action('admin_init', [$this, 'maybe_export_csv']);
    }

    private function get_attachment_id_from_request( $key = 'media_id' ) {
        if ( wp_doing_ajax() ) {
            check_ajax_referer( 'oliverodev_media_audit_ajax_nonce', 'nonce' );
        }

        $media_id = isset($_POST[ $key ]) ? absint(wp_unslash($_POST[ $key ])) : 0;
        if ( 0 === $media_id ) {
            return 0;
        }

        $post = get_post( $media_id );
        if ( ! $post || 'attachment' !== $post->post_type ) {
            return 0;
        }

        return $media_id;
    }

    private function user_can_manage_attachment( $media_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        if ( 0 === absint( $media_id ) ) {
            return false;
        }

        if ( ! current_user_can( 'delete_post', $media_id ) ) {
            return false;
        }

        return true;
    }

    public function handle_actions() {
        if ( ! isset( $_POST['oliverodev_media_audit_action'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_POST['oliverodev_media_audit_action'] ) );
        $allowed_actions = array(
            'delete_perm',
            'delete_single',
        );
        if ( ! in_array( $action, $allowed_actions, true ) ) {
            return;
        }

        $media_id = isset($_POST['media_id']) ? absint(wp_unslash($_POST['media_id'])) : 0;
        $scanner = Oliverodev_Media_Audit_Scanner::get_instance();

        switch($action) {
            case 'delete_perm':
                check_admin_referer('oliverodev_media_audit_delete_perm_nonce');
                if ( ! $this->user_can_manage_attachment( $media_id ) ) {
                    break;
                }
                $scanner->delete_permanently($media_id);
                wp_safe_redirect(remove_query_arg(['oliverodev_media_audit_action', 'media_id', '_wpnonce', 'oliverodev_media_audit_delete_perm_nonce']));
                exit;
                break;
            case 'delete_single':
                check_admin_referer('oliverodev_media_audit_delete_single_nonce');
                if ( ! $this->user_can_manage_attachment( $media_id ) ) {
                    break;
                }
                $scanner->delete_permanently($media_id);
                $scanner->update_stats();
                wp_safe_redirect(remove_query_arg(['oliverodev_media_audit_action', 'media_id', '_wpnonce', 'oliverodev_media_audit_delete_single_nonce']));
                exit;
                break;
        }
    }

    public function add_admin_menu() {
        add_management_page(
            esc_html__( 'OliveroDev Media Audit', 'oliverodev-media-audit' ),
            esc_html__( 'OliveroDev Media Audit', 'oliverodev-media-audit' ),
            'manage_options',
            'oliverodev-media-audit',
            array( $this, 'render_admin_page' )
        );
    }

    public function enqueue_assets($hook) {
        if ('tools_page_oliverodev-media-audit' !== $hook) {
            return;
        }

        wp_enqueue_style( 'oliverodev-media-audit-admin-style', OLIVERODEV_MEDIA_AUDIT_PLUGIN_URL . 'assets/css/admin.css', [], OLIVERODEV_MEDIA_AUDIT_VERSION );
        wp_enqueue_script( 'oliverodev-media-audit-admin-script', OLIVERODEV_MEDIA_AUDIT_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], OLIVERODEV_MEDIA_AUDIT_VERSION, true );

        $current_tab_for_js = filter_input( INPUT_GET, 'tab', FILTER_UNSAFE_RAW );
        $current_tab_for_js = is_string( $current_tab_for_js ) ? sanitize_key( $current_tab_for_js ) : 'dashboard';

        wp_localize_script(
            'oliverodev-media-audit-admin-script',
            'oliverodevMediaAudit',
            array(
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'nonce'      => wp_create_nonce( 'oliverodev_media_audit_ajax_nonce' ),
                'currentTab' => $current_tab_for_js,
                'exportUrl'  => add_query_arg(
                    array(
                        'page'                          => 'oliverodev-media-audit',
                        'oliverodev_export_csv'         => '1',
                        '_wpnonce'                      => wp_create_nonce( 'oliverodev_media_audit_export_csv' ),
                    ),
                    admin_url( 'tools.php' )
                ),
                'strings'  => array(
                    'scanning'          => __( 'Scanning...', 'oliverodev-media-audit' ),
                    'complete'          => __( 'Scan Complete!', 'oliverodev-media-audit' ),
                    'processing'        => __( 'Processing...', 'oliverodev-media-audit' ),
                    /* translators: 1: scanned count, 2: total count, 3: remaining count */
                    'scanning_progress' => _x( 'Scanning %1$s of %2$s files · %3$s remaining', 'Scan progress placeholder', 'oliverodev-media-audit' ),
                    'calculating'       => __( 'Calculating final stats...', 'oliverodev-media-audit' ),
                    'initializing'      => __( 'Initializing...', 'oliverodev-media-audit' ),
                    'start_new_scan'    => __( 'Start New Scan', 'oliverodev-media-audit' ),
                    /* translators: %s: number of files. */
                    'checking_files'    => _x( 'Checking %s files', 'Number of files placeholder', 'oliverodev-media-audit' ),
                    /* translators: %s: number of files. */
                    'potential_savings' => _x( 'Potential savings: %s files', 'Number of files placeholder', 'oliverodev-media-audit' ),
                    'all_files_loaded'  => __( 'All files loaded.', 'oliverodev-media-audit' ),
                    'no_more_files'     => __( 'No more files to load.', 'oliverodev-media-audit' ),
                    'failed_start_scan' => __( 'Failed to start scan.', 'oliverodev-media-audit' ),
                    /* translators: %s: batch number. */
                    'error_scanning_batch' => _x( 'Error scanning batch %s', 'Batch number placeholder', 'oliverodev-media-audit' ),
                    'server_timeout'    => __( 'Server timeout. Try again.', 'oliverodev-media-audit' ),
                    'action_failed'     => __( 'Action failed.', 'oliverodev-media-audit' ),
                    'unauthorized'      => __( 'Unauthorized', 'oliverodev-media-audit' ),
                    'delete_title'      => __( 'Delete file permanently?', 'oliverodev-media-audit' ),
                    'delete_confirm'    => __( 'Delete Permanently', 'oliverodev-media-audit' ),
                    'cancel'            => __( 'Cancel', 'oliverodev-media-audit' ),
                    'loading_locations' => __( 'Loading...', 'oliverodev-media-audit' ),
                    'no_locations'      => __( 'No specific location found.', 'oliverodev-media-audit' ),
                    'where_used'        => __( 'Where is it used?', 'oliverodev-media-audit' ),
                    'hide_locations'    => __( 'Hide', 'oliverodev-media-audit' ),
                ),
            )
        );
    }

    /**
     * AJAX: Delete Item Permanently
     */
    public function delete_item_ajax() {
        check_ajax_referer('oliverodev_media_audit_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'oliverodev-media-audit'));

        $media_id = $this->get_attachment_id_from_request();
        if ( 0 === $media_id || ! $this->user_can_manage_attachment( $media_id ) ) {
            wp_send_json_error(__('Unauthorized', 'oliverodev-media-audit'));
        }
        if (Oliverodev_Media_Audit_Scanner::get_instance()->delete_permanently($media_id)) {
            wp_send_json_success();
        }
        wp_send_json_error(__('Failed to delete item', 'oliverodev-media-audit'));
    }

    /**
     * AJAX: Load More Files
     */
    public function load_more_files_ajax() {
        check_ajax_referer('oliverodev_media_audit_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'oliverodev-media-audit'));
        
        $page = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) + 1 : 1;
        $filter = isset($_POST['filter']) ? sanitize_key(wp_unslash($_POST['filter'])) : 'all';
        $filter = in_array( $filter, array( 'all', 'unused' ), true ) ? $filter : 'all';
        $orderby = isset($_POST['orderby']) ? sanitize_key(wp_unslash($_POST['orderby'])) : 'date';
        $orderby = in_array( $orderby, array( 'date', 'size' ), true ) ? $orderby : 'date';
        $order = isset($_POST['order']) ? sanitize_key(wp_unslash($_POST['order'])) : 'desc';
        $mime_type_filter = isset($_POST['mime']) ? sanitize_key(wp_unslash($_POST['mime'])) : '';
        $mime_type_filter = in_array( $mime_type_filter, array( '', 'image', 'video', 'audio', 'document', 'archive' ), true ) ? $mime_type_filter : '';
        $order = strtoupper( $order );
        $order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';

        $args = [
            'post_type' => 'attachment',
            'post_status' => array('inherit', 'publish', 'private'),
            'posts_per_page' => 20,
            'paged' => $page,
        ];

        if ($filter === 'unused') {
            $args['meta_query'] = [
                [
                    'key' => '_oliverodev_media_audit_is_unused',
                    'value' => '1',
                    'compare' => '='
                ],
            ];
        }

        if ($mime_type_filter) {
             if ($mime_type_filter === 'image') $args['post_mime_type'] = 'image/%';
             elseif ($mime_type_filter === 'video') $args['post_mime_type'] = 'video/%';
             elseif ($mime_type_filter === 'audio') $args['post_mime_type'] = 'audio/%';
             elseif ($mime_type_filter === 'document') {
                $args['post_mime_type'] = ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'text/plain'];
             } elseif ($mime_type_filter === 'archive') {
                $args['post_mime_type'] = ['application/zip', 'application/x-rar-compressed', 'application/x-tar'];
             }
        }

        if ($orderby === 'size') {
            $args['meta_key'] = '_oliverodev_media_audit_file_size';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = $order;
        } else {
            $args['orderby'] = 'date';
            $args['order'] = $order;
        }

        $args = apply_filters( 'oliverodev_media_audit_media_query_args', $args, $filter, $orderby, $order, $mime_type_filter, true );

        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            ob_start();
            while ($query->have_posts()) {
                $query->the_post();
                $this->render_media_row(get_the_ID());
            }
            wp_reset_postdata();
            wp_send_json_success(ob_get_clean());
        } else {
            wp_reset_postdata();
            wp_send_json_error(__('No more files to load.', 'oliverodev-media-audit'));
        }
    }

    /**
     * AJAX: Start Batch Scan
     */
    public function start_scan_ajax() {
        check_ajax_referer('oliverodev_media_audit_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'oliverodev-media-audit'));

        $total       = Oliverodev_Media_Audit_Scanner::get_instance()->get_total_attachments();
        $max_batch   = absint( get_option( 'oliverodev_media_audit_batch_size', 20 ) );
        $max_batch   = max( 1, min( 200, $max_batch ) );
        // Start conservative — the adaptive engine grows the batch size automatically.
        $initial     = min( $max_batch, 5 );
        wp_send_json_success( array( 'total' => $total, 'batch_size' => $initial, 'max_batch_size' => $max_batch ) );
    }

    /**
     * AJAX: Process Batch (offset-based, adaptive batch size)
     */
    public function process_batch_ajax() {
        check_ajax_referer('oliverodev_media_audit_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'oliverodev-media-audit'));

        $offset     = isset( $_POST['offset'] )     ? absint( wp_unslash( $_POST['offset'] ) )     : 0;
        $batch_size = isset( $_POST['batch_size'] ) ? absint( wp_unslash( $_POST['batch_size'] ) ) : 5;
        $max_batch  = absint( get_option( 'oliverodev_media_audit_batch_size', 20 ) );
        $max_batch  = max( 1, min( 200, $max_batch ) );
        $batch_size = max( 1, min( $max_batch, $batch_size ) );

        $result = Oliverodev_Media_Audit_Scanner::get_instance()->scan_batch( $offset, $batch_size );
        wp_send_json_success( $result );
    }

    /**
     * AJAX: Load a single tab's inner HTML — enables SPA-style navigation.
     * The JS intercepts tab clicks, fires this action, and swaps only the
     * .muc-content div without a full WordPress page reload.
     */
    public function load_tab_ajax() {
        check_ajax_referer( 'oliverodev_media_audit_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'oliverodev-media-audit' ) );
        }

        $tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : 'dashboard';

        $tabs = apply_filters( 'oliverodev_media_audit_admin_tabs', array(
            'dashboard'    => array( 'label' => '', 'icon' => '' ),
            'unused-files' => array( 'label' => '', 'icon' => '' ),
            'media-files'  => array( 'label' => '', 'icon' => '' ),
            'settings'     => array( 'label' => '', 'icon' => '' ),
        ) );

        if ( ! isset( $tabs[ $tab ] ) ) {
            $tab = 'dashboard';
        }

        ob_start();
        switch ( $tab ) {
            case 'dashboard':
                $this->display_dashboard();
                break;
            case 'unused-files':
                $this->display_media_files( 'unused' );
                break;
            case 'media-files':
                $this->display_media_files( 'all' );
                break;
            case 'settings':
                $this->display_settings();
                break;
            default:
                do_action( 'oliverodev_media_audit_render_admin_tab_' . $tab );
                do_action( 'oliverodev_media_audit_render_admin_tab', $tab );
                break;
        }
        $html = ob_get_clean();

        wp_send_json_success( array( 'html' => $html, 'tab' => $tab ) );
    }

    /**
     * AJAX: Get usage locations for a media item (Feature 1)
     */
    public function get_locations_ajax() {
        check_ajax_referer( 'oliverodev_media_audit_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'oliverodev-media-audit' ) );
        }

        $media_id  = isset( $_POST['media_id'] ) ? absint( wp_unslash( $_POST['media_id'] ) ) : 0;
        if ( 0 === $media_id ) {
            wp_send_json_error( __( 'Invalid media ID.', 'oliverodev-media-audit' ) );
        }

        $locations = Oliverodev_Media_Audit_Scanner::get_instance()->get_usage_locations( $media_id );
        wp_send_json_success( $locations );
    }

    /**
     * CSV Export handler (Feature 4) — triggered via GET on admin_init.
     */
    public function maybe_export_csv() {
        if ( ! isset( $_GET['oliverodev_export_csv'] ) || '1' !== $_GET['oliverodev_export_csv'] ) {
            return;
        }
        if ( ! isset( $_GET['page'] ) || 'oliverodev-media-audit' !== $_GET['page'] ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'oliverodev-media-audit' ) );
        }
        check_admin_referer( 'oliverodev_media_audit_export_csv' );

        $args = array(
            'post_type'              => 'attachment',
            'post_status'            => array( 'inherit', 'publish', 'private' ),
            'posts_per_page'         => -1,
            'fields'                 => 'ids',
            'no_found_rows'          => true,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                array(
                    'key'     => '_oliverodev_media_audit_is_unused',
                    'value'   => '1',
                    'compare' => '=',
                ),
            ),
        );
        $query = new WP_Query( $args );
        $ids   = $query->posts;

        $filename = 'unused-media-' . gmdate( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, array( 'ID', 'Filename', 'URL', 'File Size', 'MIME Type', 'Date Uploaded' ) );

        foreach ( $ids as $id ) {
            $file_path = get_attached_file( $id );
            $size_raw  = $file_path ? oliverodev_media_audit_filesize( $file_path ) : 0;
            fputcsv( $out, array(
                $id,
                basename( (string) $file_path ),
                wp_get_attachment_url( $id ),
                size_format( $size_raw ),
                get_post_mime_type( $id ),
                get_the_date( 'Y-m-d', $id ),
            ) );
        }

        fclose( $out );
        exit;
    }

    /**
     * AJAX: Finish Scan (Calculate Stats)
     */
    public function finish_scan_ajax() {
        check_ajax_referer('oliverodev_media_audit_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'oliverodev-media-audit'));

        $stats = Oliverodev_Media_Audit_Scanner::get_instance()->calculate_stats_from_meta();
        wp_send_json_success($stats);
    }

    /**
     * Render a single media row
     */
    private function render_media_row($media_id) {
        $file_path = get_attached_file($media_id);
        $raw_size = $file_path ? oliverodev_media_audit_filesize( $file_path ) : 0;
        $file_size = $raw_size > 0 ? size_format( $raw_size, 2 ) : 'N/A';
        $mime_type = get_post_mime_type($media_id);
        $is_unused_meta = get_post_meta( $media_id, '_oliverodev_media_audit_is_unused', true );
        if ( '' !== $is_unused_meta ) {
            $is_used = ( '0' === $is_unused_meta );
        } else {
            $is_used = oliverodev_media_audit_is_media_in_use( $media_id );
        }
        
        $cat = 'document';
        if (strpos($mime_type, 'image/') !== false) $cat = 'image';
        elseif (strpos($mime_type, 'video/') !== false) $cat = 'video';
        elseif (strpos($mime_type, 'audio/') !== false) $cat = 'audio';
        elseif (in_array($mime_type, ['application/zip', 'application/x-rar-compressed', 'application/x-tar'])) $cat = 'archive';
        ?>
        <?php
        $is_image   = wp_attachment_is_image( $media_id );
        $thumb_html = $is_image ? wp_get_attachment_image( $media_id, array( 60, 60 ) ) : '';
        $thumb_url  = $is_image ? (string) wp_get_attachment_image_url( $media_id, array( 60, 60 ) ) : '';
        $title      = get_the_title( $media_id );
        $attach_url = wp_get_attachment_url( $media_id );
        ?>
        <tr class="<?php echo esc_attr($is_used ? 'row-used' : 'row-unused'); ?>" data-id="<?php echo esc_attr($media_id); ?>" data-size="<?php echo esc_attr($raw_size); ?>" data-type="<?php echo esc_attr($cat); ?>">
            <td class="col-preview">
                <div class="media-preview">
                    <?php if ( $thumb_html ) : ?>
                        <?php echo $thumb_html; ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-media-default"></span>
                    <?php endif; ?>
                </div>
            </td>
            <td class="col-title">
                <strong><?php echo esc_html( $title ); ?></strong>
                <div class="row-actions">
                    <a href="<?php echo esc_url( $attach_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'View Original', 'oliverodev-media-audit' ); ?></a>
                </div>
            </td>
            <td class="col-meta">
                <span class="meta-type"><?php echo esc_html( strtoupper( str_replace( 'image/', '', $mime_type ) ) ); ?></span>
                <span class="meta-size"><?php echo esc_html( $file_size ); ?></span>
            </td>
            <td class="col-status">
                <span class="muc-status-pill <?php echo esc_attr( $is_used ? 'status-used' : 'status-unused' ); ?>">
                    <?php echo esc_html( $is_used ? __( 'Used', 'oliverodev-media-audit' ) : __( 'Unused', 'oliverodev-media-audit' ) ); ?>
                </span>
                <?php if ( $is_used ) : ?>
                    <div class="muc-locations-wrapper">
                        <button type="button" class="muc-where-used-btn" data-id="<?php echo esc_attr( $media_id ); ?>">
                            <span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Where?', 'oliverodev-media-audit' ); ?>
                        </button>
                        <div class="muc-locations-list" style="display:none;"></div>
                    </div>
                <?php endif; ?>
            </td>
            <td class="col-actions">
                <?php if ( ! $is_used ) : ?>
                    <button type="button"
                        class="button muc-item-action muc-delete-trigger"
                        data-action="delete"
                        data-id="<?php echo esc_attr( $media_id ); ?>"
                        data-filename="<?php echo esc_attr( $title ); ?>"
                        data-filesize="<?php echo esc_attr( $file_size ); ?>"
                        data-thumb="<?php echo esc_attr( $is_image ? 'image' : 'file' ); ?>"
                        data-imgurl="<?php echo esc_url( $thumb_url ); ?>">
                        <span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete Permanently', 'oliverodev-media-audit' ); ?>
                    </button>
                    <?php do_action( 'oliverodev_media_audit_row_actions', $media_id, $is_used ); ?>
                <?php else : ?>
                    <button disabled class="button disabled"><span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'In Use', 'oliverodev-media-audit' ); ?></button>
                    <?php do_action( 'oliverodev_media_audit_row_actions', $media_id, $is_used ); ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'oliverodev-media-audit' ) );
        }
        
        $tabs = array(
            'dashboard'   => array(
                'label' => __( 'Dashboard', 'oliverodev-media-audit' ),
                'icon'  => 'dashicons-dashboard',
            ),
            'unused-files' => array(
                'label' => __( 'Unused Files', 'oliverodev-media-audit' ),
                'icon'  => 'dashicons-warning',
            ),
            'media-files' => array(
                'label' => __( 'Library', 'oliverodev-media-audit' ),
                'icon'  => 'dashicons-admin-media',
            ),
            'settings'    => array(
                'label' => __( 'Settings', 'oliverodev-media-audit' ),
                'icon'  => 'dashicons-admin-settings',
            ),
        );
        $tabs = apply_filters( 'oliverodev_media_audit_admin_tabs', $tabs );

        $current_tab = filter_input( INPUT_GET, 'tab', FILTER_UNSAFE_RAW );
        $current_tab = is_string( $current_tab ) ? sanitize_key( $current_tab ) : 'dashboard';
        if ( ! isset( $tabs[ $current_tab ] ) ) {
            $current_tab = 'dashboard';
        }
        ?>
        <?php
        $is_pro        = function_exists( 'oliverodev_media_audit_is_pro' ) && oliverodev_media_audit_is_pro();
        $last_check    = absint( get_option( 'oliverodev_media_audit_last_check', 0 ) );
        $used_count    = absint( get_option( 'oliverodev_media_audit_used_count', 0 ) );
        $unused_count  = absint( get_option( 'oliverodev_media_audit_unused_count', 0 ) );
        $unused_size   = (int) get_option( 'oliverodev_media_audit_unused_size', 0 );
        $total_files   = $used_count + $unused_count;
        ?>
        <div class="wrap muc-wrap">
            <div class="muc-header">
                <div class="muc-header-top">
                    <!-- Branding -->
                    <div class="muc-header-brand">
                        <div class="muc-header-title-row">
                            <h1><?php esc_html_e( 'OliveroDev Media Audit', 'oliverodev-media-audit' ); ?></h1>
                            <div class="muc-header-badges">
                                <span class="muc-version-badge">v<?php echo esc_html( OLIVERODEV_MEDIA_AUDIT_VERSION ); ?></span>
                                <?php if ( $is_pro ) : ?>
                                    <span class="muc-plan-badge muc-plan-pro"><?php esc_html_e( 'PRO', 'oliverodev-media-audit' ); ?></span>
                                <?php else : ?>
                                    <span class="muc-plan-badge muc-plan-free"><?php esc_html_e( 'FREE', 'oliverodev-media-audit' ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="muc-header-tagline">
                            <?php esc_html_e( 'Find unused files. Know the risk. Clean without breaking your site.', 'oliverodev-media-audit' ); ?>
                        </p>
                    </div>

                    <!-- Stats (shown only after first scan) -->
                    <?php if ( $last_check ) : ?>
                    <div class="muc-header-stats">
                        <div class="muc-hstat">
                            <span class="muc-hstat-value"><?php echo esc_html( number_format_i18n( $total_files ) ); ?></span>
                            <span class="muc-hstat-label"><?php esc_html_e( 'Total files', 'oliverodev-media-audit' ); ?></span>
                        </div>
                        <div class="muc-hstat-divider"></div>
                        <div class="muc-hstat">
                            <span class="muc-hstat-value muc-hstat-green"><?php echo esc_html( number_format_i18n( $used_count ) ); ?></span>
                            <span class="muc-hstat-label"><?php esc_html_e( 'In use', 'oliverodev-media-audit' ); ?></span>
                        </div>
                        <div class="muc-hstat-divider"></div>
                        <div class="muc-hstat">
                            <span class="muc-hstat-value muc-hstat-amber"><?php echo esc_html( number_format_i18n( $unused_count ) ); ?></span>
                            <span class="muc-hstat-label"><?php esc_html_e( 'Unused', 'oliverodev-media-audit' ); ?></span>
                        </div>
                        <div class="muc-hstat-divider"></div>
                        <div class="muc-hstat">
                            <span class="muc-hstat-value muc-hstat-red"><?php echo esc_html( $unused_size > 0 ? size_format( $unused_size ) : '—' ); ?></span>
                            <span class="muc-hstat-label"><?php esc_html_e( 'Recoverable', 'oliverodev-media-audit' ); ?></span>
                        </div>
                    </div>
                    <?php else : ?>
                    <div class="muc-header-noscan">
                        <span class="dashicons dashicons-search"></span>
                        <p><?php esc_html_e( 'Run your first scan to see your library stats here.', 'oliverodev-media-audit' ); ?></p>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ( $last_check ) : ?>
                <div class="muc-header-bottom">
                    <span class="dashicons dashicons-clock"></span>
                    <?php
                    printf(
                        /* translators: %s: last scan date and time */
                        esc_html__( 'Last scan: %s', 'oliverodev-media-audit' ),
                        esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_check ) )
                    );
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <?php if ( ! function_exists( 'oliverodev_media_audit_is_pro' ) || ! oliverodev_media_audit_is_pro() ) : ?>
                <?php $this->render_pro_banner(); ?>
            <?php endif; ?>

            <nav class="muc-nav-tab-wrapper">
                <?php foreach ( $tabs as $tab_slug => $tab ) : ?>
                    <?php
                    $tab_slug = sanitize_key( (string) $tab_slug );
                    $url = add_query_arg(
                        array(
                            'page' => 'oliverodev-media-audit',
                            'tab'  => $tab_slug,
                        ),
                        admin_url( 'tools.php' )
                    );
                    $label = isset( $tab['label'] ) ? (string) $tab['label'] : $tab_slug;
                    $icon  = isset( $tab['icon'] ) ? sanitize_html_class( (string) $tab['icon'] ) : '';
                    $count = isset( $tab['count'] ) ? absint( $tab['count'] ) : 0;
                    if ( 'unused-files' === $tab_slug ) {
                        $count = absint( get_option( 'oliverodev_media_audit_unused_count', 0 ) );
                    }
                    ?>
                    <a href="<?php echo esc_url( $url ); ?>" class="nav-tab <?php echo esc_attr( $current_tab === $tab_slug ? 'nav-tab-active' : '' ); ?>">
                        <?php if ( '' !== $icon ) : ?>
                            <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                        <?php endif; ?>
                        <?php echo esc_html( $label ); ?>
                        <?php if ( $count > 0 && 'unused-files' === $tab_slug ) : ?>
                            <span class="count-badge"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="muc-content">
                <?php
                switch ($current_tab) {
                    case 'dashboard':
                        $this->display_dashboard();
                        break;
                    case 'unused-files':
                        $this->display_media_files('unused');
                        break;
                    case 'media-files':
                        $this->display_media_files('all');
                        break;
                    case 'settings':
                        $this->display_settings();
                        break;
                    default:
                        do_action( 'oliverodev_media_audit_render_admin_tab_' . $current_tab );
                        do_action( 'oliverodev_media_audit_render_admin_tab', $current_tab );
                        break;
                }
                ?>
            </div>
        </div>

        <!-- Delete Confirmation Modal (Feature 3) -->
        <div id="muc-delete-modal" class="muc-modal-overlay" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="muc-modal-title">
            <div class="muc-modal-box">
                <div class="muc-modal-header">
                    <span class="dashicons dashicons-warning muc-modal-icon"></span>
                    <h2 id="muc-modal-title"><?php esc_html_e( 'Delete file permanently?', 'oliverodev-media-audit' ); ?></h2>
                </div>
                <div class="muc-modal-body">
                    <div class="muc-modal-preview" id="muc-modal-preview"></div>
                    <div class="muc-modal-info">
                        <p class="muc-modal-filename" id="muc-modal-filename"></p>
                        <p class="muc-modal-filesize" id="muc-modal-filesize"></p>
                        <p class="muc-modal-warning"><?php esc_html_e( 'This action cannot be undone. The file and all its generated sizes will be permanently removed from your server.', 'oliverodev-media-audit' ); ?></p>
                    </div>
                </div>
                <div class="muc-modal-footer">
                    <button type="button" id="muc-modal-cancel" class="button button-secondary">
                        <?php esc_html_e( 'Cancel', 'oliverodev-media-audit' ); ?>
                    </button>
                    <button type="button" id="muc-modal-confirm" class="button muc-btn-danger" data-id="">
                        <span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete Permanently', 'oliverodev-media-audit' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function display_dashboard() {
        $total_media = wp_count_posts('attachment')->inherit;
        $used_count = get_option('oliverodev_media_audit_used_count', 0);
        $unused_count = get_option('oliverodev_media_audit_unused_count', 0);
        $used_size = get_option('oliverodev_media_audit_used_size', 0);
        $unused_size = get_option('oliverodev_media_audit_unused_size', 0);
        $last_check = get_option('oliverodev_media_audit_last_check');
        
        // Calculate percentages
        $used_percent = $total_media > 0 ? round(($used_count / $total_media) * 100) : 0;
        $unused_percent = $total_media > 0 ? round(($unused_count / $total_media) * 100) : 0;

        ?>
        <div class="muc-dashboard">
            <div class="muc-welcome-banner">
                <div class="banner-text">
                    <h2><?php esc_html_e('Ready to clean up your site?', 'oliverodev-media-audit'); ?></h2>
                    <p><?php esc_html_e('Identify and remove unused media files to free up disk space and improve performance.', 'oliverodev-media-audit'); ?></p>
                </div>
                <div class="banner-actions">
                    <form method="post" class="muc-scan-form">
                        <?php wp_nonce_field('oliverodev_media_audit_force_check', 'oliverodev_media_audit_force_check_nonce'); ?>
                        <button type="submit" name="oliverodev_media_audit_force_check" class="button button-primary button-hero">
                            <span class="dashicons dashicons-search"></span> <?php esc_html_e('Start New Scan', 'oliverodev-media-audit'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="muc-scan-progress" style="display:none;">
                <div class="scan-progress-track">
                    <div class="scan-progress-bar" style="width:0%;"></div>
                </div>
                <div class="scan-status-text"><?php esc_html_e('Initializing...', 'oliverodev-media-audit'); ?></div>
            </div>

            <div class="muc-stats-grid">
                <div class="muc-stat-card total highlight">
                    <div class="stat-icon"><span class="dashicons dashicons-admin-media"></span></div>
                    <div class="stat-info">
                        <h3><?php esc_html_e('Media Library Size', 'oliverodev-media-audit'); ?></h3>
                        <p class="muc-stat-number" data-value="<?php echo esc_attr( (float) $used_size + (float) $unused_size ); ?>" data-is-size="1"><?php echo esc_html( size_format( (float) $used_size + (float) $unused_size ) ); ?></p>
                        <?php /* translators: %s: number of files. */ ?>
                        <span class="sub-stat"><?php printf( esc_html_x( 'Checking %s files', 'Number of files placeholder', 'oliverodev-media-audit' ), esc_html( number_format_i18n( $total_media ) ) ); ?></span>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'oliverodev-media-audit', 'tab' => 'media-files', 'filter' => 'all'], admin_url('tools.php'))); ?>" class="view-link"><?php esc_html_e('View Library', 'oliverodev-media-audit'); ?> &rarr;</a>
                    </div>
                </div>
                <div class="muc-stat-card used">
                    <div class="stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="stat-info">
                        <h3><?php esc_html_e('Files in Use', 'oliverodev-media-audit'); ?></h3>
                        <p class="muc-stat-number" data-value="<?php echo esc_attr( $used_count ); ?>"><?php echo esc_html( number_format_i18n( $used_count ) ); ?></p>
                        <div class="progress-bar"><div class="progress" style="width: <?php echo esc_attr($used_percent); ?>%"></div></div>
                        <span class="percent"><?php echo esc_html($used_percent); ?>%</span>
                        <span class="sub-stat"><?php echo esc_html( size_format( (float) $used_size ) ); ?></span>
                    </div>
                </div>
                <div class="muc-stat-card unused warning">
                    <div class="stat-icon"><span class="dashicons dashicons-warning"></span></div>
                    <div class="stat-info">
                        <h3><?php esc_html_e('Space to Recover', 'oliverodev-media-audit'); ?></h3>
                        <p class="muc-stat-number" data-value="<?php echo esc_attr($unused_size); ?>" data-is-size="1"><?php echo esc_html(size_format((float)$unused_size)); ?></p>
                        <div class="progress-bar"><div class="progress" style="width: <?php echo esc_attr($unused_percent); ?>%"></div></div>
                        <span class="percent"><?php echo esc_html($unused_percent); ?>%</span>
                        <?php /* translators: %s: number of files. */ ?>
                        <span class="sub-stat"><?php printf( esc_html_x( 'Potential savings: %s files', 'Number of files placeholder', 'oliverodev-media-audit' ), esc_html( number_format_i18n( $unused_count ) ) ); ?></span>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'oliverodev-media-audit', 'tab' => 'unused-files'], admin_url('tools.php'))); ?>" class="view-link"><?php esc_html_e('Clean Now', 'oliverodev-media-audit'); ?> &rarr;</a>
                    </div>
                </div>
            </div>

            <?php if ($last_check): ?>
            <div class="muc-breakdown-section">
                <h3><?php esc_html_e('Library Breakdown', 'oliverodev-media-audit'); ?></h3>
                <div class="muc-breakdown-list">
                    <?php 
                    $breakdown = get_option('oliverodev_media_audit_breakdown', []);
                    $types = [
                        'image' => ['label' => __('Images', 'oliverodev-media-audit'), 'icon' => 'format-image'],
                        'document' => ['label' => __('Documents', 'oliverodev-media-audit'), 'icon' => 'media-document'],
                        'video' => ['label' => __('Videos', 'oliverodev-media-audit'), 'icon' => 'format-video'],
                        'audio' => ['label' => __('Audio', 'oliverodev-media-audit'), 'icon' => 'format-audio'],
                        'archive' => ['label' => __('Archives', 'oliverodev-media-audit'), 'icon' => 'media-archive'],
                    ];
                    foreach ($types as $key => $data) : 
                        if (empty($breakdown[$key]['count'])) continue;
                    ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'oliverodev-media-audit', 'tab' => 'media-files', 'filter' => 'all', 'mime_type' => $key], admin_url('tools.php'))); ?>" class="breakdown-item" data-type="<?php echo esc_attr($key); ?>">
                            <span class="dashicons dashicons-<?php echo esc_attr($data['icon']); ?>"></span>
                            <span class="label"><?php echo esc_html($data['label']); ?></span>
                            <span class="count" data-value="<?php echo esc_attr( $breakdown[ $key ]['count'] ); ?>"><?php echo esc_html( number_format_i18n( $breakdown[ $key ]['count'] ) ); ?></span>
                            <span class="size" data-value="<?php echo esc_attr($breakdown[$key]['size']); ?>" data-is-size="1"><?php echo esc_html(size_format($breakdown[$key]['size'])); ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="muc-footer-info">
                <?php /* translators: %s: last scan date. */ ?>
                <p><span class="dashicons dashicons-clock"></span> <?php printf( esc_html_x( 'Last scan completed: %s', 'Last scan date placeholder', 'oliverodev-media-audit' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_check ) ) ); ?></p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function display_media_files($forced_filter = null) {
        $current_page = filter_input( INPUT_GET, 'media_page', FILTER_VALIDATE_INT );
        $current_page = $current_page ? max( 1, absint( $current_page ) ) : 1;
        $per_page = 20;
        $filter = $forced_filter ? $forced_filter : filter_input( INPUT_GET, 'filter', FILTER_UNSAFE_RAW );
        $filter = is_string( $filter ) ? sanitize_key( $filter ) : 'all';
        $filter = in_array( $filter, array( 'all', 'unused' ), true ) ? $filter : 'all';
        $orderby = filter_input( INPUT_GET, 'orderby', FILTER_UNSAFE_RAW );
        $orderby = is_string( $orderby ) ? sanitize_key( $orderby ) : 'date';
        $orderby = in_array( $orderby, array( 'date', 'size' ), true ) ? $orderby : 'date';
        $order = filter_input( INPUT_GET, 'order', FILTER_UNSAFE_RAW );
        $order = is_string( $order ) ? sanitize_key( $order ) : 'desc';
        $order = strtoupper( $order );
        $order = in_array( $order, array( 'ASC', 'DESC' ), true ) ? $order : 'DESC';
        $mime_type_filter = filter_input( INPUT_GET, 'mime_type', FILTER_UNSAFE_RAW );
        $mime_type_filter = is_string( $mime_type_filter ) ? sanitize_key( $mime_type_filter ) : '';
        $mime_type_filter = in_array( $mime_type_filter, array( '', 'image', 'video', 'audio', 'document', 'archive' ), true ) ? $mime_type_filter : '';

        $args = [
            'post_type' => 'attachment',
            'post_status' => array('inherit', 'publish', 'private'),
            'posts_per_page' => $per_page,
            'paged' => $current_page,
            'no_found_rows' => false,
        ];

        if ($filter === 'unused') {
            $args['meta_query'] = [
                [
                    'key' => '_oliverodev_media_audit_is_unused',
                    'value' => '1',
                    'compare' => '='
                ],
            ];
        }

        if ($mime_type_filter) {
            switch ($mime_type_filter) {
                case 'image': $args['post_mime_type'] = 'image/%'; break;
                case 'video': $args['post_mime_type'] = 'video/%'; break;
                case 'audio': $args['post_mime_type'] = 'audio/%'; break;
                case 'document': 
                    $args['post_mime_type'] = [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'text/plain'
                    ];
                    break;
                case 'archive':
                    $args['post_mime_type'] = [
                        'application/zip',
                        'application/x-rar-compressed',
                        'application/x-tar'
                    ];
                    break;
            }
        }

        if ($orderby === 'size') {
            $args['meta_key'] = '_oliverodev_media_audit_file_size';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = $order;
        } else {
            $args['orderby'] = 'date';
            $args['order'] = $order;
        }
        
        $args = apply_filters( 'oliverodev_media_audit_media_query_args', $args, $filter, $orderby, $order, $mime_type_filter, false );

        $media_query = new WP_Query($args);

        ?>
        <div class="muc-media-files-section">
            <div class="section-header">
                <h2><?php echo esc_html($filter === 'unused' ? __('Unused Files Cleanup', 'oliverodev-media-audit') : __('Media Library Analysis', 'oliverodev-media-audit')); ?></h2>
                <div class="filter-controls">
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'oliverodev-media-audit', 'tab' => 'media-files', 'filter' => 'all'], admin_url('tools.php'))); ?>" class="filter-link <?php echo esc_attr($filter === 'all' ? 'active' : ''); ?>"><?php esc_html_e('All', 'oliverodev-media-audit'); ?></a>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'oliverodev-media-audit', 'tab' => 'unused-files'], admin_url('tools.php'))); ?>" class="filter-link <?php echo esc_attr($filter === 'unused' ? 'active' : ''); ?>"><?php esc_html_e('Unused only', 'oliverodev-media-audit'); ?></a>
                </div>
            </div>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <span class="sorting-label"><?php esc_html_e('Sort by:', 'oliverodev-media-audit'); ?></span>
                    <a href="<?php echo esc_url(add_query_arg('orderby', 'date')); ?>" class="button-link <?php echo esc_attr($orderby === 'date' ? 'active' : ''); ?>"><?php esc_html_e('Date', 'oliverodev-media-audit'); ?></a>
                    <a href="<?php echo esc_url(add_query_arg(['orderby' => 'size', 'order' => 'DESC'])); ?>" class="button-link <?php echo esc_attr($orderby === 'size' ? 'active' : ''); ?>"><?php esc_html_e('Size', 'oliverodev-media-audit'); ?></a>
                </div>
                <?php if ( $filter === 'unused' ) : ?>
                <div class="alignright">
                    <a id="muc-export-csv" href="#" class="button muc-export-btn">
                        <span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'oliverodev-media-audit' ); ?>
                    </a>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($media_query->have_posts()) : ?>
                <table class="wp-list-table widefat fixed striped muc-data-table">
                    <thead>
                        <tr>
                            <th class="col-preview"><?php esc_html_e('Preview', 'oliverodev-media-audit'); ?></th>
                            <th class="col-title"><?php esc_html_e('File Info', 'oliverodev-media-audit'); ?></th>
                            <th class="col-meta"><?php esc_html_e('Details', 'oliverodev-media-audit'); ?></th>
                            <th class="col-status"><?php esc_html_e('Status', 'oliverodev-media-audit'); ?></th>
                            <th class="col-actions"><?php esc_html_e('Actions', 'oliverodev-media-audit'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($media_query->have_posts()) : $media_query->the_post(); 
                            $this->render_media_row(get_the_ID());
                        endwhile; ?>
                    </tbody>
                </table>

                <?php
                $pagination = paginate_links(
                    array(
                        'base'      => add_query_arg( 'media_page', '%#%' ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => max( 1, (int) $media_query->max_num_pages ),
                        'prev_text' => '«',
                        'next_text' => '»',
                    )
                );
                if ( $pagination ) :
                ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php echo wp_kses_post( $pagination ); ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="muc-empty-state">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php if ($filter === 'unused') : ?>
                        <p><?php esc_html_e('No unused files found! Your library is perfectly optimized, or you need to start a new scan.', 'oliverodev-media-audit'); ?></p>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'oliverodev-media-audit', 'tab' => 'dashboard'], admin_url('tools.php'))); ?>" class="button button-secondary"><?php esc_html_e('Go to Dashboard', 'oliverodev-media-audit'); ?></a>
                    <?php else : ?>
                        <p><?php esc_html_e('No media files found in your library.', 'oliverodev-media-audit'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; 
            wp_reset_postdata();
            ?>
        </div>
        <?php
    }

    private function display_settings() {
        if (isset($_POST['oliverodev_media_audit_save_settings']) && current_user_can('manage_options') && check_admin_referer('oliverodev_media_audit_settings_nonce')) {
            $batch_size = isset($_POST['oliverodev_media_audit_batch_size']) ? absint(wp_unslash($_POST['oliverodev_media_audit_batch_size'])) : 20;
            if ( $batch_size < 1 ) {
                $batch_size = 20;
            }
            if ( $batch_size > 200 ) {
                $batch_size = 200;
            }
            update_option( 'oliverodev_media_audit_batch_size', $batch_size );

            $scan_frequency = isset($_POST['oliverodev_media_audit_scan_frequency']) ? sanitize_key(wp_unslash($_POST['oliverodev_media_audit_scan_frequency'])) : 'daily';
            $scan_frequency = in_array( $scan_frequency, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $scan_frequency : 'daily';
            update_option( 'oliverodev_media_audit_scan_frequency', $scan_frequency );

            $file_types = isset($_POST['oliverodev_media_audit_file_types']) && is_array($_POST['oliverodev_media_audit_file_types']) ? array_map( 'sanitize_key', wp_unslash( $_POST['oliverodev_media_audit_file_types'] ) ) : array();
            $file_types = array_values( array_intersect( $file_types, array( 'image', 'document', 'video', 'audio', 'archive' ) ) );
            update_option( 'oliverodev_media_audit_file_types', $file_types );

            oliverodev_media_audit_schedule_cron( $scan_frequency );
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'oliverodev-media-audit') . '</p></div>';
        }

        $batch_size     = absint( get_option('oliverodev_media_audit_batch_size', 20) );
        $scan_frequency = get_option('oliverodev_media_audit_scan_frequency', 'daily');
        $file_types     = get_option('oliverodev_media_audit_file_types', ['image', 'document', 'video', 'audio']);
        ?>
        <div class="muc-settings-section">
            <h2><?php esc_html_e('Global Settings', 'oliverodev-media-audit'); ?></h2>
            <form method="post" class="muc-settings-form">
                <?php wp_nonce_field('oliverodev_media_audit_settings_nonce'); ?>
                
                <div class="settings-card">
                    <h3><span class="dashicons dashicons-performance"></span> <?php esc_html_e('Performance', 'oliverodev-media-audit'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="oliverodev_media_audit_batch_size"><?php esc_html_e('Batch Size', 'oliverodev-media-audit'); ?></label></th>
                            <td>
                                <input type="number" id="oliverodev_media_audit_batch_size" name="oliverodev_media_audit_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="1" max="200">
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-card">
                    <h3><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Automation', 'oliverodev-media-audit'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Scan Frequency', 'oliverodev-media-audit'); ?></th>
                            <td>
                                <select name="oliverodev_media_audit_scan_frequency">
                                    <option value="hourly" <?php selected($scan_frequency, 'hourly'); ?>><?php esc_html_e('Hourly', 'oliverodev-media-audit'); ?></option>
                                    <option value="twicedaily" <?php selected($scan_frequency, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'oliverodev-media-audit'); ?></option>
                                    <option value="daily" <?php selected($scan_frequency, 'daily'); ?>><?php esc_html_e('Daily', 'oliverodev-media-audit'); ?></option>
                                    <option value="weekly" <?php selected($scan_frequency, 'weekly'); ?>><?php esc_html_e('Weekly', 'oliverodev-media-audit'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-card">
                    <h3><span class="dashicons dashicons-media-text"></span> <?php esc_html_e('File Types', 'oliverodev-media-audit'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Included Formats', 'oliverodev-media-audit'); ?></th>
                            <td>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="oliverodev_media_audit_file_types[]" value="image" <?php checked(in_array('image', $file_types, true)); ?>> <?php esc_html_e('Images', 'oliverodev-media-audit'); ?></label>
                                    <label><input type="checkbox" name="oliverodev_media_audit_file_types[]" value="document" <?php checked(in_array('document', $file_types, true)); ?>> <?php esc_html_e('Documents (PDF, Doc, etc)', 'oliverodev-media-audit'); ?></label>
                                    <label><input type="checkbox" name="oliverodev_media_audit_file_types[]" value="video" <?php checked(in_array('video', $file_types, true)); ?>> <?php esc_html_e('Videos', 'oliverodev-media-audit'); ?></label>
                                    <label><input type="checkbox" name="oliverodev_media_audit_file_types[]" value="audio" <?php checked(in_array('audio', $file_types, true)); ?>> <?php esc_html_e('Audio', 'oliverodev-media-audit'); ?></label>
                                    <label><input type="checkbox" name="oliverodev_media_audit_file_types[]" value="archive" <?php checked(in_array('archive', $file_types, true)); ?>> <?php esc_html_e('Archives (ZIP, RAR)', 'oliverodev-media-audit'); ?></label>
                                    
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php do_action( 'oliverodev_media_audit_settings_cards' ); ?>

                <div class="submit-wrapper">
                    <button type="submit" name="oliverodev_media_audit_save_settings" class="button button-primary button-large"><?php esc_html_e('Save Changes', 'oliverodev-media-audit'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }

    private function render_pro_banner() {
        $trial_url   = 'https://checkout.freemius.com/plugin/23055/plan/47886/?trial=free';
        $pricing_url = 'https://checkout.freemius.com/plugin/23055/plan/47886/';
        $unused_count = absint( get_option( 'oliverodev_media_audit_unused_count', 0 ) );
        ?>
        <div class="muc-pro-banner">
            <div class="muc-pro-banner-inner">

                <!-- Left: headline + benefits -->
                <div class="muc-pro-banner-left">
                    <div class="muc-pro-eyebrow">
                        <span class="muc-pro-badge-new">PRO</span>
                        <span class="muc-pro-eyebrow-text"><?php esc_html_e( 'Are you sure those files are safe to delete?', 'oliverodev-media-audit' ); ?></span>
                    </div>

                    <h3 class="muc-pro-headline">
                        <?php esc_html_e( 'Most cleaners guess. PRO knows.', 'oliverodev-media-audit' ); ?>
                    </h3>

                    <p class="muc-pro-sub">
                        <?php
                        if ( $unused_count > 0 ) {
                            printf(
                                /* translators: %d: number of unused files */
                                esc_html__( 'You have %d files marked as unused. Before you delete a single one, make sure none of them are hiding inside Elementor, ACF, Divi, or WooCommerce — where the free scanner cannot look.', 'oliverodev-media-audit' ),
                                $unused_count
                            );
                        } else {
                            esc_html_e( 'Before you delete any file, make sure it is not hiding inside Elementor, ACF, Divi, or WooCommerce — where the free scanner cannot look.', 'oliverodev-media-audit' );
                        }
                        ?>
                    </p>

                    <ul class="muc-pro-benefits">
                        <li>
                            <span class="muc-pro-benefit-icon">🔍</span>
                            <div>
                                <strong><?php esc_html_e( 'Deep Detection for Elementor, ACF, Divi & WooCommerce', 'oliverodev-media-audit' ); ?></strong>
                                <span><?php esc_html_e( 'Catches every hidden reference the free scanner misses — so you never delete a file your site still needs.', 'oliverodev-media-audit' ); ?></span>
                            </div>
                        </li>
                        <li>
                            <span class="muc-pro-benefit-icon">🎯</span>
                            <div>
                                <strong><?php esc_html_e( 'Risk Score 0–100 per file', 'oliverodev-media-audit' ); ?></strong>
                                <span><?php esc_html_e( 'Know exactly how safe it is to delete each file — based on age, references, metadata, and filename patterns.', 'oliverodev-media-audit' ); ?></span>
                            </div>
                        </li>
                        <li>
                            <span class="muc-pro-benefit-icon">🗑️</span>
                            <div>
                                <strong><?php esc_html_e( 'PRO Trash: delete with an undo button', 'oliverodev-media-audit' ); ?></strong>
                                <span><?php esc_html_e( 'Move files to PRO Trash first. Restore them in one click if you change your mind. Permanent delete only when you are sure.', 'oliverodev-media-audit' ); ?></span>
                            </div>
                        </li>
                        <li>
                            <span class="muc-pro-benefit-icon">⚡</span>
                            <div>
                                <strong><?php esc_html_e( 'Bulk cleanup, analytics & automated reports', 'oliverodev-media-audit' ); ?></strong>
                                <span><?php esc_html_e( 'Clean by risk level in one click. See storage charts. Get email summaries for every site you manage.', 'oliverodev-media-audit' ); ?></span>
                            </div>
                        </li>
                    </ul>
                </div>

                <!-- Right: CTA box -->
                <div class="muc-pro-banner-right">
                    <div class="muc-pro-cta-box">
                        <div class="muc-pro-price-block">
                            <span class="muc-pro-price-label"><?php esc_html_e( 'Starting at', 'oliverodev-media-audit' ); ?></span>
                            <div class="muc-pro-price">
                                <span class="muc-pro-price-amount">$29</span>
                                <span class="muc-pro-price-period"><?php esc_html_e( '/year', 'oliverodev-media-audit' ); ?></span>
                            </div>
                            <span class="muc-pro-price-sites"><?php esc_html_e( '1 site · cancel anytime', 'oliverodev-media-audit' ); ?></span>
                        </div>

                        <a href="<?php echo esc_url( $trial_url ); ?>" target="_blank" rel="noopener noreferrer" class="muc-pro-cta-btn">
                            <?php esc_html_e( 'Try PRO Free — 3 Days', 'oliverodev-media-audit' ); ?>
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M12 5l7 7-7 7"/></svg>
                        </a>

                        <p class="muc-pro-no-card">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            <?php esc_html_e( 'No credit card required', 'oliverodev-media-audit' ); ?>
                        </p>

                        <div class="muc-pro-cta-divider"></div>

                        <ul class="muc-pro-trust-list">
                            <li><?php esc_html_e( '✓ 3-day full access trial', 'oliverodev-media-audit' ); ?></li>
                            <li><?php esc_html_e( '✓ 14-day money-back guarantee', 'oliverodev-media-audit' ); ?></li>
                            <li><?php esc_html_e( '✓ Unlimited sites plan available', 'oliverodev-media-audit' ); ?></li>
                        </ul>

                        <a href="<?php echo esc_url( $pricing_url ); ?>" target="_blank" rel="noopener noreferrer" class="muc-pro-see-plans">
                            <?php esc_html_e( 'See all plans & pricing →', 'oliverodev-media-audit' ); ?>
                        </a>
                    </div>
                </div>

            </div>
        </div>
        <?php
    }
}
