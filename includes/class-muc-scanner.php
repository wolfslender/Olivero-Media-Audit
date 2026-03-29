<?php
/**
 * Media Usage Checker Scanner
 * 
 * @package Media_Usage_Checker
 */

if (!defined('ABSPATH')) {
    exit;
}

class Media_Usage_Checker_Scanner {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function cache_group() {
        return 'media-usage-checker';
    }

    private function get_cache_salt() {
        $salt = get_option( 'muc_cache_salt', 0 );
        $salt = absint( $salt );

        if ( 0 === $salt ) {
            $salt = time();
            add_option( 'muc_cache_salt', $salt, '', 'no' );
        }

        return $salt;
    }

    private function bump_cache_salt() {
        $salt = time();
        if ( false === get_option( 'muc_cache_salt', false ) ) {
            add_option( 'muc_cache_salt', $salt, '', 'no' );
        } else {
            update_option( 'muc_cache_salt', $salt, false );
        }
    }

    public function invalidate_cache() {
        $this->bump_cache_salt();
    }

    private function cache_key( $key ) {
        return $this->get_cache_salt() . ':' . $key;
    }

    private function cache_get( $key ) {
        $value = wp_cache_get( $this->cache_key( $key ), $this->cache_group() );
        return false === $value ? null : $value;
    }

    private function cache_set( $key, $value, $expiration = 300 ) {
        return wp_cache_set( $this->cache_key( $key ), $value, $this->cache_group(), absint( $expiration ) );
    }

    private function is_pro() {
        return true;
    }

    private function is_excluded_from_cleanup( $media_id, $file_path, $size ) {
        $media_id = absint( $media_id );
        if ( 0 === $media_id || ! $this->is_pro() ) {
            return false;
        }

        $needle_csv = get_option( 'muc_pro_exclude_filename_contains', '' );
        $needle_csv = is_string( $needle_csv ) ? $needle_csv : '';
        $needles = array_filter( array_map( 'trim', explode( ',', $needle_csv ) ) );

        if ( $file_path && ! empty( $needles ) ) {
            $name = strtolower( (string) basename( $file_path ) );
            foreach ( $needles as $needle ) {
                $needle = strtolower( (string) $needle );
                if ( '' === $needle ) {
                    continue;
                }
                if ( false !== strpos( $name, $needle ) ) {
                    return true;
                }
            }
        }

        $exclude_larger_mb = absint( get_option( 'muc_pro_exclude_larger_than_mb', 0 ) );
        if ( $exclude_larger_mb > 0 && $size > 0 ) {
            $threshold = $exclude_larger_mb * MB_IN_BYTES;
            if ( $size >= $threshold ) {
                return true;
            }
        }

        $mime_prefixes = get_option( 'muc_pro_exclude_mime_prefixes', array() );
        if ( is_array( $mime_prefixes ) && ! empty( $mime_prefixes ) ) {
            $mime = get_post_mime_type( $media_id );
            $mime = is_string( $mime ) ? $mime : '';
            if ( '' !== $mime ) {
                foreach ( $mime_prefixes as $prefix ) {
                    $prefix = is_string( $prefix ) ? $prefix : '';
                    if ( '' === $prefix ) {
                        continue;
                    }
                    if ( 0 === strpos( $mime, $prefix ) ) {
                        return true;
                    }
                }
            }
        }

        $exclude_paths_csv = get_option( 'muc_pro_exclude_paths', '' );
        $exclude_paths_csv = is_string( $exclude_paths_csv ) ? $exclude_paths_csv : '';
        $exclude_paths = array_filter( array_map( 'trim', explode( ',', $exclude_paths_csv ) ) );
        if ( $file_path && ! empty( $exclude_paths ) ) {
            $uploads = wp_upload_dir();
            $base_dir = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
            $file_path_norm = wp_normalize_path( $file_path );
            if ( $base_dir && 0 === strpos( $file_path_norm, trailingslashit( $base_dir ) ) ) {
                $relative = ltrim( substr( $file_path_norm, strlen( trailingslashit( $base_dir ) ) ), '/' );
                $relative = strtolower( $relative );
                foreach ( $exclude_paths as $p ) {
                    $p = strtolower( trim( $p, " \t\n\r\0\x0B/" ) );
                    if ( '' === $p ) {
                        continue;
                    }
                    if ( 0 === strpos( $relative, $p . '/' ) || $relative === $p ) {
                        return true;
                    }
                }
            }
        }

        $exclude_author_ids_csv = get_option( 'muc_pro_exclude_author_ids', '' );
        $exclude_author_ids_csv = is_string( $exclude_author_ids_csv ) ? $exclude_author_ids_csv : '';
        $exclude_author_ids = array_filter( array_map( 'absint', explode( ',', $exclude_author_ids_csv ) ) );
        if ( ! empty( $exclude_author_ids ) ) {
            $author_id = absint( get_post_field( 'post_author', $media_id ) );
            if ( $author_id > 0 && in_array( $author_id, $exclude_author_ids, true ) ) {
                return true;
            }
        }

        return false;
    }

    private function audit_log_key() {
        return 'muc_pro_audit_log';
    }

    private function audit_log_add( $action, $media_id, $data = array(), $system = false ) {
        if ( ! $this->is_pro() ) {
            return;
        }

        $action   = is_string( $action ) ? sanitize_key( $action ) : '';
        $media_id = absint( $media_id );
        if ( '' === $action || $media_id < 1 ) {
            return;
        }

        $entry = array(
            'ts'      => current_time( 'timestamp' ),
            'action'  => $action,
            'media'   => $media_id,
            'user'    => $system ? 0 : absint( get_current_user_id() ),
            'system'  => $system ? 1 : 0,
        );

        if ( is_array( $data ) && ! empty( $data ) ) {
            foreach ( $data as $k => $v ) {
                if ( is_string( $k ) ) {
                    $entry[ sanitize_key( $k ) ] = is_scalar( $v ) ? $v : wp_json_encode( $v );
                }
            }
        }

        $log = get_option( $this->audit_log_key(), array() );
        if ( ! is_array( $log ) ) {
            $log = array();
        }

        array_unshift( $log, $entry );
        if ( count( $log ) > 1000 ) {
            $log = array_slice( $log, 0, 1000 );
        }

        update_option( $this->audit_log_key(), $log, false );
    }

