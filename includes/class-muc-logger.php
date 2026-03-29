<?php
/**
 * Media Usage Checker Logger
 * 
 * @package Media_Usage_Checker
 * @since 2.8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Usage_Checker_Logger {
    private static $instance = null;
    private $log_file;
    private $max_log_size = 1024 * 1024; // 1MB

    private function __construct() {
        $upload_dir = wp_upload_dir();
        $logs_dir = $upload_dir['basedir'] . '/muc-logs';
        $this->log_file = $logs_dir . '/muc-' . gmdate( 'Y-m-d' ) . '.log';
        
        // Create logs directory if it doesn't exist
        wp_mkdir_p($logs_dir);

        $htaccess_file = $logs_dir . '/.htaccess';
        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'exists' ) && ! $fs->exists( $htaccess_file ) ) {
            $this->write_file( $htaccess_file, "Options -Indexes\nDeny from all\n" );
        }
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function get_filesystem() {
        global $wp_filesystem;
        if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
            return $wp_filesystem;
        }

        if ( defined( 'ABSPATH' ) && ! function_exists( 'WP_Filesystem' ) ) {
            $file_api = ABSPATH . 'wp-admin/includes/file.php';
            require_once $file_api;
        }

        if ( function_exists( 'WP_Filesystem' ) ) {
            WP_Filesystem();
        }

        if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
            return $wp_filesystem;
        }

        if ( defined( 'ABSPATH' ) && ! class_exists( 'WP_Filesystem_Direct' ) ) {
            $base = ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
            $direct = ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            require_once $base;
            require_once $direct;
        }

        if ( class_exists( 'WP_Filesystem_Direct' ) ) {
            return new WP_Filesystem_Direct( null );
        }

        return null;
    }

    private function write_file( $path, $contents ) {
        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'put_contents' ) ) {
            $mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
            return (bool) $fs->put_contents( $path, $contents, $mode );
        }

        return false;
    }

    private function append_file( $path, $contents ) {
        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'put_contents' ) && method_exists( $fs, 'get_contents' ) ) {
            $existing = '';
            if ( $fs->exists( $path ) ) {
                $existing = (string) $fs->get_contents( $path );
            }

            $mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
            return (bool) $fs->put_contents( $path, $existing . $contents, $mode );
        }

        return false;
    }

    public function log($message, $type = 'info') {
        if ( ! is_string( $message ) ) {
            $encoded = wp_json_encode( $message );
            $message = false !== $encoded ? $encoded : '';
        }

        $log_message = sprintf(
            '[%s] [%s] %s' . PHP_EOL,
            current_time('Y-m-d H:i:s'),
            strtoupper($type),
            $message
        );

        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'exists' ) && $fs->exists( $this->log_file ) ) {
            $size = 0;
            if ( method_exists( $fs, 'size' ) ) {
                $size = (int) $fs->size( $this->log_file );
            }
            if ( $size > $this->max_log_size ) {
                $this->rotate_logs();
            }
        }

        $this->append_file( $this->log_file, $log_message );
    }

    private function rotate_logs() {
        $backup_name = str_replace( '.log', '-' . gmdate( 'His' ) . '.log', $this->log_file );
        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'move' ) && method_exists( $fs, 'exists' ) && $fs->exists( $this->log_file ) ) {
            $fs->move( $this->log_file, $backup_name, true );
        }
    }

    public function get_logs($lines = 100) {
        $fs = $this->get_filesystem();
        if ( ! $fs || ! method_exists( $fs, 'exists' ) || ! $fs->exists( $this->log_file ) ) {
            return array();
        }

        $contents = '';
        if ( method_exists( $fs, 'get_contents' ) ) {
            $contents = (string) $fs->get_contents( $this->log_file );
        }
        if ( '' === $contents ) {
            return array();
        }

        $logs = preg_split( "/\r\n|\n|\r/", $contents );
        $logs = array_values( array_filter( array_map( 'trim', $logs ) ) );
        return array_slice( $logs, -absint( $lines ) );
    }

    public function clear_logs() {
        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'delete' ) && method_exists( $fs, 'exists' ) && $fs->exists( $this->log_file ) ) {
            return (bool) $fs->delete( $this->log_file, false, 'f' );
        }

        return false;
    }
}
