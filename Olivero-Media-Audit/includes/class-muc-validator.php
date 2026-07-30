<?php
/**
 * Media Usage Checker Validator
 * 
 * @package Oliverodev_Media_Audit
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class Oliverodev_Media_Audit_Validator {
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

        $fs = $this->get_filesystem();
        if ( ! $fs || ! method_exists( $fs, 'exists' ) || ! $fs->exists( $path ) ) {
            return false;
        }

        return $path;
    }

    private function get_filesystem() {
        global $wp_filesystem;
        if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
            return $wp_filesystem;
        }

        if ( defined( 'ABSPATH' ) && ! function_exists( 'WP_Filesystem' ) ) {
            $inc = ABSPATH . 'wp-admin/includes/';
            if ( file_exists( $inc . 'file.php' ) ) {
                require_once $inc . 'file.php';
            }
        }

        if ( function_exists( 'WP_Filesystem' ) ) {
            WP_Filesystem();
        }

        if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
            return $wp_filesystem;
        }

        if ( class_exists( 'WP_Filesystem_Direct' ) ) {
            return new WP_Filesystem_Direct( null );
        }

        return null;
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
        return wp_verify_nonce($nonce, 'oliverodev_media_audit_' . $action);
    }
}