    public function get_audit_log( $limit = 200 ) {
        if ( ! $this->is_pro() ) {
            return array();
        }

        $limit = absint( $limit );
        if ( $limit < 1 ) {
            $limit = 200;
        }

        $log = get_option( $this->audit_log_key(), array() );
        if ( ! is_array( $log ) ) {
            return array();
        }

        return array_slice( $log, 0, $limit );
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

    private function move_file( $source, $destination ) {
        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'move' ) ) {
            return (bool) $fs->move( $source, $destination, true );
        }

        return false;
    }

    private function delete_file( $path ) {
        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'delete' ) ) {
            return (bool) $fs->delete( $path, false, 'f' );
        }

        return (bool) wp_delete_file( $path );
    }

    private function list_dir( $dir ) {
        $fs = $this->get_filesystem();
        if ( $fs && method_exists( $fs, 'dirlist' ) ) {
            $items = $fs->dirlist( $dir, false, true );
            if ( ! is_array( $items ) ) {
                return array();
            }

            return array_values( array_diff( array_keys( $items ), array( '.', '..' ) ) );
        }

        return array();
    }

    /**
     * Check if a media item is being used.
     * 
     * Universal Detection for JSON, Page Builders (Elementor, Divi), and serialized data.
     * Uses strict matching to avoid false positives (Surgical Precision).
     * Optimized into a single UNION query for better performance.
     * 
     * @param int $media_id The attachment ID.
     * @return bool True if media is in use, false otherwise.
     */
    public function is_media_in_use( $media_id ) {
        global $wpdb;

        if ( get_post_meta( $media_id, '_muc_trashed_at', true ) ) {
            return false;
        }

        $media_url = wp_get_attachment_url( $media_id );
        if ( ! $media_url ) {
            return false;
        }

        $media_path = get_attached_file( $media_id );
        $upload_dir = wp_upload_dir();
        $filename   = '';
        $relative_path = '';
        if ( $media_path ) {
            $filename = basename( $media_path );
            $relative_path = str_replace( $upload_dir['basedir'], '', $media_path );
            $relative_path = '/' . ltrim( $relative_path, '/' );
        }

        $search_terms   = array();
        $search_terms[] = $media_url;
        $search_terms[] = str_replace( '/', '\/', $media_url );
        if ( $relative_path ) {
            $search_terms[] = ltrim( $relative_path, '/' );
        }
        $search_terms[] = 'wp-image-' . $media_id;

        if ( absint( get_option( 'site_icon' ) ) === absint( $media_id ) ) {
            return true;
        }

        $thumbnail_cache_key = $this->cache_key( 'thumb_post_' . absint( $media_id ) );
        $thumbnail_post_id   = wp_cache_get( $thumbnail_cache_key, $this->cache_group() );
        if ( false === $thumbnail_post_id ) {
            $thumbnail_post_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1",
                    $media_id
                )
            );
            wp_cache_set( $thumbnail_cache_key, $thumbnail_post_id, $this->cache_group(), 300 );
        }
        if ( $thumbnail_post_id ) {
            return true;
        }

        $metadata = wp_get_attachment_metadata( $media_id );
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $base_url = dirname( $media_url );
            $base_dir = $relative_path ? dirname( $relative_path ) : '';
            foreach ( $metadata['sizes'] as $size_info ) {
                if ( isset( $size_info['file'] ) ) {
                    $size_filename = $size_info['file'];
                    $size_url      = $base_url . '/' . $size_filename;
                    $search_terms[] = $size_url;
                    $search_terms[] = str_replace( '/', '\/', $size_url );
                    if ( $base_dir ) {
                        $search_terms[] = ltrim( $base_dir . '/' . $size_filename, '/' );
                    }
                }
            }
        }

        $search_terms = array_values( array_unique( array_filter( $search_terms ) ) );
        $is_pro = $this->is_pro();

        $has_like_in_posts = function ( $terms ) use ( $wpdb ) {
            foreach ( $terms as $term ) {
                $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
                $cache_key = $this->cache_key( 'p_like_' . md5( (string) $term ) );
                $found     = wp_cache_get( $cache_key, $this->cache_group() );
                if ( false === $found ) {
                    $found = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('attachment', 'revision', 'auto-draft') AND post_status NOT IN ('auto-draft','trash') LIMIT 1",
                            $like
                        )
                    );
                    wp_cache_set( $cache_key, $found, $this->cache_group(), 300 );
                }
                if ( $found ) {
                    return true;
                }
            }
            return false;
        };

        if ( ! $is_pro ) {
            return $has_like_in_posts( $search_terms );
        }

        $id_patterns = array(
            '"id":' . $media_id . ',',
            '"id":' . $media_id . '}',
            '"id":"' . $media_id . '"',
            '"id": ' . $media_id . ',',
            '"id": ' . $media_id . '}',
            'i:' . $media_id . ';',
            's:' . strlen( (string) $media_id ) . ':"' . $media_id . '";',
            'ids="' . $media_id . '"',
            'ids="' . $media_id . ',',
            ',' . $media_id . ',',
            ',' . $media_id . '"',
            'data-id="' . $media_id . '"',
            'elementor-repeater-item-' . $media_id,
        );

        $pro_terms = array_values( array_unique( array_filter( array_merge( $search_terms, $id_patterns ) ) ) );
        if ( $has_like_in_posts( $pro_terms ) ) {
            return true;
        }

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $cache_key = $this->cache_key( 'pm_like_' . absint( $media_id ) . '_' . md5( (string) $term ) );
            $found     = wp_cache_get( $cache_key, $this->cache_group() );
            if ( false === $found ) {
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND post_id != %d LIMIT 1",
                        $like,
                        (int) $media_id
                    )
                );
                wp_cache_set( $cache_key, $found, $this->cache_group(), 300 );
            }
            if ( $found ) {
                return true;
            }
        }

        $exact_cache_key      = $this->cache_key( 'pm_exact_' . absint( $media_id ) );
        $found_exact_meta_id  = wp_cache_get( $exact_cache_key, $this->cache_group() );
        if ( false === $found_exact_meta_id ) {
            $found_exact_meta_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %d AND post_id != %d LIMIT 1",
                    (int) $media_id,
                    (int) $media_id
                )
            );
            wp_cache_set( $exact_cache_key, $found_exact_meta_id, $this->cache_group(), 300 );
        }
        if ( $found_exact_meta_id ) {
            return true;
        }

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $cache_key = $this->cache_key( 'um_like_' . md5( (string) $term ) );
            $found     = wp_cache_get( $cache_key, $this->cache_group() );
            if ( false === $found ) {
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_value LIKE %s LIMIT 1",
                        $like
                    )
                );
                wp_cache_set( $cache_key, $found, $this->cache_group(), 300 );
            }
            if ( $found ) {
                return true;
            }
        }

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $cache_key = $this->cache_key( 'tm_like_' . md5( (string) $term ) );
            $found     = wp_cache_get( $cache_key, $this->cache_group() );
            if ( false === $found ) {
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT 1",
                        $like
                    )
                );
                wp_cache_set( $cache_key, $found, $this->cache_group(), 300 );
            }
            if ( $found ) {
                return true;
            }
        }

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $cache_key = $this->cache_key( 'opt_like_' . md5( (string) $term ) );
            $found     = wp_cache_get( $cache_key, $this->cache_group() );
            if ( false === $found ) {
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1",
                        $like
                    )
                );
                wp_cache_set( $cache_key, $found, $this->cache_group(), 300 );
            }
            if ( $found ) {
                return true;
            }
        }

        if ( get_theme_mod( 'custom_logo' ) == $media_id ) {
            return true;
        }

        $header_image = get_theme_mod( 'header_image' );
        if ( $header_image && ( $header_image == $media_url || ( $filename && strpos( $header_image, $filename ) !== false ) ) ) {
            return true;
        }

        $background_image = get_theme_mod( 'background_image' );
        if ( $background_image && ( $background_image == $media_url || ( $filename && strpos( $background_image, $filename ) !== false ) ) ) {
            return true;
        }

        return apply_filters( 'media_usage_checker_is_media_used', false, $media_id, $filename );
    }

    public function get_usage_evidence( $media_id, $limit = 5 ) {
        if ( ! $this->is_pro() ) {
            return array();
        }

        global $wpdb;

        $media_id = absint( $media_id );
        $limit    = absint( $limit );
        if ( $media_id < 1 ) {
            return array();
        }
        if ( $limit < 1 ) {
            $limit = 5;
        }

        if ( get_post_meta( $media_id, '_muc_trashed_at', true ) ) {
            return array();
        }

        $media_url = wp_get_attachment_url( $media_id );
        if ( ! $media_url ) {
            return array();
        }

        $media_path    = get_attached_file( $media_id );
        $upload_dir    = wp_upload_dir();
        $filename      = '';
        $relative_path = '';
        if ( $media_path ) {
            $filename      = basename( $media_path );
            $relative_path = str_replace( $upload_dir['basedir'], '', $media_path );
            $relative_path = '/' . ltrim( $relative_path, '/' );
        }

        $search_terms   = array();
        $search_terms[] = $media_url;
        $search_terms[] = str_replace( '/', '\/', $media_url );
        if ( $relative_path ) {
            $search_terms[] = ltrim( $relative_path, '/' );
        }
        $search_terms[] = 'wp-image-' . $media_id;

        $metadata = wp_get_attachment_metadata( $media_id );
        if ( isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
            $base_url = dirname( $media_url );
            $base_dir = $relative_path ? dirname( $relative_path ) : '';
            foreach ( $metadata['sizes'] as $size_info ) {
                if ( isset( $size_info['file'] ) ) {
                    $size_filename = $size_info['file'];
                    $size_url      = $base_url . '/' . $size_filename;
                    $search_terms[] = $size_url;
                    $search_terms[] = str_replace( '/', '\/', $size_url );
                    if ( $base_dir ) {
                        $search_terms[] = ltrim( $base_dir . '/' . $size_filename, '/' );
                    }
                }
            }
        }

        $search_terms = array_values( array_unique( array_filter( $search_terms ) ) );

        $evidence = array();

        if ( absint( get_option( 'site_icon' ) ) === absint( $media_id ) ) {
            $evidence[] = array(
                'source' => 'site_icon',
                'id'     => $media_id,
                'label'  => __( 'Site Icon', 'media-usage-checker' ),
                'url'    => admin_url( 'customize.php' ),
            );
            if ( count( $evidence ) >= $limit ) {
                return $evidence;
            }
        }

        $thumb_cache_key = $this->cache_key( 'thumb_post_' . $media_id );
        $thumb_post_id   = wp_cache_get( $thumb_cache_key, $this->cache_group() );
        if ( false === $thumb_post_id ) {
            $thumb_post_id = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1",
                    $media_id
                )
            );
            wp_cache_set( $thumb_cache_key, $thumb_post_id, $this->cache_group(), 300 );
        }
        if ( $thumb_post_id ) {
            $evidence[] = array(
                'source' => 'featured_image',
                'id'     => absint( $thumb_post_id ),
                'label'  => get_the_title( absint( $thumb_post_id ) ),
                'url'    => get_edit_post_link( absint( $thumb_post_id ), '' ),
            );
            if ( count( $evidence ) >= $limit ) {
                return $evidence;
            }
        }

        $pro_terms = $search_terms;
        $id_patterns = array(
            '"id":' . $media_id . ',',
            '"id":' . $media_id . '}',
            '"id":"' . $media_id . '"',
            '"id": ' . $media_id . ',',
            '"id": ' . $media_id . '}',
            'i:' . $media_id . ';',
            's:' . strlen( (string) $media_id ) . ':"' . $media_id . '";',
            'ids="' . $media_id . '"',
            'ids="' . $media_id . ',',
            ',' . $media_id . ',',
            ',' . $media_id . '"',
            'data-id="' . $media_id . '"',
            'elementor-repeater-item-' . $media_id,
        );
        $pro_terms = array_values( array_unique( array_filter( array_merge( $pro_terms, $id_patterns ) ) ) );

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s AND post_type NOT IN ('attachment', 'revision', 'auto-draft') AND post_status NOT IN ('auto-draft','trash') LIMIT 1",
                    $like
                )
            );
            if ( $found ) {
                $evidence[] = array(
                    'source' => 'post_content',
                    'id'     => absint( $found ),
                    'label'  => get_the_title( absint( $found ) ),
                    'url'    => get_edit_post_link( absint( $found ), '' ),
                );
                if ( count( $evidence ) >= $limit ) {
                    return $evidence;
                }
            }
        }

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND post_id != %d LIMIT 1",
                    $like,
                    $media_id
                )
            );
            if ( $found ) {
                $evidence[] = array(
                    'source' => 'postmeta',
                    'id'     => absint( $found ),
                    'label'  => get_the_title( absint( $found ) ),
                    'url'    => get_edit_post_link( absint( $found ), '' ),
                );
                if ( count( $evidence ) >= $limit ) {
                    return $evidence;
                }
            }
        }

        $found_exact_meta_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value = %d AND post_id != %d LIMIT 1",
                $media_id,
                $media_id
            )
        );
        if ( $found_exact_meta_id ) {
            $evidence[] = array(
                'source' => 'postmeta',
                'id'     => absint( $found_exact_meta_id ),
                'label'  => get_the_title( absint( $found_exact_meta_id ) ),
                'url'    => get_edit_post_link( absint( $found_exact_meta_id ), '' ),
            );
            if ( count( $evidence ) >= $limit ) {
                return $evidence;
            }
        }

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->usermeta} WHERE meta_value LIKE %s LIMIT 1",
                    $like
                )
            );
            if ( $found ) {
                $evidence[] = array(
                    'source' => 'usermeta',
                    'id'     => absint( $found ),
                    'label'  => sprintf( __( 'User #%d', 'media-usage-checker' ), absint( $found ) ),
                    'url'    => admin_url( 'user-edit.php?user_id=' . absint( $found ) ),
                );
                if ( count( $evidence ) >= $limit ) {
                    return $evidence;
                }
            }
        }

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_value LIKE %s LIMIT 1",
                    $like
                )
            );
            if ( $found ) {
                $evidence[] = array(
                    'source' => 'termmeta',
                    'id'     => absint( $found ),
                    'label'  => sprintf( __( 'Term #%d', 'media-usage-checker' ), absint( $found ) ),
                );
                if ( count( $evidence ) >= $limit ) {
                    return $evidence;
                }
            }
        }

        foreach ( $pro_terms as $term ) {
            $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $found = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1",
                    $like
                )
            );
            if ( $found ) {
                $evidence[] = array(
                    'source' => 'option',
                    'id'     => absint( $found ),
                    'label'  => sprintf( __( 'Option #%d', 'media-usage-checker' ), absint( $found ) ),
                );
                if ( count( $evidence ) >= $limit ) {
                    return $evidence;
                }
            }
        }

        if ( get_theme_mod( 'custom_logo' ) == $media_id ) {
            $evidence[] = array(
                'source' => 'custom_logo',
                'id'     => $media_id,
                'label'  => __( 'Custom Logo', 'media-usage-checker' ),
                'url'    => admin_url( 'customize.php' ),
            );
            if ( count( $evidence ) >= $limit ) {
                return $evidence;
            }
        }

        $header_image = get_theme_mod( 'header_image' );
        if ( $header_image && ( $header_image == $media_url || ( $filename && false !== strpos( $header_image, $filename ) ) ) ) {
            $evidence[] = array(
                'source' => 'header_image',
                'id'     => $media_id,
                'label'  => __( 'Header Image', 'media-usage-checker' ),
                'url'    => admin_url( 'customize.php' ),
            );
            if ( count( $evidence ) >= $limit ) {
                return $evidence;
            }
        }

        $background_image = get_theme_mod( 'background_image' );
        if ( $background_image && ( $background_image == $media_url || ( $filename && false !== strpos( $background_image, $filename ) ) ) ) {
            $evidence[] = array(
                'source' => 'background_image',
                'id'     => $media_id,
                'label'  => __( 'Background Image', 'media-usage-checker' ),
                'url'    => admin_url( 'customize.php' ),
            );
        }

        return $evidence;
    }

    /**
     * Ensure MUC Trash directory exists and is protected.
     * 
     * @return string|false The path to the trash directory or false on failure.
     */
    private function ensure_muc_trash_dir() {
        $upload_dir = wp_upload_dir();
        $trash_dir  = $upload_dir['basedir'] . '/muc-trash';

        $fs = $this->get_filesystem();
        if ( ! $fs || ! method_exists( $fs, 'exists' ) ) {
            return false;
        }

        if ( ! $fs->exists( $trash_dir ) ) {
            if ( method_exists( $fs, 'mkdir' ) ) {
                $mode = defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755;
                $fs->mkdir( $trash_dir, $mode );
            }

            if ( ! $fs->exists( $trash_dir ) ) {
                return false;
            }
        }

        // Create .htaccess to protect the directory
        $htaccess_file = $trash_dir . '/.htaccess';
        if ( ! $fs->exists( $htaccess_file ) ) {
            $htaccess_content = "Options -Indexes\nDeny from all\n";
            $this->write_file( $htaccess_file, $htaccess_content );
        }

        return $trash_dir;
    }

    /**
     * Get Query Args for Scanning
     */
    private function get_scan_query_args() {
        $file_types = get_option( 'muc_file_types', array( 'image', 'document', 'video', 'audio', 'archive' ) );
        if ( ! is_array( $file_types ) ) {
            $file_types = array( 'image', 'document', 'video', 'audio', 'archive' );
        }

        $file_types = array_map( 'sanitize_key', $file_types );
        $file_types = array_values( array_intersect( $file_types, array( 'image', 'document', 'video', 'audio', 'archive' ) ) );

        $mime_types = array();
        
        foreach ($file_types as $type) {
            switch ($type) {
                case 'image': $mime_types[] = 'image/%'; break;
                case 'video': $mime_types[] = 'video/%'; break;
                case 'audio': $mime_types[] = 'audio/%'; break;
                case 'document': 
                    $mime_types[] = 'application/pdf';
                    $mime_types[] = 'application/msword';
                    $mime_types[] = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                    $mime_types[] = 'application/vnd.ms-excel';
                    $mime_types[] = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
                    $mime_types[] = 'text/plain';
                    break;
                case 'archive':
                    $mime_types[] = 'application/zip';
                    $mime_types[] = 'application/x-rar-compressed';
                    $mime_types[] = 'application/x-tar';
                    break;
            }
        }

        return array(
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'fields'                 => 'ids',
            'posts_per_page'         => 20,
            'no_found_rows'          => false,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'post_mime_type'         => ! empty( $mime_types ) ? $mime_types : '',
        );
    }

    /**
     * Get Total Attachments Count to Scan
     */
    public function get_total_attachments() {
        $args = $this->get_scan_query_args();
        $args['posts_per_page'] = 1;
        $args['paged'] = 1;
        $args['fields'] = 'ids';
        $args['no_found_rows'] = false;
        $query = new WP_Query($args);
        return (int) $query->found_posts;
    }

    /**
     * Process a Batch of files
     */
    public function scan_batch($page = 1, $batch_size = 50) {
        $args = $this->get_scan_query_args();
        $args['posts_per_page'] = $batch_size;
        $args['paged'] = $page;
        $args['fields'] = 'ids';

        $query = new WP_Query($args);
        $ids = $query->posts;
        $processed = 0;

        foreach ($ids as $id) {
            $file_path = get_attached_file($id);
            $size = $file_path && function_exists( 'media_usage_checker_filesize' ) ? media_usage_checker_filesize( $file_path ) : 0;
            
            // Store size
            update_post_meta($id, '_muc_file_size', $size);
            
            // Check usage
            $in_use = $this->is_media_in_use($id);
            update_post_meta($id, '_muc_is_unused', $in_use ? '0' : '1');
            
            $processed++;
        }

        if ( $processed > 0 ) {
            $this->invalidate_cache();
        }

        return $processed;
    }

    /**
     * Finalize: Update global stats from meta values
     */
    public function calculate_stats_from_meta() {
        global $wpdb;
        $total_media = (int) $this->get_total_attachments();

        $cache_key = $this->cache_key( 'stats_used_count' );
        $used_count = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $used_count ) {
            $used_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_muc_is_unused' AND meta_value = '0'" );
            wp_cache_set( $cache_key, $used_count, $this->cache_group(), 10 );
        }

        $cache_key = $this->cache_key( 'stats_unused_count' );
        $unused_count = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $unused_count ) {
            $unused_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_muc_is_unused' AND meta_value = '1'" );
            wp_cache_set( $cache_key, $unused_count, $this->cache_group(), 10 );
        }

        $cache_key = $this->cache_key( 'stats_used_size' );
        $used_size = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $used_size ) {
            $used_size = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} WHERE meta_key = '_muc_file_size' AND post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_muc_is_unused' AND meta_value = '0')" );
            wp_cache_set( $cache_key, $used_size, $this->cache_group(), 10 );
        }

        $cache_key = $this->cache_key( 'stats_unused_size' );
        $unused_size = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $unused_size ) {
            $unused_size = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} WHERE meta_key = '_muc_file_size' AND post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_muc_is_unused' AND meta_value = '1')" );
            wp_cache_set( $cache_key, $unused_size, $this->cache_group(), 10 );
        }

        $breakdown = [
            'image' => ['count' => 0, 'size' => 0],
            'document' => ['count' => 0, 'size' => 0],
            'video' => ['count' => 0, 'size' => 0],
            'audio' => ['count' => 0, 'size' => 0],
            'archive' => ['count' => 0, 'size' => 0],
        ];

        $cache_key = $this->cache_key( 'breakdown_image_count' );
        $breakdown['image']['count'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['image']['count'] ) {
            $breakdown['image']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status='inherit' AND post_mime_type LIKE 'image/%'" );
            wp_cache_set( $cache_key, $breakdown['image']['count'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_video_count' );
        $breakdown['video']['count'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['video']['count'] ) {
            $breakdown['video']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status='inherit' AND post_mime_type LIKE 'video/%'" );
            wp_cache_set( $cache_key, $breakdown['video']['count'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_audio_count' );
        $breakdown['audio']['count'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['audio']['count'] ) {
            $breakdown['audio']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status='inherit' AND post_mime_type LIKE 'audio/%'" );
            wp_cache_set( $cache_key, $breakdown['audio']['count'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_archive_count' );
        $breakdown['archive']['count'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['archive']['count'] ) {
            $breakdown['archive']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status='inherit' AND post_mime_type IN ('application/zip','application/x-rar-compressed','application/x-tar')" );
            wp_cache_set( $cache_key, $breakdown['archive']['count'], $this->cache_group(), 30 );
        }
        $breakdown['document']['count'] = max( 0, $total_media - $breakdown['image']['count'] - $breakdown['video']['count'] - $breakdown['audio']['count'] - $breakdown['archive']['count'] );

        $cache_key = $this->cache_key( 'breakdown_image_size' );
        $breakdown['image']['size'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['image']['size'] ) {
            $breakdown['image']['size'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_muc_file_size' AND p.post_mime_type LIKE 'image/%'" );
            wp_cache_set( $cache_key, $breakdown['image']['size'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_video_size' );
        $breakdown['video']['size'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['video']['size'] ) {
            $breakdown['video']['size'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_muc_file_size' AND p.post_mime_type LIKE 'video/%'" );
            wp_cache_set( $cache_key, $breakdown['video']['size'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_audio_size' );
        $breakdown['audio']['size'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['audio']['size'] ) {
            $breakdown['audio']['size'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_muc_file_size' AND p.post_mime_type LIKE 'audio/%'" );
            wp_cache_set( $cache_key, $breakdown['audio']['size'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_archive_size' );
        $breakdown['archive']['size'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['archive']['size'] ) {
            $breakdown['archive']['size'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_muc_file_size' AND p.post_mime_type IN ('application/zip','application/x-rar-compressed','application/x-tar')" );
            wp_cache_set( $cache_key, $breakdown['archive']['size'], $this->cache_group(), 30 );
        }
        $breakdown['document']['size'] = max( 0, ( $used_size + $unused_size ) - $breakdown['image']['size'] - $breakdown['video']['size'] - $breakdown['audio']['size'] - $breakdown['archive']['size'] );

        $this->save_stats($total_media, $used_count, $unused_count, $used_size, $unused_size, $breakdown);
        
        $cache_key = $this->cache_key( 'stats_trashed_count' );
        $trashed_count = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $trashed_count ) {
            $trashed_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_muc_trashed_at'" );
            wp_cache_set( $cache_key, $trashed_count, $this->cache_group(), 10 );
        }
        update_option('muc_trashed_count', (int) $trashed_count);

        return $this->get_formatted_stats($total_media, $used_count, $unused_count, $used_size, $unused_size, $trashed_count);
    }

    private function save_stats($total, $used, $unused, $used_size, $unused_size, $breakdown) {
        update_option('muc_used_count', $used);
        update_option('muc_unused_count', $unused);
        update_option('muc_used_size', $used_size);
        update_option('muc_unused_size', $unused_size);
        update_option('muc_breakdown', $breakdown);
        update_option('muc_last_check', time());
    }

    private function get_formatted_stats($total, $used, $unused, $used_size, $unused_size, $trashed) {
        return [
            'total' => $total,
            'used' => $used,
            'unused' => $unused,
            'used_size' => size_format($used_size),
            'unused_size' => size_format($unused_size),
            'raw_used_size' => $used_size,
            'raw_unused_size' => $unused_size,
            'trashed' => $trashed
        ];
    }

    /**
     * Legacy Wrapper (Keeping it to not break external calls, but it redirects to calculation)
     */
    public function update_stats() {
        $this->invalidate_cache();
        return $this->calculate_stats_from_meta();
    }

    /**
     * Update status for a specific item
     */
    public function update_item_status($media_id) {
        $media_id = absint( $media_id );
        if ( 0 === $media_id ) {
            return false;
        }

        $file_path = get_attached_file( $media_id );
        $file_path = $file_path ? wp_normalize_path( $file_path ) : '';
        $size = (int) get_post_meta( $media_id, '_muc_file_size', true );

        if ( $this->is_pro() && $this->is_excluded_from_cleanup( $media_id, $file_path, $size ) ) {
            update_post_meta( $media_id, '_muc_is_unused', '0' );
            delete_post_meta( $media_id, '_muc_unused_since' );
            $this->invalidate_cache();
            return true;
        }

        $in_use = $this->is_media_in_use( $media_id );
        update_post_meta( $media_id, '_muc_is_unused', $in_use ? '0' : '1' );
        if ( $in_use ) {
            delete_post_meta( $media_id, '_muc_unused_since' );
        } else {
            if ( ! get_post_meta( $media_id, '_muc_unused_since', true ) ) {
                update_post_meta( $media_id, '_muc_unused_since', current_time( 'timestamp' ) );
            }
        }
        $this->invalidate_cache();
        return $in_use;
    }

    /**
     * Move to MUC Trash.
     * 
     * Moves the physical file to a protected directory.
     * 
     * @param int  $media_id     The media ID.
     * @param bool $update_stats Whether to trigger full stats update.
     * @return bool True on success.
     */
    public function move_to_trash( $media_id, $update_stats = false, $system = false ) {
        if ( ! current_user_can( 'manage_options' ) && ! ( $system && wp_doing_cron() ) ) {
            return false;
        }
        if ( ! $this->is_pro() ) {
            return false;
        }
        if ( $this->is_media_in_use( $media_id ) ) {
            return false;
        }

        $trash_dir = $this->ensure_muc_trash_dir();
        if ( ! $trash_dir ) {
            return false;
        }

        $paths = $this->get_attachment_paths( $media_id );
        if ( empty( $paths ) ) {
            return false;
        }

        $uploads = wp_upload_dir();
        $base_dir = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
        if ( empty( $base_dir ) ) {
            return false;
        }

        $trash_map = array();
        $fs = $this->get_filesystem();
        if ( ! $fs || ! method_exists( $fs, 'exists' ) ) {
            return false;
        }

        foreach ( $paths as $original_path ) {
            $original_path = wp_normalize_path( $original_path );
            if ( empty( $original_path ) || ! $fs->exists( $original_path ) ) {
                continue;
            }
            if ( 0 !== strpos( $original_path, $base_dir . '/' ) && $original_path !== $base_dir ) {
                continue;
            }

            $filename = wp_unique_filename( $trash_dir, basename( $original_path ) );
            $trash_path = trailingslashit( $trash_dir ) . $filename;

            if ( $this->move_file( $original_path, $trash_path ) ) {
                $trash_map[ $original_path ] = $trash_path;
            }
        }

        if ( empty( $trash_map ) ) {
            return false;
        }

        $primary_original = get_attached_file( $media_id );
        $primary_original = $primary_original ? wp_normalize_path( $primary_original ) : '';
        if ( $primary_original && isset( $trash_map[ $primary_original ] ) ) {
            update_post_meta( $media_id, '_muc_trash_path', $trash_map[ $primary_original ] );
            update_post_meta( $media_id, '_muc_original_path', $primary_original );
        }
        update_post_meta( $media_id, '_muc_trash_map', $trash_map );

        update_post_meta( $media_id, '_muc_trashed_at', current_time( 'mysql' ) );

        $this->invalidate_cache();

        $this->audit_log_add(
            'trash',
            $media_id,
            array(
                'size' => (int) get_post_meta( $media_id, '_muc_file_size', true ),
                'mode' => $system ? 'auto' : 'manual',
            ),
            $system
        );
        
        if ( $update_stats ) {
            $this->update_stats();
        } else {
            // Incremental update for speed
            $count = (int) get_option( 'muc_trashed_count', 0 );
            update_option( 'muc_trashed_count', $count + 1 );
            
            $unused = (int) get_option( 'muc_unused_count', 0 );
            if ( $unused > 0 ) {
                update_option( 'muc_unused_count', $unused - 1 );
            }

            // Update breakdown
            $mime = get_post_mime_type( $media_id );
            $cat  = 'document';
            if ( strpos( $mime, 'image/' ) !== false ) $cat = 'image';
            elseif ( strpos( $mime, 'video/' ) !== false ) $cat = 'video';
            elseif ( strpos( $mime, 'audio/' ) !== false ) $cat = 'audio';
            elseif ( in_array( $mime, array( 'application/zip', 'application/x-rar-compressed', 'application/x-tar' ) ) ) $cat = 'archive';

            $breakdown = get_option( 'muc_breakdown', array() );
            if ( isset( $breakdown[ $cat ] ) ) {
                $breakdown[ $cat ]['count'] = max( 0, $breakdown[ $cat ]['count'] - 1 );
                update_option( 'muc_breakdown', $breakdown );
            }
        }
        return true;
    }

    /**
     * Restore from MUC Trash.
     * 
     * Moves the file back to its original location.
     * 
     * @param int  $media_id     The media ID.
     * @param bool $update_stats Whether to trigger full stats update.
     * @return bool True on success.
     */
    public function restore_from_trash( $media_id, $update_stats = false, $system = false ) {
        if ( ! current_user_can( 'manage_options' ) && ! ( $system && wp_doing_cron() ) ) {
            return false;
        }
        if ( ! $this->is_pro() ) {
            return false;
        }
        
        $trash_map = get_post_meta( $media_id, '_muc_trash_map', true );
        $fs = $this->get_filesystem();
        if ( ! $fs || ! method_exists( $fs, 'exists' ) ) {
            return false;
        }

        $uploads = wp_upload_dir();
        $base_dir = isset( $uploads['basedir'] ) ? wp_normalize_path( $uploads['basedir'] ) : '';
        if ( empty( $base_dir ) ) {
            return false;
        }

        if ( is_array( $trash_map ) && ! empty( $trash_map ) ) {
            foreach ( $trash_map as $original_path => $trash_path ) {
                $original_path = is_string( $original_path ) ? $original_path : '';
                $trash_path    = is_string( $trash_path ) ? $trash_path : '';
                if ( empty( $original_path ) || empty( $trash_path ) ) {
                    continue;
                }
                $original_path = wp_normalize_path( $original_path );
                $trash_path    = wp_normalize_path( $trash_path );
                if ( 0 !== strpos( $original_path, $base_dir . '/' ) && $original_path !== $base_dir ) {
                    continue;
                }
                if ( ! $fs->exists( $trash_path ) ) {
                    continue;
                }

                $dir = dirname( $original_path );
                if ( method_exists( $fs, 'mkdir' ) && ! $fs->exists( $dir ) ) {
                    $mode = defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755;
                    $fs->mkdir( $dir, $mode );
                }

                $this->move_file( $trash_path, $original_path );
            }
        } else {
            $trash_path    = get_post_meta( $media_id, '_muc_trash_path', true );
            $original_path = get_post_meta( $media_id, '_muc_original_path', true );

            $trash_path    = is_string( $trash_path ) ? wp_normalize_path( $trash_path ) : '';
            $original_path = is_string( $original_path ) ? wp_normalize_path( $original_path ) : '';

            if ( $trash_path && $original_path && $fs->exists( $trash_path ) ) {
                $dir = dirname( $original_path );
                if ( method_exists( $fs, 'mkdir' ) && ! $fs->exists( $dir ) ) {
                    $mode = defined( 'FS_CHMOD_DIR' ) ? FS_CHMOD_DIR : 0755;
                    $fs->mkdir( $dir, $mode );
                }
                $this->move_file( $trash_path, $original_path );
            }
        }

        delete_post_meta( $media_id, '_muc_trash_map' );
        delete_post_meta( $media_id, '_muc_trash_path' );
        delete_post_meta( $media_id, '_muc_original_path' );

        delete_post_meta( $media_id, '_muc_trashed_at' );

        $this->invalidate_cache();

        $this->audit_log_add(
            'restore',
            $media_id,
            array(
                'size' => (int) get_post_meta( $media_id, '_muc_file_size', true ),
                'mode' => $system ? 'auto' : 'manual',
            ),
            $system
        );
        
        if ( $update_stats ) {
            $this->update_stats();
        } else {
            // Incremental update for speed
            $count = (int) get_option( 'muc_trashed_count', 0 );
            if ( $count > 0 ) {
                update_option( 'muc_trashed_count', $count - 1 );
            }

            // Update breakdown (restored items are technically 'unused' again until a full scan)
            $mime = get_post_mime_type( $media_id );
            $cat  = 'document';
            if ( strpos( $mime, 'image/' ) !== false ) $cat = 'image';
            elseif ( strpos( $mime, 'video/' ) !== false ) $cat = 'video';
            elseif ( strpos( $mime, 'audio/' ) !== false ) $cat = 'audio';
            elseif ( in_array( $mime, array( 'application/zip', 'application/x-rar-compressed', 'application/x-tar' ) ) ) $cat = 'archive';

            $breakdown = get_option( 'muc_breakdown', array() );
            if ( isset( $breakdown[ $cat ] ) ) {
                $breakdown[ $cat ]['count']++;
                update_option( 'muc_breakdown', $breakdown );
            }
        }
        return true;
    }

    /**
     * Permanent Delete.
     * 
     * Deletes the file from MUC Trash and removes the attachment from WordPress.
     * 
     * @param int  $media_id     The media ID.
     * @param bool $update_stats Whether to trigger full stats update.
     * @return bool True on success.
     */
    public function delete_permanently( $media_id, $update_stats = false, $system = false ) {
        if ( ! current_user_can( 'manage_options' ) && ! ( $system && wp_doing_cron() ) ) {
            return false;
        }

        $media_id = absint( $media_id );
        if ( 0 === $media_id ) {
            return false;
        }
        if ( $this->is_media_in_use( $media_id ) ) {
            return false;
        }

        $trash_path = get_post_meta( $media_id, '_muc_trash_path', true );
        $size = (int) get_post_meta( $media_id, '_muc_file_size', true );
        $mime = get_post_mime_type( $media_id );
        $is_unused = ( '1' === get_post_meta( $media_id, '_muc_is_unused', true ) );
        $was_trashed = ! empty( get_post_meta( $media_id, '_muc_trashed_at', true ) );

        $paths = $this->get_attachment_paths( $media_id );
        if ( ! empty( $trash_path ) ) {
            $paths[] = $trash_path;
        }
        $trash_map = get_post_meta( $media_id, '_muc_trash_map', true );
        if ( is_array( $trash_map ) && ! empty( $trash_map ) ) {
            $paths = array_merge( $paths, array_values( $trash_map ) );
        }

        $result = wp_delete_attachment( $media_id, true );
        if ( ! $result ) {
            $result = wp_delete_post( $media_id, true );
        }

        if ( $result ) {
            $paths = array_unique( array_filter( $paths ) );
            foreach ( $paths as $path ) {
                $this->delete_local_file( $path );
                $this->clean_empty_parent_dirs( dirname( $path ) );
            }

            $this->invalidate_cache();

            $this->audit_log_add(
                'delete',
                $media_id,
                array(
                    'size' => (int) $size,
                    'mode' => $system ? 'auto' : 'manual',
                ),
                $system
            );

            $trashed = (int) get_option( 'muc_trashed_count', 0 );
            if ( $was_trashed && $trashed > 0 ) {
                update_option( 'muc_trashed_count', $trashed - 1 );
            }
            
            $unused_size = (int) get_option( 'muc_unused_size', 0 );
            if ( $is_unused && $unused_size > 0 && $size > 0 ) {
                update_option( 'muc_unused_size', $unused_size - (int) $size );
            }

            $cat = 'document';
            if ( strpos( $mime, 'image/' ) !== false ) $cat = 'image';
            elseif ( strpos( $mime, 'video/' ) !== false ) $cat = 'video';
            elseif ( strpos( $mime, 'audio/' ) !== false ) $cat = 'audio';
            elseif ( in_array( $mime, array( 'application/zip', 'application/x-rar-compressed', 'application/x-tar' ) ) ) $cat = 'archive';

            $breakdown = get_option( 'muc_breakdown', array() );
            if ( isset( $breakdown[ $cat ] ) ) {
                $breakdown[ $cat ]['size'] = max( 0, $breakdown[ $cat ]['size'] - (int) $size );
                update_option( 'muc_breakdown', $breakdown );
            }
        }
        
        if ( $update_stats ) {
            $this->update_stats();
        }

        return $result;
    }

    private function get_attachment_paths( $media_id ) {
        $paths = array();
        $file_path = get_attached_file( $media_id );
        if ( ! empty( $file_path ) ) {
            $paths[] = $file_path;
        }

        $metadata = wp_get_attachment_metadata( $media_id );
        if ( is_array( $metadata ) && ! empty( $file_path ) ) {
            $base_dir = dirname( $file_path );

            if ( ! empty( $metadata['original_image'] ) ) {
                $paths[] = trailingslashit( $base_dir ) . $metadata['original_image'];
            }

            if ( ! empty( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ) {
                foreach ( $metadata['sizes'] as $size_data ) {
                    if ( ! empty( $size_data['file'] ) ) {
                        $paths[] = trailingslashit( $base_dir ) . $size_data['file'];
                    }
                }
            }
        }

        return array_unique( array_filter( $paths ) );
    }

    private function delete_local_file( $path ) {
        if ( empty( $path ) || ! is_string( $path ) ) {
            return false;
        }

        $fs = $this->get_filesystem();
        if ( ! $fs || ! method_exists( $fs, 'exists' ) ) {
            return false;
        }

        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return false;
        }

        $base_dir = wp_normalize_path( $uploads['basedir'] );
        $path = wp_normalize_path( $path );

        if ( 0 !== strpos( $path, $base_dir . '/' ) && $path !== $base_dir ) {
            return false;
        }

        if ( $fs->exists( $path ) ) {
            return $this->delete_file( $path );
        }

        return true;
    }

    /**
     * Recursively delete empty parent directories up to the uploads base dir
     */
    private function clean_empty_parent_dirs($dir) {
        $uploads = wp_upload_dir();
        if ( empty( $uploads['basedir'] ) ) {
            return;
        }

        $fs = $this->get_filesystem();
        if ( ! $fs || ! method_exists( $fs, 'exists' ) ) {
            return;
        }

        $base_dir = wp_normalize_path( untrailingslashit( $uploads['basedir'] ) );
        $dir      = wp_normalize_path( untrailingslashit( (string) $dir ) );

        // Safety check: Don't go above the uploads directory
        if ( empty( $dir ) || $dir === $base_dir || 0 !== strpos( $dir, $base_dir . '/' ) ) {
            return;
        }

        $files = $this->list_dir( $dir );
        if ( empty( $files ) ) {
            if ( method_exists( $fs, 'rmdir' ) ) {
                $fs->rmdir( $dir );
            }
            if ( $fs->exists( $dir ) ) {
                return;
            }

            if ( method_exists( $fs, 'exists' ) ) {
                $this->clean_empty_parent_dirs( dirname( $dir ) );
            }
        }
    }

    /**
     * Get Trashed Items
     */
    public function get_trashed_items($per_page = 20, $page = 1) {
        if ( ! $this->is_pro() ) {
            return [];
        }
        global $wpdb;
        $offset = ($page - 1) * $per_page;
        
        $cache_key = $this->cache_key( 'trashed_ids_' . absint( $per_page ) . '_' . absint( $page ) );
        $ids = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $ids ) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_muc_trashed_at' ORDER BY meta_value DESC LIMIT %d OFFSET %d",
                    $per_page,
                    $offset
                )
            );
            $ids = is_array( $ids ) ? $ids : array();
            wp_cache_set( $cache_key, $ids, $this->cache_group(), 30 );
        }

        if (empty($ids)) return [];

        return new WP_Query([
            'post_type' => 'attachment',
            'post_status' => 'any',
            'post__in' => $ids,
            'orderby' => 'post__in'
        ]);
    }

    public function cron_auto_trash_unused( $limit = 50, $min_days_unused = 0 ) {
        if ( ! $this->is_pro() ) {
            return 0;
        }

        $limit = absint( $limit );
        if ( $limit < 1 ) {
            $limit = 50;
        }

        $meta_query = array(
            'relation' => 'AND',
            array(
                'key'     => '_muc_is_unused',
                'value'   => '1',
                'compare' => '=',
            ),
            array(
                'key'     => '_muc_trashed_at',
                'compare' => 'NOT EXISTS',
            ),
        );

        $min_days_unused = absint( $min_days_unused );
        if ( $min_days_unused > 0 ) {
            $threshold = current_time( 'timestamp' ) - ( $min_days_unused * DAY_IN_SECONDS );
            $meta_query[] = array(
                'key'     => '_muc_unused_since',
                'value'   => (string) absint( $threshold ),
                'compare' => '<=',
                'type'    => 'NUMERIC',
            );
        }

        $ids = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'fields'         => 'ids',
                'posts_per_page' => $limit,
                'no_found_rows'  => true,
                'meta_query'     => $meta_query,
            )
        );

        $count = 0;
        foreach ( $ids as $id ) {
            $id = absint( $id );
            if ( $id < 1 ) {
                continue;
            }
            if ( $this->move_to_trash( $id, false, true ) ) {
                $count++;
            }
        }

        if ( $count > 0 ) {
            $this->update_stats();
        }

        return $count;
    }

    public function cron_purge_trash_older_than( $days, $limit = 50 ) {
        if ( ! $this->is_pro() ) {
            return 0;
        }

        $days  = absint( $days );
        $limit = absint( $limit );
        if ( $days < 1 ) {
            return 0;
        }
        if ( $limit < 1 ) {
            $limit = 50;
        }

        $ts = current_time( 'timestamp' ) - ( $days * DAY_IN_SECONDS );
        $cutoff = function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s', $ts ) : gmdate( 'Y-m-d H:i:s', $ts );

        $ids = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => $limit,
                'no_found_rows'  => true,
                'meta_query'     => array(
                    array(
                        'key'     => '_muc_trashed_at',
                        'value'   => $cutoff,
                        'compare' => '<',
                        'type'    => 'DATETIME',
                    ),
                ),
            )
        );

        $count = 0;
        foreach ( $ids as $id ) {
            $id = absint( $id );
            if ( $id < 1 ) {
                continue;
            }
            if ( $this->delete_permanently( $id, false, true ) ) {
                $count++;
            }
        }

        if ( $count > 0 ) {
            $this->update_stats();
        }

        return $count;
    }
}

/**
 * Global helper for backward compatibility
 */
function media_usage_checker_is_media_in_use($media_id) {
    return Media_Usage_Checker_Scanner::get_instance()->is_media_in_use($media_id);
}
