<?php
/**
 * Media Usage Checker Scanner
 * 
 * @package Oliverodev_Media_Audit
 */

if (!defined('ABSPATH')) {
    exit;
}

class Oliverodev_Media_Audit_Scanner {
    private static $instance = null;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    private function cache_group() {
        return 'oliverodev-media-audit';
    }

    private function get_cache_salt() {
        $salt = get_option( 'oliverodev_media_audit_cache_salt', 0 );
        $salt = absint( $salt );

        if ( 0 === $salt ) {
            $salt = time();
            add_option( 'oliverodev_media_audit_cache_salt', $salt, '', 'no' );
        }

        return $salt;
    }

    private function bump_cache_salt() {
        $salt = time();
        if ( false === get_option( 'oliverodev_media_audit_cache_salt', false ) ) {
            add_option( 'oliverodev_media_audit_cache_salt', $salt, '', 'no' );
        } else {
            update_option( 'oliverodev_media_audit_cache_salt', $salt, false );
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

    private function get_filesystem() {
        global $wp_filesystem;
        if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
            return $wp_filesystem;
        }

        if ( defined( 'ABSPATH' ) && ! function_exists( 'WP_Filesystem' ) ) {
            require_once includes( 'file.php' );
        }

        if ( function_exists( 'WP_Filesystem' ) ) {
            WP_Filesystem();
        }

        if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
            return $wp_filesystem;
        }

        if ( defined( 'ABSPATH' ) && ! class_exists( 'WP_Filesystem_Direct' ) ) {
            require_once includes( 'class-wp-filesystem-base.php' );
            require_once includes( 'class-wp-filesystem-direct.php' );
        }

        if ( class_exists( 'WP_Filesystem_Direct' ) ) {
            return new WP_Filesystem_Direct( null );
        }

        return null;
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
        $search_terms[] = urlencode( $media_url ); // URL encoded variant
        if ( $relative_path ) {
            $search_terms[] = ltrim( $relative_path, '/' );
            $search_terms[] = urlencode( ltrim( $relative_path, '/' ) );
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

        // Patterns safe for post_content only: JSON block attributes and HTML data attributes.
        // These are NOT used against meta/options tables to avoid false positives from
        // serialized integers, counters, or unrelated numeric values stored there.
        $id_patterns = array(
            '"id":' . $media_id . ',',
            '"id":' . $media_id . '}',
            '"id":"' . $media_id . '"',
            '"id": ' . $media_id . ',',
            '"id": ' . $media_id . '}',
            'ids="' . $media_id . '"',
            'ids="' . $media_id . ',',
            'data-id="' . $media_id . '"',
            'elementor-repeater-item-' . $media_id,
        );

        // Search post_content with both URL terms and JSON/HTML id patterns.
        $post_content_terms = array_values( array_unique( array_filter( array_merge( $search_terms, $id_patterns ) ) ) );
        foreach ( $post_content_terms as $term ) {
            $like      = '%' . $wpdb->esc_like( (string) $term ) . '%';
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

        // Search postmeta with URL terms only — id_patterns cause false positives against
        // serialized data storing unrelated integers (e.g. _edit_last, counts, term IDs).
        foreach ( $search_terms as $term ) {
            $like      = '%' . $wpdb->esc_like( (string) $term ) . '%';
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

        // Search usermeta, termmeta, and options with URL terms only for the same reason.
        foreach ( $search_terms as $term ) {
            $like      = '%' . $wpdb->esc_like( (string) $term ) . '%';
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

        foreach ( $search_terms as $term ) {
            $like      = '%' . $wpdb->esc_like( (string) $term ) . '%';
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

        foreach ( $search_terms as $term ) {
            $like      = '%' . $wpdb->esc_like( (string) $term ) . '%';
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

        // Custom Tables Checks (e.g. Slider Revolution)
        $revslider_table = $wpdb->prefix . 'revslider_slides';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $revslider_table ) ) === $revslider_table ) {
            foreach ( $terms as $term ) {
                $like = '%' . $wpdb->esc_like( (string) $term ) . '%';
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT id FROM {$revslider_table} WHERE params LIKE %s OR layers LIKE %s LIMIT 1",
                        $like, $like
                    )
                );
                if ( $found ) {
                    return true;
                }
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

        return apply_filters( 'oliverodev_media_audit_is_media_used', false, $media_id, $filename );
    }

    /**
     * Get Query Args for Scanning
     */
    private function get_scan_query_args() {
        $file_types = get_option( 'oliverodev_media_audit_file_types', array( 'image', 'document', 'video', 'audio', 'archive' ) );
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

        $args = array(
            'post_type'              => 'attachment',
            'post_status'            => array('inherit', 'publish', 'private'),
            'fields'                 => 'ids',
            'posts_per_page'         => 20,
            'no_found_rows'          => false,
            'update_post_term_cache' => false,
            'update_post_meta_cache' => false,
            'post_mime_type'         => ! empty( $mime_types ) ? $mime_types : '',
        );

        return apply_filters( 'oliverodev_media_audit_scan_query_args', $args );
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
            if ( apply_filters( 'oliverodev_media_audit_skip_attachment', false, $id ) ) {
                continue;
            }
            $file_path = get_attached_file($id);
            $size = $file_path ? oliverodev_media_audit_filesize( $file_path ) : 0;
            
            // Store size
            update_post_meta($id, '_oliverodev_media_audit_file_size', $size);
            
            // Check usage
            $in_use = $this->is_media_in_use($id);
            update_post_meta($id, '_oliverodev_media_audit_is_unused', $in_use ? '0' : '1');
            
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
            $used_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key = '_oliverodev_media_audit_is_unused' AND pm.meta_value = '0' AND p.post_type='attachment' AND p.post_status NOT IN ('trash', 'auto-draft')" );
            wp_cache_set( $cache_key, $used_count, $this->cache_group(), 10 );
        }

        $cache_key = $this->cache_key( 'stats_unused_count' );
        $unused_count = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $unused_count ) {
            $unused_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key = '_oliverodev_media_audit_is_unused' AND pm.meta_value = '1' AND p.post_type='attachment' AND p.post_status NOT IN ('trash', 'auto-draft')" );
            wp_cache_set( $cache_key, $unused_count, $this->cache_group(), 10 );
        }

        $cache_key = $this->cache_key( 'stats_used_size' );
        $used_size = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $used_size ) {
            $used_size = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key = '_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash', 'auto-draft') AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_oliverodev_media_audit_is_unused' AND meta_value = '0')" );
            wp_cache_set( $cache_key, $used_size, $this->cache_group(), 10 );
        }

