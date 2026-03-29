<?php
/**
 * Media Usage Checker Admin UI
 * 
 * @package Media_Usage_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Media_Usage_Checker_Admin {
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
        add_action('wp_ajax_muc_trash_item', [$this, 'trash_item_ajax']);
        add_action('wp_ajax_muc_restore_item', [$this, 'restore_item_ajax']);
        add_action('wp_ajax_muc_delete_item', [$this, 'delete_item_ajax']);
        add_action('wp_ajax_muc_usage_evidence', [$this, 'usage_evidence_ajax']);
        add_action('wp_ajax_muc_load_more_files', [$this, 'load_more_files_ajax']);
        add_action('wp_ajax_muc_trash_preview', [$this, 'trash_preview_ajax']);
        
        // Batch Scanning Handlers
        add_action('wp_ajax_muc_start_scan', [$this, 'start_scan_ajax']);
        add_action('wp_ajax_muc_process_batch', [$this, 'process_batch_ajax']);
        add_action('wp_ajax_muc_finish_scan', [$this, 'finish_scan_ajax']);
    }

    private function get_attachment_id_from_request( $key = 'media_id' ) {
        if ( wp_doing_ajax() ) {
            check_ajax_referer( 'muc_ajax_nonce', 'nonce' );
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

    private function is_pro() {
        return true;
    }

    private function pro_badge() {
        return '';
    }

    public function handle_actions() {
        if ( ! isset( $_POST['muc_action'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = sanitize_key( wp_unslash( $_POST['muc_action'] ) );
        $allowed_actions = array(
            'trash',
            'restore',
            'trash_all',
            'delete_perm',
            'empty_trash',
            'bulk_trash_apply',
            'bulk_trash',
            'delete_single',
        );
        if ( ! in_array( $action, $allowed_actions, true ) ) {
            return;
        }

        $media_id = isset($_POST['media_id']) ? absint(wp_unslash($_POST['media_id'])) : 0;
        $scanner = Media_Usage_Checker_Scanner::get_instance();

        switch($action) {
            case 'trash':
                if (!$this->is_pro()) {
                    break;
                }
                check_admin_referer('muc_trash_nonce');
                if ( ! $this->user_can_manage_attachment( $media_id ) ) {
                    break;
                }
                if ( media_usage_checker_is_media_in_use( $media_id ) ) {
                    break;
                }
                $scanner->move_to_trash($media_id);
                wp_safe_redirect(remove_query_arg(['muc_action', 'media_id', '_wpnonce', 'muc_trash_nonce']));
                exit;
                break;
            case 'restore':
                if (!$this->is_pro()) {
                    break;
                }
                check_admin_referer('muc_restore_nonce');
                if ( ! $this->user_can_manage_attachment( $media_id ) ) {
                    break;
                }
                $scanner->restore_from_trash($media_id);
                wp_safe_redirect(remove_query_arg(['muc_action', 'media_id', '_wpnonce', 'muc_restore_nonce']));
                exit;
                break;
            /*
            case 'save_license':
                 // Logic removed. Ready for Freemius SDK integration.
                 break;
            */
            case 'trash_all':
                if (!$this->is_pro()) {
                    break;
                }
                check_admin_referer('muc_trash_all_nonce');
                $attachments = get_posts(
                    array(
                        'post_type'      => 'attachment',
                        'post_status'    => 'inherit',
                        'fields'         => 'ids',
                        'posts_per_page' => -1,
                        'no_found_rows'  => true,
                    )
                );
                foreach ( $attachments as $id ) {
                    $id = absint( $id );
                    if ( 0 === $id || ! $this->user_can_manage_attachment( $id ) ) {
                        continue;
                    }
                    if ( ! media_usage_checker_is_media_in_use( $id ) ) {
                        $scanner->move_to_trash( $id );
                    }
                }
                $scanner->update_stats();
                wp_safe_redirect(remove_query_arg(['muc_action', 'media_id', '_wpnonce', 'muc_force_check_nonce', 'muc_trash_nonce', 'muc_restore_nonce', 'muc_trash_all_nonce', 'muc_delete_perm_nonce', 'muc_empty_trash_nonce', 'muc_bulk_trash_nonce', 'bulk_trash']));
                exit;
                break;
            case 'delete_perm':
                check_admin_referer('muc_delete_perm_nonce');
                if ( ! $this->user_can_manage_attachment( $media_id ) ) {
                    break;
                }
                $scanner->delete_permanently($media_id);
                wp_safe_redirect(remove_query_arg(['muc_action', 'media_id', '_wpnonce', 'muc_delete_perm_nonce']));
                exit;
                break;
            case 'empty_trash':
                if (!$this->is_pro()) {
                    break;
                }
                check_admin_referer('muc_empty_trash_nonce');
                $trashed_ids = get_posts(
                    array(
                        'post_type'      => 'attachment',
                        'post_status'    => 'any',
                        'fields'         => 'ids',
                        'posts_per_page' => -1,
                        'meta_key'       => '_muc_trashed_at',
                        'orderby'        => 'meta_value',
                        'order'          => 'DESC',
                        'no_found_rows'  => true,
                    )
                );
                foreach ( $trashed_ids as $id ) {
                    $id = absint( $id );
                    if ( 0 === $id || ! $this->user_can_manage_attachment( $id ) ) {
                        continue;
                    }
                    $scanner->delete_permanently( $id );
                }
                $scanner->update_stats();
                wp_safe_redirect(remove_query_arg(['muc_action', 'media_id', '_wpnonce', 'muc_force_check_nonce', 'muc_trash_nonce', 'muc_restore_nonce', 'muc_trash_all_nonce', 'muc_delete_perm_nonce', 'muc_empty_trash_nonce', 'muc_bulk_trash_nonce']));
                exit;
                break;
            case 'bulk_trash_apply':
                if (!$this->is_pro()) {
                    break;
                }
                check_admin_referer('muc_bulk_trash_nonce');
                if (!isset($_POST['selected_media']) || !is_array($_POST['selected_media'])) break;
                
                $bulk_action = isset( $_POST['bulk_action_v2'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action_v2'] ) ) : '';
                if ( ! in_array( $bulk_action, array( 'restore', 'delete' ), true ) ) {
                    break;
                }
                $ids = array_map('absint', wp_unslash($_POST['selected_media']));
                
                foreach ($ids as $id) {
                    if ( ! $this->user_can_manage_attachment( $id ) ) {
                        continue;
                    }
                    if ($bulk_action === 'restore') {
                        $scanner->restore_from_trash($id);
                    } elseif ($bulk_action === 'delete') {
                        $scanner->delete_permanently($id);
                    }
                }
                $scanner->update_stats();
                wp_safe_redirect(remove_query_arg(['muc_action', 'media_id', '_wpnonce', 'muc_force_check_nonce', 'muc_trash_nonce', 'muc_restore_nonce', 'muc_trash_all_nonce', 'muc_delete_perm_nonce', 'muc_empty_trash_nonce', 'muc_bulk_trash_nonce']));
                exit;
                break;
            case 'bulk_trash':
                if (!$this->is_pro()) {
                    break;
                }
                check_admin_referer('muc_bulk_trash_nonce');
                if (!isset($_POST['selected_media']) || !is_array($_POST['selected_media'])) break;
                
                $ids = array_map('absint', wp_unslash($_POST['selected_media']));
                foreach ($ids as $id) {
                    if ( ! $this->user_can_manage_attachment( $id ) ) {
                        continue;
                    }
                    if ( media_usage_checker_is_media_in_use( $id ) ) {
                        continue;
                    }
                    $scanner->move_to_trash($id);
                }
                $scanner->update_stats();
                wp_safe_redirect(remove_query_arg(['muc_action', 'media_id', '_wpnonce', 'muc_force_check_nonce', 'muc_trash_nonce', 'muc_restore_nonce', 'muc_trash_all_nonce', 'muc_delete_perm_nonce', 'muc_empty_trash_nonce', 'muc_bulk_trash_nonce']));
                exit;
                break;
            case 'delete_single':
                check_admin_referer('muc_delete_single_nonce');
                if ( ! $this->user_can_manage_attachment( $media_id ) ) {
                    break;
                }
                $scanner->delete_permanently($media_id);
                $scanner->update_stats();
                wp_safe_redirect(remove_query_arg(['muc_action', 'media_id', '_wpnonce', 'muc_delete_single_nonce']));
                exit;
                break;
        }
    }

    public function add_admin_menu() {
        add_management_page(
            esc_html__( 'Olivero Media Audit', 'media-usage-checker' ),
            esc_html__( 'Olivero Media Audit', 'media-usage-checker' ),
            'manage_options',
            'media-usage-checker',
            array( $this, 'render_admin_page' )
        );
    }

    public function enqueue_assets($hook) {
        if ('tools_page_media-usage-checker' !== $hook) {
            return;
        }
        
        add_thickbox();

        wp_enqueue_style( 'muc-admin-style', MEDIA_USAGE_CHECKER_PLUGIN_URL . 'assets/css/admin.css', [], MEDIA_USAGE_CHECKER_VERSION );
        wp_enqueue_script( 'muc-admin-script', MEDIA_USAGE_CHECKER_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], MEDIA_USAGE_CHECKER_VERSION, true );
        
        wp_localize_script(
            'muc-admin-script',
            'mucAdmin',
            array(
                'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'muc_ajax_nonce' ),
                'strings'  => array(
                    'scanning'       => __( 'Scanning...', 'media-usage-checker' ),
                    'complete'       => __( 'Scan Complete!', 'media-usage-checker' ),
                    'confirmDelete'  => __( 'Are you sure you want to delete this file?', 'media-usage-checker' ),
                    'processing'     => __( 'Processing...', 'media-usage-checker' ),
                    /* translators: %s: progress percentage. */
                    'scanning_progress' => _x( 'Scanning... %s%%', 'Progress percentage placeholder', 'media-usage-checker' ),
                    'calculating'    => __( 'Calculating final stats...', 'media-usage-checker' ),
                    'initializing'   => __( 'Initializing...', 'media-usage-checker' ),
                    'start_new_scan' => __( 'Start New Scan', 'media-usage-checker' ),
                    /* translators: %s: number of files. */
                    'checking_files' => _x( 'Checking %s files', 'Number of files placeholder', 'media-usage-checker' ),
                    /* translators: %s: number of files. */
                    'potential_savings' => _x( 'Potential savings: %s files', 'Number of files placeholder', 'media-usage-checker' ),
                    'all_files_loaded'  => __( 'All files loaded.', 'media-usage-checker' ),
                    'no_more_files'     => __( 'No more files to load.', 'media-usage-checker' ),
                    'failed_start_scan' => __( 'Failed to start scan.', 'media-usage-checker' ),
                    /* translators: %s: batch number. */
                    'error_scanning_batch' => _x( 'Error scanning batch %s', 'Batch number placeholder', 'media-usage-checker' ),
                    'server_timeout'   => __( 'Server timeout. Try again.', 'media-usage-checker' ),
                    'action_failed'    => __( 'Action failed.', 'media-usage-checker' ),
                    'unauthorized'     => __( 'Unauthorized', 'media-usage-checker' ),
                    'pro_required'     => __( 'This feature requires PRO.', 'media-usage-checker' ),
                    'evidence_none'    => __( 'No evidence was found.', 'media-usage-checker' ),
                    'evidence_title'   => __( 'Why is this media used?', 'media-usage-checker' ),
                ),
            )
        );
    }

    /**
     * AJAX: Trash Item
     */
    public function trash_item_ajax() {
        check_ajax_referer('muc_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'media-usage-checker'));
        if (!$this->is_pro()) wp_send_json_error(__('This feature requires PRO.', 'media-usage-checker'));

        $media_id = $this->get_attachment_id_from_request();
        if ( 0 === $media_id || ! $this->user_can_manage_attachment( $media_id ) ) {
            wp_send_json_error(__('Unauthorized', 'media-usage-checker'));
        }
        if ( media_usage_checker_is_media_in_use( $media_id ) ) {
            wp_send_json_error(__('Action failed.', 'media-usage-checker'));
        }
        if (Media_Usage_Checker_Scanner::get_instance()->move_to_trash($media_id)) {
            wp_send_json_success();
        }
        wp_send_json_error(__('Failed to trash item', 'media-usage-checker'));
    }

    /**
     * AJAX: Restore Item
     */
    public function restore_item_ajax() {
        check_ajax_referer('muc_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'media-usage-checker'));
        if (!$this->is_pro()) wp_send_json_error(__('This feature requires PRO.', 'media-usage-checker'));

        $media_id = $this->get_attachment_id_from_request();
        if ( 0 === $media_id || ! $this->user_can_manage_attachment( $media_id ) ) {
            wp_send_json_error(__('Unauthorized', 'media-usage-checker'));
        }
        if (Media_Usage_Checker_Scanner::get_instance()->restore_from_trash($media_id)) {
            wp_send_json_success();
        }
        wp_send_json_error(__('Failed to restore item', 'media-usage-checker'));
    }

    /**
     * AJAX: Delete Item Permanently
     */
    public function delete_item_ajax() {
        check_ajax_referer('muc_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'media-usage-checker'));

        $media_id = $this->get_attachment_id_from_request();
        if ( 0 === $media_id || ! $this->user_can_manage_attachment( $media_id ) ) {
            wp_send_json_error(__('Unauthorized', 'media-usage-checker'));
        }
        if (Media_Usage_Checker_Scanner::get_instance()->delete_permanently($media_id)) {
            wp_send_json_success();
        }
        wp_send_json_error(__('Failed to delete item', 'media-usage-checker'));
    }

    /**
     * AJAX: Load More Files
     */
    public function load_more_files_ajax() {
        check_ajax_referer('muc_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'media-usage-checker'));
        
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
            'post_status' => 'inherit',
            'posts_per_page' => 20,
            'paged' => $page,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_muc_trashed_at',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];

        if ($filter === 'unused') {
            $args['meta_query'][] = [
                'key' => '_muc_is_unused',
                'value' => '1',
                'compare' => '='
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
            $args['meta_key'] = '_muc_file_size';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = $order;
        } else {
            $args['orderby'] = 'date';
            $args['order'] = $order;
        }

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
            wp_send_json_error(__('No more files to load.', 'media-usage-checker'));
        }
    }

    public function trash_preview_ajax() {
        $nonce = filter_input( INPUT_GET, 'nonce', FILTER_UNSAFE_RAW );
        $nonce = is_string( $nonce ) ? sanitize_text_field( $nonce ) : '';
        if ( ! wp_verify_nonce( $nonce, 'muc_ajax_nonce' ) ) {
            status_header( 403 );
            exit;
        }

        $media_id = filter_input( INPUT_GET, 'media_id', FILTER_VALIDATE_INT );
        $media_id = $media_id ? absint( $media_id ) : 0;
        if ( 0 === $media_id || ! $this->user_can_manage_attachment( $media_id ) || ! $this->is_pro() ) {
            status_header( 403 );
            exit;
        }

        $trash_path = get_post_meta( $media_id, '_muc_trash_path', true );
        if ( empty( $trash_path ) || ! is_string( $trash_path ) ) {
            status_header( 404 );
            exit;
        }

        $trash_path = Media_Usage_Checker_Validator::get_instance()->validate_file_path( $trash_path );
        if ( ! $trash_path ) {
            status_header( 404 );
            exit;
        }

        $ft = wp_check_filetype( $trash_path );
        $mime = ! empty( $ft['type'] ) ? $ft['type'] : 'application/octet-stream';
        $length = function_exists( 'media_usage_checker_filesize' ) ? media_usage_checker_filesize( $trash_path ) : 0;
        $fs = function_exists( 'media_usage_checker_get_filesystem' ) ? media_usage_checker_get_filesystem() : null;
        if ( ! $fs || ! method_exists( $fs, 'get_contents' ) ) {
            status_header( 500 );
            exit;
        }
        $contents = (string) $fs->get_contents( $trash_path );

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        if ( $length > 0 ) {
            header( 'Content-Length: ' . (string) $length );
        }
        echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        exit;
    }

    /**
     * AJAX: Start Batch Scan
     */
    public function start_scan_ajax() {
        check_ajax_referer('muc_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'media-usage-checker'));

        $total = Media_Usage_Checker_Scanner::get_instance()->get_total_attachments();
        $batch_size = $this->is_pro() ? absint(get_option('muc_batch_size', 100)) : 20;
        if ($batch_size < 1) {
            $batch_size = $this->is_pro() ? 100 : 20;
        }
        wp_send_json_success(['total' => $total, 'batch_size' => $batch_size]);
    }

    /**
     * AJAX: Process Batch
     */
    public function process_batch_ajax() {
        check_ajax_referer('muc_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'media-usage-checker'));

        $page = isset($_POST['page']) ? absint(wp_unslash($_POST['page'])) : 1;
        $batch_size = $this->is_pro() ? absint(get_option('muc_batch_size', 100)) : 20;
        if ($batch_size < 1) {
            $batch_size = $this->is_pro() ? 100 : 20;
        }
        
        $processed = Media_Usage_Checker_Scanner::get_instance()->scan_batch($page, $batch_size);
        wp_send_json_success(['processed' => $processed]);
    }

    /**
     * AJAX: Finish Scan (Calculate Stats)
     */
    public function finish_scan_ajax() {
        check_ajax_referer('muc_ajax_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error(__('Unauthorized', 'media-usage-checker'));

        $stats = Media_Usage_Checker_Scanner::get_instance()->calculate_stats_from_meta();
        wp_send_json_success($stats);
    }

    public function usage_evidence_ajax() {
        check_ajax_referer( 'muc_ajax_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized', 'media-usage-checker' ) );
        }
        if ( ! $this->is_pro() ) {
            wp_send_json_error( __( 'This feature requires PRO.', 'media-usage-checker' ) );
        }

        $media_id = $this->get_attachment_id_from_request( 'media_id' );
        if ( 0 === $media_id ) {
            wp_send_json_error( __( 'Invalid media item.', 'media-usage-checker' ) );
        }

        $evidence = Media_Usage_Checker_Scanner::get_instance()->get_usage_evidence( $media_id, 5 );
        wp_send_json_success(
            array(
                'media_id'  => $media_id,
                'evidence'  => $evidence,
            )
        );
    }

    /**
     * Render a single media row
     */
    private function render_media_row($media_id) {
        $file_path = get_attached_file($media_id);
        $raw_size = $file_path && function_exists( 'media_usage_checker_filesize' ) ? media_usage_checker_filesize( $file_path ) : 0;
        $file_size = $raw_size > 0 ? size_format( $raw_size, 2 ) : 'N/A';
        $mime_type = get_post_mime_type($media_id);
        $is_used = media_usage_checker_is_media_in_use( $media_id );
        
        $cat = 'document';
        if (strpos($mime_type, 'image/') !== false) $cat = 'image';
        elseif (strpos($mime_type, 'video/') !== false) $cat = 'video';
        elseif (strpos($mime_type, 'audio/') !== false) $cat = 'audio';
        elseif (in_array($mime_type, ['application/zip', 'application/x-rar-compressed', 'application/x-tar'])) $cat = 'archive';
        ?>
        <tr class="<?php echo esc_attr($is_used ? 'row-used' : 'row-unused'); ?>" data-id="<?php echo esc_attr($media_id); ?>" data-size="<?php echo esc_attr($raw_size); ?>" data-type="<?php echo esc_attr($cat); ?>">
            <td><input type="checkbox" name="selected_media[]" value="<?php echo esc_attr($media_id); ?>"></td>
            <td class="col-preview">
                <div class="media-preview">
                    <?php if (wp_attachment_is_image($media_id)) : ?>
                        <?php echo wp_get_attachment_image($media_id, [60, 60]); ?>
                    <?php else : ?>
                        <span class="dashicons dashicons-media-default"></span>
                    <?php endif; ?>
                </div>
            </td>
            <td class="col-title">
                <strong><?php echo esc_html(get_the_title($media_id)); ?></strong>
                <div class="row-actions">
                    <a href="<?php echo esc_url(wp_get_attachment_url($media_id)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View Original', 'media-usage-checker'); ?></a>
                </div>
            </td>
            <td class="col-meta">
                <span class="meta-type"><?php echo esc_html(strtoupper(str_replace('image/', '', $mime_type))); ?></span>
                <span class="meta-size"><?php echo esc_html($file_size); ?></span>
            </td>
            <td class="col-status">
                <span class="muc-status-pill <?php echo esc_attr($is_used ? 'status-used' : 'status-unused'); ?>">
                    <?php echo esc_html($is_used ? __('Used', 'media-usage-checker') : __('Unused', 'media-usage-checker')); ?>
                </span>
            </td>
            <td class="col-actions">
                <?php if (!$is_used) : ?>
                    <?php if ($this->is_pro()) : ?>
                        <button type="button" class="button muc-item-action" data-action="trash" data-id="<?php echo esc_attr($media_id); ?>">
                            <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Move to Trash', 'media-usage-checker'); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="button muc-item-action" data-action="delete" data-id="<?php echo esc_attr($media_id); ?>">
                            <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Delete Permanently', 'media-usage-checker'); ?>
                        </button>
                    <?php endif; ?>
                <?php else : ?>
                    <button disabled class="button disabled"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Used', 'media-usage-checker'); ?></button>
                    <?php if ( $this->is_pro() ) : ?>
                        <button type="button" class="button muc-usage-evidence" data-id="<?php echo esc_attr($media_id); ?>">
                            <span class="dashicons dashicons-info-outline"></span> <?php esc_html_e('Why used?', 'media-usage-checker'); ?>
                        </button>
                    <?php else : ?>
                        <button type="button" class="button" disabled>
                            <span class="dashicons dashicons-info-outline"></span> <?php esc_html_e('Why used?', 'media-usage-checker'); ?> <?php echo wp_kses_post( $this->pro_badge() ); ?>
                        </button>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'media-usage-checker' ) );
        }
        
        $current_tab = filter_input( INPUT_GET, 'tab', FILTER_UNSAFE_RAW );
        $current_tab = is_string( $current_tab ) ? sanitize_key( $current_tab ) : 'dashboard';
        if ( ! in_array( $current_tab, array( 'dashboard', 'unused-files', 'media-files', 'trash', 'activity', 'settings' ), true ) ) {
            $current_tab = 'dashboard';
        }
        ?>
        <div class="wrap muc-wrap">
            <div class="muc-header">
                <div class="muc-header-content">
                    <h1><?php echo esc_html__('Olivero Media Audit', 'media-usage-checker'); ?></h1>
                    <p class="muc-version">v<?php echo esc_html( MEDIA_USAGE_CHECKER_VERSION ); ?></p>
                </div>
            </div>

            <nav class="muc-nav-tab-wrapper">
                <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'dashboard'], admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($current_tab === 'dashboard' ? 'nav-tab-active' : ''); ?>">
                    <span class="dashicons dashicons-dashboard"></span> <?php esc_html_e('Dashboard', 'media-usage-checker'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'unused-files'], admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($current_tab === 'unused-files' ? 'nav-tab-active' : ''); ?>">
                    <span class="dashicons dashicons-warning"></span> <?php esc_html_e('Unused Files', 'media-usage-checker'); ?> <span class="count-badge"><?php echo esc_html( number_format_i18n( absint( get_option( 'muc_unused_count', 0 ) ) ) ); ?></span>
                </a>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'media-files'], admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($current_tab === 'media-files' ? 'nav-tab-active' : ''); ?>">
                    <span class="dashicons dashicons-admin-media"></span> <?php esc_html_e('Library', 'media-usage-checker'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'trash'], admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($current_tab === 'trash' ? 'nav-tab-active' : ''); ?>">
                    <span class="dashicons dashicons-trash"></span> <?php esc_html_e('Trash', 'media-usage-checker'); ?> <span class="count-badge"><?php echo esc_html( number_format_i18n( absint( get_option( 'muc_trashed_count', 0 ) ) ) ); ?></span>
                </a>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'activity'], admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($current_tab === 'activity' ? 'nav-tab-active' : ''); ?>">
                    <span class="dashicons dashicons-list-view"></span> <?php esc_html_e('Activity', 'media-usage-checker'); ?>
                </a>
                <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'settings'], admin_url('admin.php'))); ?>" class="nav-tab <?php echo esc_attr($current_tab === 'settings' ? 'nav-tab-active' : ''); ?>">
                    <span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e('Settings', 'media-usage-checker'); ?>
                </a>
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
                    case 'trash':
                        $this->display_trash();
                        break;
                    case 'activity':
                        $this->display_activity();
                        break;
                    case 'settings':
                        $this->display_settings();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function display_dashboard() {
        $total_media = wp_count_posts('attachment')->inherit;
        $used_count = get_option('muc_used_count', 0);
        $unused_count = get_option('muc_unused_count', 0);
        $used_size = get_option('muc_used_size', 0);
        $unused_size = get_option('muc_unused_size', 0);
        $last_check = get_option('muc_last_check');
        
        // Calculate percentages
        $used_percent = $total_media > 0 ? round(($used_count / $total_media) * 100) : 0;
        $unused_percent = $total_media > 0 ? round(($unused_count / $total_media) * 100) : 0;

        ?>
        <div class="muc-dashboard">
            <div class="muc-welcome-banner">
                <div class="banner-text">
                    <h2><?php esc_html_e('Ready to clean up your site?', 'media-usage-checker'); ?></h2>
                    <p><?php esc_html_e('Identify and remove unused media files to free up disk space and improve performance.', 'media-usage-checker'); ?></p>
                </div>
                <div class="banner-actions">
                    <form method="post" class="muc-scan-form">
                        <?php wp_nonce_field('muc_force_check', 'muc_force_check_nonce'); ?>
                        <button type="submit" name="muc_force_check" class="button button-primary button-hero">
                            <span class="dashicons dashicons-search"></span> <?php esc_html_e('Start New Scan', 'media-usage-checker'); ?>
                        </button>
                    </form>
                </div>
            </div>

            <div class="muc-stats-grid">
                <div class="muc-stat-card total highlight">
                    <div class="stat-icon"><span class="dashicons dashicons-admin-media"></span></div>
                    <div class="stat-info">
                        <h3><?php esc_html_e('Media Library Size', 'media-usage-checker'); ?></h3>
                        <p class="muc-stat-number" data-value="<?php echo esc_attr( (float) $used_size + (float) $unused_size ); ?>" data-is-size="1"><?php echo esc_html( size_format( (float) $used_size + (float) $unused_size ) ); ?></p>
                        <?php /* translators: %s: number of files. */ ?>
                        <span class="sub-stat"><?php printf( esc_html_x( 'Checking %s files', 'Number of files placeholder', 'media-usage-checker' ), esc_html( number_format_i18n( $total_media ) ) ); ?></span>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'media-files', 'filter' => 'all'], admin_url('admin.php'))); ?>" class="view-link"><?php esc_html_e('View Library', 'media-usage-checker'); ?> &rarr;</a>
                    </div>
                </div>
                <div class="muc-stat-card used">
                    <div class="stat-icon"><span class="dashicons dashicons-yes-alt"></span></div>
                    <div class="stat-info">
                        <h3><?php esc_html_e('Files in Use', 'media-usage-checker'); ?></h3>
                        <p class="muc-stat-number" data-value="<?php echo esc_attr( $used_count ); ?>"><?php echo esc_html( number_format_i18n( $used_count ) ); ?></p>
                        <div class="progress-bar"><div class="progress" style="width: <?php echo esc_attr($used_percent); ?>%"></div></div>
                        <span class="percent"><?php echo esc_html($used_percent); ?>%</span>
                        <span class="sub-stat"><?php echo esc_html( size_format( (float) $used_size ) ); ?></span>
                    </div>
                </div>
                <div class="muc-stat-card unused warning">
                    <div class="stat-icon"><span class="dashicons dashicons-warning"></span></div>
                    <div class="stat-info">
                        <h3><?php esc_html_e('Space to Recover', 'media-usage-checker'); ?></h3>
                        <p class="muc-stat-number" data-value="<?php echo esc_attr($unused_size); ?>" data-is-size="1"><?php echo esc_html(size_format((float)$unused_size)); ?></p>
                        <div class="progress-bar"><div class="progress" style="width: <?php echo esc_attr($unused_percent); ?>%"></div></div>
                        <span class="percent"><?php echo esc_html($unused_percent); ?>%</span>
                        <?php /* translators: %s: number of files. */ ?>
                        <span class="sub-stat"><?php printf( esc_html_x( 'Potential savings: %s files', 'Number of files placeholder', 'media-usage-checker' ), esc_html( number_format_i18n( $unused_count ) ) ); ?></span>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'unused-files'], admin_url('admin.php'))); ?>" class="view-link"><?php esc_html_e('Clean Now', 'media-usage-checker'); ?> &rarr;</a>
                    </div>
                </div>
            </div>

            <?php if ($last_check): ?>
            <div class="muc-breakdown-section">
                <h3><?php esc_html_e('Library Breakdown', 'media-usage-checker'); ?></h3>
                <div class="muc-breakdown-list">
                    <?php 
                    $breakdown = get_option('muc_breakdown', []);
                    $types = [
                        'image' => ['label' => __('Images', 'media-usage-checker'), 'icon' => 'format-image'],
                        'document' => ['label' => __('Documents', 'media-usage-checker'), 'icon' => 'media-document'],
                        'video' => ['label' => __('Videos', 'media-usage-checker'), 'icon' => 'format-video'],
                        'audio' => ['label' => __('Audio', 'media-usage-checker'), 'icon' => 'format-audio'],
                        'archive' => ['label' => __('Archives', 'media-usage-checker'), 'icon' => 'media-archive'],
                    ];
                    foreach ($types as $key => $data) : 
                        if (empty($breakdown[$key]['count'])) continue;
                    ?>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'media-files', 'filter' => 'all', 'mime_type' => $key], admin_url('admin.php'))); ?>" class="breakdown-item" data-type="<?php echo esc_attr($key); ?>">
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
                <p><span class="dashicons dashicons-clock"></span> <?php printf( esc_html_x( 'Last scan completed: %s', 'Last scan date placeholder', 'media-usage-checker' ), esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_check ) ) ); ?></p>
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
            'post_status' => 'inherit',
            'posts_per_page' => $per_page,
            'meta_query' => [
                'relation' => 'AND',
                [
                    'key' => '_muc_trashed_at',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];

        if ($filter === 'unused') {
            $args['meta_query'][] = [
                'key' => '_muc_is_unused',
                'value' => '1',
                'compare' => '='
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
            $args['meta_key'] = '_muc_file_size';
            $args['orderby'] = 'meta_value_num';
            $args['order'] = $order;
        } else {
            $args['orderby'] = 'date';
            $args['order'] = $order;
        }
        
        $media_query = new WP_Query($args);

        ?>
        <div class="muc-media-files-section">
            <div class="section-header">
                <h2><?php echo esc_html($filter === 'unused' ? __('Unused Files Cleanup', 'media-usage-checker') : __('Media Library Analysis', 'media-usage-checker')); ?></h2>
                <div class="action-buttons">
                    <?php if ($filter === 'unused') : ?>
                        <?php if ($this->is_pro()) : ?>
                            <form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_attr(__('Trash ALL unused files? This is a safe operation as you can restore them from Trash.', 'media-usage-checker')); ?>');">
                                <?php wp_nonce_field('muc_trash_all_nonce'); ?>
                                <input type="hidden" name="muc_action" value="trash_all">
                                <button type="submit" class="button button-primary"><span class="dashicons dashicons-trash"></span> <?php esc_html_e('Trash All Unused', 'media-usage-checker'); ?> <?php echo wp_kses_post( $this->pro_badge() ); ?></button>
                            </form>
                        <?php else : ?>
                            <button type="button" class="button button-primary" disabled><span class="dashicons dashicons-lock"></span> <?php esc_html_e('Trash All Unused', 'media-usage-checker'); ?> <?php echo wp_kses_post( $this->pro_badge() ); ?></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <div class="filter-controls">
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'media-files', 'filter' => 'all'], admin_url('admin.php'))); ?>" class="filter-link <?php echo esc_attr($filter === 'all' ? 'active' : ''); ?>"><?php esc_html_e('All', 'media-usage-checker'); ?></a>
                    <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'unused-files'], admin_url('admin.php'))); ?>" class="filter-link <?php echo esc_attr($filter === 'unused' ? 'active' : ''); ?>"><?php esc_html_e('Unused only', 'media-usage-checker'); ?></a>
                </div>
            </div>

            <div class="tablenav top">
                <div class="alignleft actions">
                    <span class="sorting-label"><?php esc_html_e('Sort by:', 'media-usage-checker'); ?></span>
                    <a href="<?php echo esc_url(add_query_arg('orderby', 'date')); ?>" class="button-link <?php echo esc_attr($orderby === 'date' ? 'active' : ''); ?>"><?php esc_html_e('Date', 'media-usage-checker'); ?></a>
                    <a href="<?php echo esc_url(add_query_arg(['orderby' => 'size', 'order' => 'DESC'])); ?>" class="button-link <?php echo esc_attr($orderby === 'size' ? 'active' : ''); ?>"><?php esc_html_e('Size', 'media-usage-checker'); ?></a>
                </div>
            </div>
            
            <?php if ($media_query->have_posts()) : ?>
                <form method="post" class="muc-media-form">
                    <?php wp_nonce_field('muc_bulk_trash_nonce'); ?>
                    <input type="hidden" name="muc_action" value="bulk_trash">
                    <table class="wp-list-table widefat fixed striped muc-data-table">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="select-all"></th>
                                <th class="col-preview"><?php esc_html_e('Preview', 'media-usage-checker'); ?></th>
                                <th class="col-title"><?php esc_html_e('File Info', 'media-usage-checker'); ?></th>
                                <th class="col-meta"><?php esc_html_e('Details', 'media-usage-checker'); ?></th>
                                <th class="col-status"><?php esc_html_e('Status', 'media-usage-checker'); ?></th>
                                <th class="col-actions"><?php esc_html_e('Actions', 'media-usage-checker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($media_query->have_posts()) : $media_query->the_post(); 
                                $this->render_media_row(get_the_ID());
                            endwhile; ?>
                        </tbody>
                    </table>

                    <div class="tablenav bottom muc-infinite-nav">
                        <div class="alignleft actions bulkactions">
                            <?php if ($this->is_pro()) : ?>
                                <button type="submit" name="bulk_trash" class="button button-secondary">
                                    <?php esc_html_e('Trash Selected', 'media-usage-checker'); ?>
                                </button>
                            <?php else : ?>
                                <button type="button" class="button button-secondary" disabled>
                                    <?php esc_html_e('Trash Selected', 'media-usage-checker'); ?> <?php echo wp_kses_post( $this->pro_badge() ); ?>
                                </button>
                            <?php endif; ?>
                        </div>
                        
                        <div class="muc-load-more-wrapper">
                            <?php if ($media_query->max_num_pages > 1) : ?>
                                <button type="button" id="muc-load-more" class="button button-primary" 
                                    data-page="1" 
                                    data-total="<?php echo esc_attr($media_query->max_num_pages); ?>"
                                    data-filter="<?php echo esc_attr($filter); ?>"
                                    data-orderby="<?php echo esc_attr($orderby); ?>"
                                    data-order="<?php echo esc_attr($order); ?>"
                                    data-mime="<?php echo esc_attr($mime_type_filter); ?>">
                                    <?php esc_html_e('Load More Files', 'media-usage-checker'); ?>
                                </button>
                                <p class="muc-loading-hint" style="display:none;"><?php esc_html_e('Loading more files...', 'media-usage-checker'); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            <?php else : ?>
                <div class="muc-empty-state">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <?php if ($filter === 'unused') : ?>
                        <p><?php esc_html_e('No unused files found! Your library is perfectly optimized, or you need to start a new scan.', 'media-usage-checker'); ?></p>
                        <a href="<?php echo esc_url(add_query_arg(['page' => 'media-usage-checker', 'tab' => 'dashboard'], admin_url('admin.php'))); ?>" class="button button-secondary"><?php esc_html_e('Go to Dashboard', 'media-usage-checker'); ?></a>
                    <?php else : ?>
                        <p><?php esc_html_e('No media files found in your library.', 'media-usage-checker'); ?></p>
                    <?php endif; ?>
                </div>
            <?php endif; 
            wp_reset_postdata();
            ?>
        </div>
        <?php
    }

    private function display_trash() {
        $current_page = filter_input( INPUT_GET, 'media_page', FILTER_VALIDATE_INT );
        $current_page = $current_page ? max( 1, absint( $current_page ) ) : 1;
        $per_page = 20;

        $trash_query = Media_Usage_Checker_Scanner::get_instance()->get_trashed_items($per_page, $current_page);

        ?>
        <div class="muc-trash-section">
            <div class="section-header">
                <h2><?php esc_html_e('Trash', 'media-usage-checker'); ?></h2>
                <form method="post" onsubmit="return confirm('<?php echo esc_attr(__('Empty all items permanently?', 'media-usage-checker')); ?>');">
                    <?php wp_nonce_field('muc_empty_trash_nonce'); ?>
                    <input type="hidden" name="muc_action" value="empty_trash">
                    <button type="submit" class="button button-secondary"><?php esc_html_e('Empty Trash', 'media-usage-checker'); ?></button>
                </form>
            </div>
            
            <?php if (!empty($trash_query) && $trash_query->have_posts()) : ?>
                <form method="post" class="muc-trash-form">
                    <?php wp_nonce_field('muc_bulk_trash_nonce'); ?>
                    <input type="hidden" name="muc_action" value="bulk_trash_apply">
                    
                    <div class="tablenav top">
                        <div class="alignleft actions bulkactions">
                            <select name="bulk_action_v2">
                                <option value="-1"><?php esc_html_e('Bulk Actions', 'media-usage-checker'); ?></option>
                                <option value="restore"><?php esc_html_e('Restore Selected', 'media-usage-checker'); ?></option>
                                <option value="delete"><?php esc_html_e('Delete Selected Forever', 'media-usage-checker'); ?></option>
                            </select>
                            <button type="submit" class="button action"><?php esc_html_e('Apply', 'media-usage-checker'); ?></button>
                        </div>
                    </div>

                    <table class="wp-list-table widefat fixed striped muc-data-table">
                        <thead>
                            <tr>
                                <th class="check-column"><input type="checkbox" id="select-all"></th>
                                <th class="col-preview"><?php esc_html_e('Preview', 'media-usage-checker'); ?></th>
                                <th class="col-title"><?php esc_html_e('File Info', 'media-usage-checker'); ?></th>
                                <th class="col-size"><?php esc_html_e('Size', 'media-usage-checker'); ?></th>
                                <th class="col-date"><?php esc_html_e('Upload Date', 'media-usage-checker'); ?></th>
                                <th class="col-meta"><?php esc_html_e('Trashed At', 'media-usage-checker'); ?></th>
                                <th class="col-actions"><?php esc_html_e('Actions', 'media-usage-checker'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($trash_query->have_posts()) : $trash_query->the_post(); 
                                $media_id = get_the_ID();
                                $trashed_at = get_post_meta($media_id, '_muc_trashed_at', true);
                                $trash_path = get_post_meta($media_id, '_muc_trash_path', true);
                                
                                // Calculate size from trash path or fallback to meta
                                $file_size = 0;
                                if ( $trash_path && function_exists( 'media_usage_checker_filesize' ) ) {
                                    $file_size = media_usage_checker_filesize( $trash_path );
                                } else {
                                    $file_size = (int) get_post_meta($media_id, '_muc_file_size', true);
                                }
                                
                                // Generate Trash URL for preview
                                $trash_url = add_query_arg(
                                    array(
                                        'action'   => 'muc_trash_preview',
                                        'media_id' => $media_id,
                                        'nonce'    => wp_create_nonce( 'muc_ajax_nonce' ),
                                    ),
                                    admin_url( 'admin-ajax.php' )
                                );
                                ?>
                                <tr data-id="<?php echo esc_attr($media_id); ?>" data-size="<?php echo esc_attr($file_size); ?>">
                                    <td><input type="checkbox" name="selected_media[]" value="<?php echo esc_attr($media_id); ?>"></td>
                                    <td class="col-preview">
                                        <div class="media-preview">
                                            <?php if (wp_attachment_is_image($media_id) && $trash_path) : ?>
                                                <img src="<?php echo esc_url($trash_url); ?>" width="60" height="60" style="object-fit:cover;" alt="">
                                            <?php else : ?>
                                                <span class="dashicons dashicons-media-default"></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td class="col-title">
                                        <strong><?php echo esc_html(get_the_title($media_id)); ?></strong>
                                        <p class="filename"><?php echo esc_html(basename($trash_path ? $trash_path : get_attached_file($media_id))); ?></p>
                                    </td>
                                    <td class="col-size">
                                        <?php echo esc_html(size_format($file_size, 2)); ?>
                                    </td>
                                    <td class="col-date">
                                        <?php echo esc_html(get_the_date('Y/m/d')); ?>
                                    </td>
                                    <td class="col-meta">
                                        <span class="date-trashed"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($trashed_at))); ?></span>
                                    </td>
                                    <td class="col-actions">
                                        <button type="button" class="button button-primary muc-item-action" data-action="restore" data-id="<?php echo esc_attr($media_id); ?>">
                                            <?php esc_html_e('Restore', 'media-usage-checker'); ?>
                                        </button>
                                        <button type="button" class="button button-link-delete muc-item-action" data-action="delete" data-id="<?php echo esc_attr($media_id); ?>">
                                            <?php esc_html_e('Delete Forever', 'media-usage-checker'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </form>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <?php echo wp_kses_post(paginate_links([
                            'base' => add_query_arg('media_page', '%#%'),
                            'total' => $trash_query->max_num_pages,
                            'current' => $current_page
                        ])); ?>
                    </div>
                </div>
            <?php else : ?>
                <div class="muc-empty-state">
                    <span class="dashicons dashicons-trash"></span>
                    <p><?php esc_html_e('Trash is empty.', 'media-usage-checker'); ?></p>
                </div>
            <?php endif; wp_reset_postdata(); ?>
        </div>
        <?php
    }

    private function display_activity() {
        $log = Media_Usage_Checker_Scanner::get_instance()->get_audit_log( 200 );
        ?>
        <div class="muc-activity-section">
            <div class="section-header">
                <h2><?php esc_html_e('Activity Log', 'media-usage-checker'); ?></h2>
            </div>

            <?php if ( empty( $log ) ) : ?>
                <div class="muc-empty-state">
                    <span class="dashicons dashicons-list-view"></span>
                    <p><?php esc_html_e('No activity yet.', 'media-usage-checker'); ?></p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped muc-data-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Date', 'media-usage-checker'); ?></th>
                            <th><?php esc_html_e('Action', 'media-usage-checker'); ?></th>
                            <th><?php esc_html_e('Media', 'media-usage-checker'); ?></th>
                            <th><?php esc_html_e('User', 'media-usage-checker'); ?></th>
                            <th><?php esc_html_e('Size', 'media-usage-checker'); ?></th>
                            <th><?php esc_html_e('Mode', 'media-usage-checker'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $log as $entry ) : ?>
                            <?php
                            $ts      = isset( $entry['ts'] ) ? absint( $entry['ts'] ) : 0;
                            $action  = isset( $entry['action'] ) ? sanitize_key( $entry['action'] ) : '';
                            $media   = isset( $entry['media'] ) ? absint( $entry['media'] ) : 0;
                            $user_id = isset( $entry['user'] ) ? absint( $entry['user'] ) : 0;
                            $size    = isset( $entry['size'] ) ? absint( $entry['size'] ) : 0;
                            $mode    = isset( $entry['mode'] ) ? sanitize_key( $entry['mode'] ) : '';

                            $date_str = $ts ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : '';
                            $user_str = $user_id ? '' : __( 'System', 'media-usage-checker' );
                            if ( $user_id ) {
                                $u = get_userdata( $user_id );
                                $user_str = $u ? $u->display_name : (string) $user_id;
                            }

                            $media_title = $media ? get_the_title( $media ) : '';
                            $media_link  = $media ? get_edit_post_link( $media, '' ) : '';
                            ?>
                            <tr>
                                <td><?php echo esc_html( $date_str ); ?></td>
                                <td><?php echo esc_html( $action ); ?></td>
                                <td>
                                    <?php if ( $media && $media_link ) : ?>
                                        <a href="<?php echo esc_url( $media_link ); ?>"><?php echo esc_html( $media_title ? $media_title : (string) $media ); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html( $media_title ? $media_title : (string) $media ); ?>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( $user_str ); ?></td>
                                <td><?php echo esc_html( $size > 0 ? size_format( $size ) : '' ); ?></td>
                                <td><?php echo esc_html( $mode ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    private function display_settings() {
        if (isset($_POST['muc_save_settings']) && current_user_can('manage_options') && check_admin_referer('muc_settings_nonce')) {
            $batch_size = isset($_POST['muc_batch_size']) ? absint(wp_unslash($_POST['muc_batch_size'])) : 100;
            if ( $batch_size < 10 ) {
                $batch_size = 10;
            }
            if ( $batch_size > 500 ) {
                $batch_size = 500;
            }
            update_option( 'muc_batch_size', $batch_size );

            $scan_frequency = isset($_POST['muc_scan_frequency']) ? sanitize_key(wp_unslash($_POST['muc_scan_frequency'])) : 'daily';
            $scan_frequency = in_array( $scan_frequency, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true ) ? $scan_frequency : 'daily';
            update_option( 'muc_scan_frequency', $scan_frequency );

            $file_types = isset($_POST['muc_file_types']) && is_array($_POST['muc_file_types']) ? array_map( 'sanitize_key', wp_unslash( $_POST['muc_file_types'] ) ) : array();
            $file_types = array_values( array_intersect( $file_types, array( 'image', 'document', 'video', 'audio', 'archive' ) ) );
            update_option( 'muc_file_types', $file_types );

            if ( $this->is_pro() ) {
                $auto_trash_enabled = isset( $_POST['muc_pro_auto_trash_enabled'] ) ? '1' : '0';
                update_option( 'muc_pro_auto_trash_enabled', $auto_trash_enabled, false );

                $auto_trash_min_days = isset( $_POST['muc_pro_auto_trash_min_days'] ) ? absint( wp_unslash( $_POST['muc_pro_auto_trash_min_days'] ) ) : 0;
                update_option( 'muc_pro_auto_trash_min_days', $auto_trash_min_days, false );

                $auto_trash_limit = isset( $_POST['muc_pro_auto_trash_limit'] ) ? absint( wp_unslash( $_POST['muc_pro_auto_trash_limit'] ) ) : 50;
                if ( $auto_trash_limit < 1 ) {
                    $auto_trash_limit = 50;
                }
                if ( $auto_trash_limit > 500 ) {
                    $auto_trash_limit = 500;
                }
                update_option( 'muc_pro_auto_trash_limit', $auto_trash_limit, false );

                $purge_enabled = isset( $_POST['muc_pro_auto_purge_enabled'] ) ? '1' : '0';
                update_option( 'muc_pro_auto_purge_enabled', $purge_enabled, false );

                $retention_days = isset( $_POST['muc_pro_trash_retention_days'] ) ? absint( wp_unslash( $_POST['muc_pro_trash_retention_days'] ) ) : 30;
                if ( $retention_days < 1 ) {
                    $retention_days = 30;
                }
                update_option( 'muc_pro_trash_retention_days', $retention_days, false );

                $purge_limit = isset( $_POST['muc_pro_auto_purge_limit'] ) ? absint( wp_unslash( $_POST['muc_pro_auto_purge_limit'] ) ) : 50;
                if ( $purge_limit < 1 ) {
                    $purge_limit = 50;
                }
                if ( $purge_limit > 500 ) {
                    $purge_limit = 500;
                }
                update_option( 'muc_pro_auto_purge_limit', $purge_limit, false );

                $weekly_report_enabled = isset( $_POST['muc_pro_weekly_report_enabled'] ) ? '1' : '0';
                update_option( 'muc_pro_weekly_report_enabled', $weekly_report_enabled, false );

                $email_to = isset( $_POST['muc_pro_report_email_to'] ) ? sanitize_email( wp_unslash( $_POST['muc_pro_report_email_to'] ) ) : '';
                if ( empty( $email_to ) || ! is_email( $email_to ) ) {
                    $email_to = get_option( 'admin_email' );
                }
                update_option( 'muc_pro_report_email_to', $email_to, false );

                $exclude_filename_contains = isset( $_POST['muc_pro_exclude_filename_contains'] ) ? sanitize_text_field( wp_unslash( $_POST['muc_pro_exclude_filename_contains'] ) ) : '';
                update_option( 'muc_pro_exclude_filename_contains', $exclude_filename_contains, false );

                $exclude_larger_mb = isset( $_POST['muc_pro_exclude_larger_than_mb'] ) ? absint( wp_unslash( $_POST['muc_pro_exclude_larger_than_mb'] ) ) : 0;
                update_option( 'muc_pro_exclude_larger_than_mb', $exclude_larger_mb, false );

                $exclude_mime_prefixes = isset( $_POST['muc_pro_exclude_mime_prefixes'] ) && is_array( $_POST['muc_pro_exclude_mime_prefixes'] )
                    ? array_map( 'sanitize_text_field', wp_unslash( $_POST['muc_pro_exclude_mime_prefixes'] ) )
                    : array();
                $exclude_mime_prefixes = array_values( array_intersect( $exclude_mime_prefixes, array( 'image/', 'video/', 'audio/', 'application/' ) ) );
                update_option( 'muc_pro_exclude_mime_prefixes', $exclude_mime_prefixes, false );

                $exclude_paths = isset( $_POST['muc_pro_exclude_paths'] ) ? sanitize_text_field( wp_unslash( $_POST['muc_pro_exclude_paths'] ) ) : '';
                update_option( 'muc_pro_exclude_paths', $exclude_paths, false );

                $exclude_author_ids = isset( $_POST['muc_pro_exclude_author_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['muc_pro_exclude_author_ids'] ) ) : '';
                update_option( 'muc_pro_exclude_author_ids', $exclude_author_ids, false );
            }
            if ( function_exists( 'media_usage_checker_schedule_cron' ) ) {
                media_usage_checker_schedule_cron( $scan_frequency );
            }
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved successfully.', 'media-usage-checker') . '</p></div>';
        }

        $batch_size = $this->is_pro() ? get_option('muc_batch_size', 100) : 20;
        $scan_frequency = get_option('muc_scan_frequency', 'daily');
        $file_types = get_option('muc_file_types', ['image', 'document', 'video', 'audio']);

        $pro_auto_trash_enabled  = '1' === (string) get_option( 'muc_pro_auto_trash_enabled', '0' );
        $pro_auto_trash_min_days = absint( get_option( 'muc_pro_auto_trash_min_days', 0 ) );
        $pro_auto_trash_limit    = absint( get_option( 'muc_pro_auto_trash_limit', 50 ) );
        $pro_purge_enabled       = '1' === (string) get_option( 'muc_pro_auto_purge_enabled', '0' );
        $pro_retention_days      = absint( get_option( 'muc_pro_trash_retention_days', 30 ) );
        $pro_purge_limit         = absint( get_option( 'muc_pro_auto_purge_limit', 50 ) );
        $pro_report_enabled      = '1' === (string) get_option( 'muc_pro_weekly_report_enabled', '0' );
        $pro_report_email_to     = get_option( 'muc_pro_report_email_to', get_option( 'admin_email' ) );
        $pro_exclude_name        = get_option( 'muc_pro_exclude_filename_contains', '' );
        $pro_exclude_larger_mb   = absint( get_option( 'muc_pro_exclude_larger_than_mb', 0 ) );
        $pro_exclude_mime_prefixes = get_option( 'muc_pro_exclude_mime_prefixes', array() );
        $pro_exclude_paths         = get_option( 'muc_pro_exclude_paths', '' );
        $pro_exclude_author_ids    = get_option( 'muc_pro_exclude_author_ids', '' );
        ?>
        <div class="muc-settings-section">
            <h2><?php esc_html_e('Global Settings', 'media-usage-checker'); ?></h2>
            <form method="post" class="muc-settings-form">
                <?php wp_nonce_field('muc_settings_nonce'); ?>
                
                <div class="settings-card">
                    <h3><span class="dashicons dashicons-performance"></span> <?php esc_html_e('Performance', 'media-usage-checker'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="muc_batch_size"><?php esc_html_e('Batch Size', 'media-usage-checker'); ?></label></th>
                            <td>
                                <?php if ($this->is_pro()) : ?>
                                    <input type="number" id="muc_batch_size" name="muc_batch_size" value="<?php echo esc_attr($batch_size); ?>" min="10" max="500">
                                    <p class="description"><?php esc_html_e('Optimize scanning speed. Recommended: 100.', 'media-usage-checker'); ?></p>
                                <?php else : ?>
                                    <input type="number" id="muc_batch_size" name="muc_batch_size" value="20" min="20" max="20" readonly>
                                    <p class="description"><?php esc_html_e('Custom batch size is a PRO feature. FREE uses 20.', 'media-usage-checker'); ?> <?php echo wp_kses_post( $this->pro_badge() ); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-card">
                    <h3><span class="dashicons dashicons-calendar-alt"></span> <?php esc_html_e('Automation', 'media-usage-checker'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Scan Frequency', 'media-usage-checker'); ?></th>
                            <td>
                                <select name="muc_scan_frequency">
                                    <option value="hourly" <?php selected($scan_frequency, 'hourly'); ?>><?php esc_html_e('Hourly', 'media-usage-checker'); ?></option>
                                    <option value="twicedaily" <?php selected($scan_frequency, 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'media-usage-checker'); ?></option>
                                    <option value="daily" <?php selected($scan_frequency, 'daily'); ?>><?php esc_html_e('Daily', 'media-usage-checker'); ?></option>
                                    <option value="weekly" <?php selected($scan_frequency, 'weekly'); ?>><?php esc_html_e('Weekly', 'media-usage-checker'); ?></option>
                                </select>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-card">
                    <h3><span class="dashicons dashicons-media-text"></span> <?php esc_html_e('File Types', 'media-usage-checker'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Included Formats', 'media-usage-checker'); ?></th>
                            <td>
                                <div class="checkbox-group">
                                    <label><input type="checkbox" name="muc_file_types[]" value="image" <?php checked(in_array('image', $file_types, true)); ?>> <?php esc_html_e('Images', 'media-usage-checker'); ?></label>
                                    <label><input type="checkbox" name="muc_file_types[]" value="document" <?php checked(in_array('document', $file_types, true)); ?>> <?php esc_html_e('Documents (PDF, Doc, etc)', 'media-usage-checker'); ?></label>
                                    <label><input type="checkbox" name="muc_file_types[]" value="video" <?php checked(in_array('video', $file_types, true)); ?>> <?php esc_html_e('Videos', 'media-usage-checker'); ?></label>
                                    <label><input type="checkbox" name="muc_file_types[]" value="audio" <?php checked(in_array('audio', $file_types, true)); ?>> <?php esc_html_e('Audio', 'media-usage-checker'); ?></label>
                                    <label><input type="checkbox" name="muc_file_types[]" value="archive" <?php checked(in_array('archive', $file_types, true)); ?>> <?php esc_html_e('Archives (ZIP, RAR)', 'media-usage-checker'); ?></label>
                                </div>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="settings-card">
                    <h3><span class="dashicons dashicons-shield-alt"></span> <?php esc_html_e('PRO Exclusions', 'media-usage-checker'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Exclude by file type', 'media-usage-checker'); ?></th>
                            <td>
                                <?php if ( $this->is_pro() ) : ?>
                                    <label><input type="checkbox" name="muc_pro_exclude_mime_prefixes[]" value="image/" <?php checked( is_array( $pro_exclude_mime_prefixes ) && in_array( 'image/', $pro_exclude_mime_prefixes, true ) ); ?>> <?php esc_html_e('Images', 'media-usage-checker'); ?></label><br>
                                    <label><input type="checkbox" name="muc_pro_exclude_mime_prefixes[]" value="video/" <?php checked( is_array( $pro_exclude_mime_prefixes ) && in_array( 'video/', $pro_exclude_mime_prefixes, true ) ); ?>> <?php esc_html_e('Videos', 'media-usage-checker'); ?></label><br>
                                    <label><input type="checkbox" name="muc_pro_exclude_mime_prefixes[]" value="audio/" <?php checked( is_array( $pro_exclude_mime_prefixes ) && in_array( 'audio/', $pro_exclude_mime_prefixes, true ) ); ?>> <?php esc_html_e('Audio', 'media-usage-checker'); ?></label><br>
                                    <label><input type="checkbox" name="muc_pro_exclude_mime_prefixes[]" value="application/" <?php checked( is_array( $pro_exclude_mime_prefixes ) && in_array( 'application/', $pro_exclude_mime_prefixes, true ) ); ?>> <?php esc_html_e('Documents / Archives', 'media-usage-checker'); ?></label>
                                <?php else : ?>
                                    <label><input type="checkbox" disabled> <?php esc_html_e('Images', 'media-usage-checker'); ?></label><br>
                                    <label><input type="checkbox" disabled> <?php esc_html_e('Videos', 'media-usage-checker'); ?></label><br>
                                    <label><input type="checkbox" disabled> <?php esc_html_e('Audio', 'media-usage-checker'); ?></label><br>
                                    <label><input type="checkbox" disabled> <?php esc_html_e('Documents / Archives', 'media-usage-checker'); ?></label>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="muc_pro_exclude_filename_contains"><?php esc_html_e('Exclude by filename contains', 'media-usage-checker'); ?></label></th>
                            <td>
                                <?php if ( $this->is_pro() ) : ?>
                                    <input type="text" id="muc_pro_exclude_filename_contains" name="muc_pro_exclude_filename_contains" value="<?php echo esc_attr( $pro_exclude_name ); ?>" class="regular-text">
                                <?php else : ?>
                                    <input type="text" id="muc_pro_exclude_filename_contains" value="" class="regular-text" disabled>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="muc_pro_exclude_paths"><?php esc_html_e('Exclude by folder (relative to uploads)', 'media-usage-checker'); ?></label></th>
                            <td>
                                <?php if ( $this->is_pro() ) : ?>
                                    <input type="text" id="muc_pro_exclude_paths" name="muc_pro_exclude_paths" value="<?php echo esc_attr( $pro_exclude_paths ); ?>" class="regular-text">
                                <?php else : ?>
                                    <input type="text" id="muc_pro_exclude_paths" value="" class="regular-text" disabled>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="muc_pro_exclude_larger_than_mb"><?php esc_html_e('Exclude files larger than (MB)', 'media-usage-checker'); ?></label></th>
                            <td>
                                <?php if ( $this->is_pro() ) : ?>
                                    <input type="number" id="muc_pro_exclude_larger_than_mb" name="muc_pro_exclude_larger_than_mb" value="<?php echo esc_attr( $pro_exclude_larger_mb ); ?>" min="0" max="20480">
                                <?php else : ?>
                                    <input type="number" id="muc_pro_exclude_larger_than_mb" value="0" min="0" max="20480" disabled>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="muc_pro_exclude_author_ids"><?php esc_html_e('Exclude by author IDs (comma-separated)', 'media-usage-checker'); ?></label></th>
                            <td>
                                <?php if ( $this->is_pro() ) : ?>
                                    <input type="text" id="muc_pro_exclude_author_ids" name="muc_pro_exclude_author_ids" value="<?php echo esc_attr( $pro_exclude_author_ids ); ?>" class="regular-text">
                                <?php else : ?>
                                    <input type="text" id="muc_pro_exclude_author_ids" value="" class="regular-text" disabled>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <?php if ( ! $this->is_pro() ) : ?>
                        <p class="description"><?php esc_html_e('These settings are available in PRO.', 'media-usage-checker'); ?> <?php echo wp_kses_post( $this->pro_badge() ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="settings-card">
                    <h3><span class="dashicons dashicons-trash"></span> <?php esc_html_e('PRO Automation', 'media-usage-checker'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Auto-trash unused files', 'media-usage-checker'); ?></th>
                            <td>
                                <?php if ( $this->is_pro() ) : ?>
                                    <label><input type="checkbox" name="muc_pro_auto_trash_enabled" value="1" <?php checked( $pro_auto_trash_enabled ); ?>> <?php esc_html_e('Enable', 'media-usage-checker'); ?></label>
                                    <p>
                                        <label for="muc_pro_auto_trash_min_days"><?php esc_html_e('Only if unused for at least (days)', 'media-usage-checker'); ?></label>
                                        <input type="number" id="muc_pro_auto_trash_min_days" name="muc_pro_auto_trash_min_days" value="<?php echo esc_attr( $pro_auto_trash_min_days ); ?>" min="0" max="3650">
                                    </p>
                                    <p>
                                        <label for="muc_pro_auto_trash_limit"><?php esc_html_e('Limit per run', 'media-usage-checker'); ?></label>
                                        <input type="number" id="muc_pro_auto_trash_limit" name="muc_pro_auto_trash_limit" value="<?php echo esc_attr( $pro_auto_trash_limit ); ?>" min="1" max="500">
                                    </p>
                                <?php else : ?>
                                    <label><input type="checkbox" disabled> <?php esc_html_e('Enable', 'media-usage-checker'); ?></label>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Auto-purge trash', 'media-usage-checker'); ?></th>
                            <td>
                                <?php if ( $this->is_pro() ) : ?>
                                    <label><input type="checkbox" name="muc_pro_auto_purge_enabled" value="1" <?php checked( $pro_purge_enabled ); ?>> <?php esc_html_e('Enable', 'media-usage-checker'); ?></label>
                                    <p>
                                        <label for="muc_pro_trash_retention_days"><?php esc_html_e('Retention (days)', 'media-usage-checker'); ?></label>
                                        <input type="number" id="muc_pro_trash_retention_days" name="muc_pro_trash_retention_days" value="<?php echo esc_attr( $pro_retention_days ); ?>" min="1" max="3650">
                                    </p>
                                    <p>
                                        <label for="muc_pro_auto_purge_limit"><?php esc_html_e('Limit per run', 'media-usage-checker'); ?></label>
                                        <input type="number" id="muc_pro_auto_purge_limit" name="muc_pro_auto_purge_limit" value="<?php echo esc_attr( $pro_purge_limit ); ?>" min="1" max="500">
                                    </p>
                                <?php else : ?>
                                    <label><input type="checkbox" disabled> <?php esc_html_e('Enable', 'media-usage-checker'); ?></label>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Weekly email report', 'media-usage-checker'); ?></th>
                            <td>
                                <?php if ( $this->is_pro() ) : ?>
                                    <label><input type="checkbox" name="muc_pro_weekly_report_enabled" value="1" <?php checked( $pro_report_enabled ); ?>> <?php esc_html_e('Enable', 'media-usage-checker'); ?></label>
                                    <p>
                                        <label for="muc_pro_report_email_to"><?php esc_html_e('Send to', 'media-usage-checker'); ?></label>
                                        <input type="email" id="muc_pro_report_email_to" name="muc_pro_report_email_to" value="<?php echo esc_attr( $pro_report_email_to ); ?>" class="regular-text">
                                    </p>
                                <?php else : ?>
                                    <label><input type="checkbox" disabled> <?php esc_html_e('Enable', 'media-usage-checker'); ?></label>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <?php if ( ! $this->is_pro() ) : ?>
                        <p class="description"><?php esc_html_e('These automation features are available in PRO.', 'media-usage-checker'); ?> <?php echo wp_kses_post( $this->pro_badge() ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="submit-wrapper">
                    <button type="submit" name="muc_save_settings" class="button button-primary button-large"><?php esc_html_e('Save Changes', 'media-usage-checker'); ?></button>
                </div>
            </form>
        </div>
        <?php
    }
}
