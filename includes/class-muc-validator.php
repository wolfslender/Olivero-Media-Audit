<?php
/**
 * Media Usage Checker Validator
 * 
 * @package Media_Usage_Checker
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Media_Usage_Checker_Validator {
    private static $instance = null;

    private function __construct() {}

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function validate_file_path($path) {
        if (empty($path)) {
            return false;
        }

        $path = wp_normalize_path( (string) $path );
        $uploads = wp_upload_dir();

        if ( empty( $uploads['basedir'] ) ) {
            return false;
        }

        $base_dir = wp_normalize_path( $uploads['basedir'] );
        if ( 0 !== strpos( $path, $base_dir . '/' ) && $path !== $base_dir ) {
            return false;
        }

        $fs = function_exists( 'media_usage_checker_get_filesystem' ) ? media_usage_checker_get_filesystem() : null;
        if ( ! $fs || ! method_exists( $fs, 'exists' ) || ! $fs->exists( $path ) ) {
            return false;
        }

        return $path;
    }

    public function validate_media_id($id) {
        if (!is_numeric($id)) {
            return false;
        }

        $id = intval($id);
        if ($id <= 0) {
            return false;
        }

        return get_post($id) ? $id : false;
    }

    public function validate_batch_size($size) {
        if (!is_numeric($size)) {
            return false;
        }

        $size = intval($size);
        if ($size <= 0 || $size > 1000) {
            return false;
        }

        return $size;
    }

    public function validate_action($action) {
        $allowed_actions = [
            'check_media',
            'delete_media',
            'force_check',
            'clear_logs'
        ];

        return in_array($action, $allowed_actions, true);
    }

    public function validate_nonce($nonce, $action) {
        return wp_verify_nonce($nonce, 'muc_' . $action);
    }
}