        $cache_key = $this->cache_key( 'stats_unused_size' );
        $unused_size = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $unused_size ) {
            $unused_size = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key = '_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash', 'auto-draft') AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_oliverodev_media_audit_is_unused' AND meta_value = '1')" );
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
            $breakdown['image']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status NOT IN ('trash', 'auto-draft') AND post_mime_type LIKE 'image/%'" );
            wp_cache_set( $cache_key, $breakdown['image']['count'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_video_count' );
        $breakdown['video']['count'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['video']['count'] ) {
            $breakdown['video']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status NOT IN ('trash', 'auto-draft') AND post_mime_type LIKE 'video/%'" );
            wp_cache_set( $cache_key, $breakdown['video']['count'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_audio_count' );
        $breakdown['audio']['count'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['audio']['count'] ) {
            $breakdown['audio']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status NOT IN ('trash', 'auto-draft') AND post_mime_type LIKE 'audio/%'" );
            wp_cache_set( $cache_key, $breakdown['audio']['count'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_archive_count' );
        $breakdown['archive']['count'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['archive']['count'] ) {
            $breakdown['archive']['count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status NOT IN ('trash', 'auto-draft') AND post_mime_type IN ('application/zip','application/x-rar-compressed','application/x-tar')" );
            wp_cache_set( $cache_key, $breakdown['archive']['count'], $this->cache_group(), 30 );
        }
        $breakdown['document']['count'] = max( 0, $total_media - $breakdown['image']['count'] - $breakdown['video']['count'] - $breakdown['audio']['count'] - $breakdown['archive']['count'] );

        $cache_key = $this->cache_key( 'breakdown_image_size' );
        $breakdown['image']['size'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['image']['size'] ) {
            $breakdown['image']['size'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash', 'auto-draft') AND p.post_mime_type LIKE 'image/%'" );
            wp_cache_set( $cache_key, $breakdown['image']['size'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_video_size' );
        $breakdown['video']['size'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['video']['size'] ) {
            $breakdown['video']['size'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash', 'auto-draft') AND p.post_mime_type LIKE 'video/%'" );
            wp_cache_set( $cache_key, $breakdown['video']['size'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_audio_size' );
        $breakdown['audio']['size'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['audio']['size'] ) {
            $breakdown['audio']['size'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash', 'auto-draft') AND p.post_mime_type LIKE 'audio/%'" );
            wp_cache_set( $cache_key, $breakdown['audio']['size'], $this->cache_group(), 30 );
        }

        $cache_key = $this->cache_key( 'breakdown_archive_size' );
        $breakdown['archive']['size'] = wp_cache_get( $cache_key, $this->cache_group() );
        if ( false === $breakdown['archive']['size'] ) {
            $breakdown['archive']['size'] = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash', 'auto-draft') AND p.post_mime_type IN ('application/zip','application/x-rar-compressed','application/x-tar')" );
            wp_cache_set( $cache_key, $breakdown['archive']['size'], $this->cache_group(), 30 );
        }
        $breakdown['document']['size'] = max( 0, ( $used_size + $unused_size ) - $breakdown['image']['size'] - $breakdown['video']['size'] - $breakdown['audio']['size'] - $breakdown['archive']['size'] );

        $this->save_stats($total_media, $used_count, $unused_count, $used_size, $unused_size, $breakdown);

        return $this->get_formatted_stats($total_media, $used_count, $unused_count, $used_size, $unused_size);
    }

    private function save_stats($total, $used, $unused, $used_size, $unused_size, $breakdown) {
        update_option('oliverodev_media_audit_used_count', $used);
        update_option('oliverodev_media_audit_unused_count', $unused);
        update_option('oliverodev_media_audit_used_size', $used_size);
        update_option('oliverodev_media_audit_unused_size', $unused_size);
        update_option('oliverodev_media_audit_breakdown', $breakdown);
        update_option('oliverodev_media_audit_last_check', time());
    }

    private function get_formatted_stats($total, $used, $unused, $used_size, $unused_size) {
        return [
            'total' => $total,
            'used' => $used,
            'unused' => $unused,
            'used_size' => size_format($used_size),
            'unused_size' => size_format($unused_size),
            'raw_used_size' => $used_size,
            'raw_unused_size' => $unused_size,
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

        $in_use = $this->is_media_in_use( $media_id );
        update_post_meta( $media_id, '_oliverodev_media_audit_is_unused', $in_use ? '0' : '1' );
        $this->invalidate_cache();
        return $in_use;
    }

    /**
     * Permanent Delete.
     *
     * Removes the attachment from WordPress.
     * 
     * @param int  $media_id     The media ID.
     * @param bool $update_stats Whether to trigger full stats update.
     * @return bool True on success.
     */
    public function delete_permanently( $media_id, $update_stats = false ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $media_id = absint( $media_id );
        if ( 0 === $media_id ) {
            return false;
        }
        if ( $this->is_media_in_use( $media_id ) ) {
            return false;
        }

        $size = (int) get_post_meta( $media_id, '_oliverodev_media_audit_file_size', true );
        $mime = get_post_mime_type( $media_id );
        $is_unused = ( '1' === get_post_meta( $media_id, '_oliverodev_media_audit_is_unused', true ) );

        $paths = $this->get_attachment_paths( $media_id );

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
            
            $unused_size = (int) get_option( 'oliverodev_media_audit_unused_size', 0 );
            if ( $is_unused && $unused_size > 0 && $size > 0 ) {
                update_option( 'oliverodev_media_audit_unused_size', $unused_size - (int) $size );
            }

            $cat = 'document';
            if ( strpos( $mime, 'image/' ) !== false ) $cat = 'image';
            elseif ( strpos( $mime, 'video/' ) !== false ) $cat = 'video';
            elseif ( strpos( $mime, 'audio/' ) !== false ) $cat = 'audio';
            elseif ( in_array( $mime, array( 'application/zip', 'application/x-rar-compressed', 'application/x-tar' ) ) ) $cat = 'archive';

            $breakdown = get_option( 'oliverodev_media_audit_breakdown', array() );
            if ( isset( $breakdown[ $cat ] ) ) {
                $breakdown[ $cat ]['size'] = max( 0, $breakdown[ $cat ]['size'] - (int) $size );
                update_option( 'oliverodev_media_audit_breakdown', $breakdown );
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

}

/**
 * Global helper for backward compatibility
 */
function oliverodev_media_audit_is_media_in_use($media_id) {
    return Oliverodev_Media_Audit_Scanner::get_instance()->is_media_in_use($media_id);
}
