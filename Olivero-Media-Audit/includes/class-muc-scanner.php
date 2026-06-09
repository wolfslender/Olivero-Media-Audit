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
    /**
     * Check if media URL is referenced in CSS background-image properties.
     * Searches in post_content (inline styles) and postmeta (custom CSS fields).
     *
     * @param string $media_url The full URL of the media file.
     * @param string $filename  The filename of the media file.
     * @return bool True if found in CSS backgrounds, false otherwise.
     */
    /**
     * Decompress Elementor data — handles raw gzip and base64-encoded gzip.
     * Returns the original string unchanged if it is not compressed.
     */
    private function elementor_decompress( $raw ) {
        if ( ! is_string( $raw ) || '' === $raw ) {
            return '';
        }
        // Raw gzip: magic bytes \x1f\x8b
        if ( "\x1f\x8b" === substr( $raw, 0, 2 ) ) {
            $decoded = @gzdecode( $raw );
            return ( false !== $decoded ) ? (string) $decoded : '';
        }
        // Base64-encoded gzip (some Elementor builds)
        $maybe = @base64_decode( $raw, true );
        if ( false !== $maybe && strlen( $maybe ) > 2 && "\x1f\x8b" === substr( $maybe, 0, 2 ) ) {
            $decoded = @gzdecode( $maybe );
            return ( false !== $decoded ) ? (string) $decoded : '';
        }
        return $raw;
    }

    private function check_css_background_usage( $media_url, $filename ) {
        global $wpdb;

        if ( ! $media_url && ! $filename ) {
            return false;
        }

        // CSS background patterns to search for
        $css_patterns = array(
            'background-image',
            'background:',
            'background-image:',
        );

        // Build searchable terms
        $search_terms = array();
        if ( $media_url ) {
            $search_terms[] = 'url(' . $media_url;
            $search_terms[] = 'url( ' . $media_url;
            $search_terms[] = 'url("' . $media_url;
            $search_terms[] = "url('" . $media_url;
        }

        if ( $filename ) {
            $search_terms[] = 'url(' . $filename;
            $search_terms[] = 'url( ' . $filename;
            $search_terms[] = 'url("' . $filename;
            $search_terms[] = "url('" . $filename;
        }

        $search_terms = array_filter( array_unique( $search_terms ) );
        if ( empty( $search_terms ) ) {
            return false;
        }

        // Search in post_content for inline styles
        foreach ( $search_terms as $term ) {
            $like      = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $cache_key = $this->cache_key( 'css_bg_' . md5( (string) $term ) );
            $found     = wp_cache_get( $cache_key, $this->cache_group() );

            if ( false === $found ) {
                // Look for background-image in post_content
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE (post_content LIKE %s OR post_content LIKE %s) AND post_type NOT IN ('attachment', 'revision', 'auto-draft') AND post_status NOT IN ('auto-draft','trash') LIMIT 1",
                        '%background%' . $wpdb->esc_like( (string) $term ) . '%',
                        '%style%' . $wpdb->esc_like( (string) $term ) . '%'
                    )
                );
                wp_cache_set( $cache_key, $found, $this->cache_group(), 300 );
            }

            if ( $found ) {
                return true;
            }
        }

        // Search in postmeta for custom CSS fields (ACF, custom fields, etc)
        foreach ( $search_terms as $term ) {
            $like      = '%' . $wpdb->esc_like( (string) $term ) . '%';
            $cache_key = $this->cache_key( 'css_bg_meta_' . md5( (string) $term ) );
            $found     = wp_cache_get( $cache_key, $this->cache_group() );

            if ( false === $found ) {
                $found = $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 1",
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
    }

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
            // Gallery shortcode: [gallery ids="1,2,3"] — all positions (first, middle, last)
            'ids="' . $media_id . '"',
            'ids="' . $media_id . ',',
            ',' . $media_id . '"',
            ',' . $media_id . ',',
            // [gallery include="1,2,3"] variant
            'include="' . $media_id . '"',
            'include="' . $media_id . ',',
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
            foreach ( $search_terms as $term ) {
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

        // Check for CSS background-image references (inline styles, parallax backgrounds, etc)
        if ( $this->check_css_background_usage( $media_url, $filename ) ) {
            return true;
        }

        // ── Elementor detection ───────────────────────────────────────────────
        // Three-layer strategy that survives URL mismatches (http/https, CDN),
        // gzip-compressed data (Elementor 3.7+), and base64-encoded gzip variants.
        if ( defined( 'ELEMENTOR_VERSION' ) ) {

            // Layer 1: ID-based search in uncompressed _elementor_data.
            // More reliable than URL: survives protocol changes and CDN rewrites.
            // Covers both integer ("id":123) and string ("id":"123") formats.
            $el_id_cache = $this->cache_key( 'el_id_' . absint( $media_id ) );
            $el_id_found = wp_cache_get( $el_id_cache, $this->cache_group() );
            if ( false === $el_id_found ) {
                $el_id_found = null;
                $id_patterns = array(
                    '%"id":' . $media_id . ',%',
                    '%"id":' . $media_id . '}%',
                    '%"id": ' . $media_id . ',%',
                    '%"id": ' . $media_id . '}%',
                    '%"id":"' . $media_id . '"%',
                    '%"id": "' . $media_id . '"%',
                );
                foreach ( $id_patterns as $pattern ) {
                    $el_id_found = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta}
                             WHERE meta_key = '_elementor_data'
                             AND meta_value LIKE %s LIMIT 1",
                            $pattern
                        )
                    );
                    if ( $el_id_found ) {
                        break;
                    }
                }
                wp_cache_set( $el_id_cache, $el_id_found, $this->cache_group(), 300 );
            }
            if ( $el_id_found ) {
                return true;
            }

            // Layer 2: Generated post CSS (_elementor_css) — always plaintext,
            // contains background-image:url("...") even when _elementor_data is compressed.
            if ( $media_url ) {
                $like      = '%' . $wpdb->esc_like( $media_url ) . '%';
                $cache_key = $this->cache_key( 'el_css_' . absint( $media_id ) );
                $found     = wp_cache_get( $cache_key, $this->cache_group() );
                if ( false === $found ) {
                    $found = $wpdb->get_var(
                        $wpdb->prepare(
                            "SELECT post_id FROM {$wpdb->postmeta}
                             WHERE meta_key = '_elementor_css'
                             AND meta_value LIKE %s LIMIT 1",
                            $like
                        )
                    );
                    wp_cache_set( $cache_key, $found, $this->cache_group(), 300 );
                }
                if ( $found ) {
                    return true;
                }
            }

            // Layer 3: PHP-side decompression for compressed _elementor_data.
            // Handles raw gzip (\x1f\x8b) and base64-encoded gzip variants.
            $compressed_ids = get_transient( 'omau_el_compressed_ids' );
            if ( false === $compressed_ids ) {
                $compressed_ids = $wpdb->get_col(
                    "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
                     WHERE meta_key = '_elementor_data'
                       AND meta_value NOT LIKE '[%'
                       AND meta_value NOT LIKE '{%'
                     LIMIT 500"
                );
                set_transient( 'omau_el_compressed_ids', $compressed_ids ? $compressed_ids : array(), HOUR_IN_SECONDS );
            }
            if ( ! empty( $compressed_ids ) ) {
                $comp_cache_key = $this->cache_key( 'el_comp_' . absint( $media_id ) );
                $comp_found     = wp_cache_get( $comp_cache_key, $this->cache_group() );
                if ( false === $comp_found ) {
                    $comp_found = '0';
                    foreach ( (array) $compressed_ids as $pid ) {
                        $raw = get_post_meta( absint( $pid ), '_elementor_data', true );
                        if ( ! is_string( $raw ) || '' === $raw ) {
                            continue;
                        }
                        $raw = $this->elementor_decompress( $raw );
                        if ( '' === $raw ) {
                            continue;
                        }
                        if ( false !== strpos( $raw, '"id":' . $media_id )
                            || false !== strpos( $raw, '"id": ' . $media_id )
                            || false !== strpos( $raw, '"id":"' . $media_id . '"' )
                            || ( $media_url && false !== strpos( $raw, $media_url ) )
                            || ( $filename && false !== strpos( $raw, $filename ) )
                        ) {
                            $comp_found = '1';
                            break;
                        }
                    }
                    wp_cache_set( $comp_cache_key, $comp_found, $this->cache_group(), 300 );
                }
                if ( '1' === $comp_found ) {
                    return true;
                }
            }
        }

        // ── Elementor atomic CSS files (Flexbox Container / e-con, v3.6+) ──────
        // Elementor writes per-page CSS to uploads/elementor/css/post-{id}.css.
        // Flexbox Container (e-con) backgrounds use Elementor's atomic CSS — the
        // background-image CSS lives ONLY in disk files, never in the database.
        // Strategy: scan ALL *.css files without a post-ID filter (avoids stale
        // ID lists), search by multiple URL variants to survive http/https changes,
        // CDN rewrites, and scaled-image filename differences.
        if ( defined( 'ELEMENTOR_VERSION' ) && ( $media_url || $filename ) ) {
            $upload_dir = wp_upload_dir();
            $el_dir     = trailingslashit( $upload_dir['basedir'] ) . 'elementor/css/';

            if ( is_dir( $el_dir ) ) {
                // Build needle list: full URL, protocol-relative, path-only, filename.
                $el_needles = array();
                if ( $media_url ) {
                    $el_needles[] = $media_url;
                    $el_needles[] = preg_replace( '#^https?:#', '', $media_url );
                    $parsed_path  = wp_parse_url( $media_url, PHP_URL_PATH );
                    if ( $parsed_path ) {
                        $el_needles[] = $parsed_path;
                    }
                }
                if ( $filename ) {
                    $el_needles[] = $filename;
                }
                $el_needles = array_values( array_unique( array_filter( $el_needles ) ) );

                // Per-media result: object cache only (clears each PHP request — no
                // cross-scan stale values from persistent transients).
                $el_hit_key = $this->cache_key( 'el_css_' . absint( $media_id ) );
                $el_hit     = wp_cache_get( $el_hit_key, $this->cache_group() );
                if ( false === $el_hit ) {
                    $el_hit = '0';

                    // File list: 1-minute transient so glob() doesn't run every call.
                    $el_files = get_transient( 'omau_el_css_list' );
                    if ( false === $el_files ) {
                        $el_files = glob( $el_dir . '*.css' ) ?: array();
                        set_transient( 'omau_el_css_list', $el_files, MINUTE_IN_SECONDS );
                    }

                    foreach ( (array) $el_files as $f ) {
                        // File contents: 2-minute object cache per file path.
                        $fc_key  = $this->cache_key( 'elfc_' . md5( $f ) );
                        $content = wp_cache_get( $fc_key, $this->cache_group() );
                        if ( false === $content ) {
                            $content = @file_get_contents( $f );
                            $content = ( false !== $content ) ? $content : '';
                            wp_cache_set( $fc_key, $content, $this->cache_group(), 120 );
                        }
                        if ( '' === $content ) {
                            continue;
                        }
                        foreach ( $el_needles as $needle ) {
                            if ( false !== strpos( $content, $needle ) ) {
                                $el_hit = '1';
                                break 2;
                            }
                        }
                    }

                    wp_cache_set( $el_hit_key, $el_hit, $this->cache_group(), 60 );
                }
                if ( '1' === $el_hit ) {
                    return true;
                }
            }
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
     * Returns the wall-clock budget in seconds that a single batch request may use.
     * Derived from PHP's max_execution_time: we use at most 50 % of the limit,
     * clamped to [3, 20] so the server always has headroom to finish the request.
     * When the limit is 0 / -1 (CLI or unlimited) we default to 15 s.
     */
    private function get_safe_time_budget() {
        $limit = (int) ini_get( 'max_execution_time' );
        if ( $limit <= 0 ) {
            return 15;
        }
        return min( 20, max( 3, (int) floor( $limit * 0.50 ) ) );
    }

    /**
     * Returns the PHP memory limit in bytes.
     * Returns PHP_INT_MAX when the limit is set to -1 (unlimited).
     */
    private function get_memory_limit_bytes() {
        $raw = (string) ini_get( 'memory_limit' );
        if ( '-1' === $raw ) {
            return PHP_INT_MAX;
        }
        $unit  = strtolower( substr( $raw, -1 ) );
        $value = (int) $raw;
        switch ( $unit ) {
            case 'g':
                $value *= 1024;
                // fall through
            case 'm':
                $value *= 1024;
                // fall through
            case 'k':
                $value *= 1024;
        }
        return max( 1, $value );
    }

    /**
     * Process a batch of attachments starting at $offset.
     *
     * Uses offset-based pagination so that the batch size can change between
     * requests without causing items to be skipped or double-scanned.
     *
     * Returns an array with:
     *   'processed'            => (int) items actually scanned this call
     *   'suggested_batch_size' => (int) recommended size for the NEXT request,
     *                            derived from measured throughput on this server
     *
     * The suggested size grows or shrinks automatically, making the scan safe on
     * shared PHP 7.4 / 32 MB hosts AND fast on well-provisioned servers.
     *
     * @param int $offset     Zero-based offset into the full attachment list.
     * @param int $batch_size Maximum items to attempt (capped to 200).
     * @return array{processed:int, suggested_batch_size:int}
     */
    public function scan_batch( $offset = 0, $batch_size = 5 ) {
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }

        $budget      = $this->get_safe_time_budget();
        $mem_limit   = $this->get_memory_limit_bytes();
        $mem_ceiling = (int) ( $mem_limit * 0.78 );
        $batch_size  = max( 1, min( 200, absint( $batch_size ) ) );
        $offset      = max( 0, absint( $offset ) );

        $args = $this->get_scan_query_args();
        $args['posts_per_page'] = $batch_size;
        $args['offset']         = $offset;
        $args['fields']         = 'ids';
        $args['no_found_rows']  = true;
        unset( $args['paged'] );

        $query      = new WP_Query( $args );
        $ids        = $query->posts;
        $processed  = 0;
        $batch_start = microtime( true );

        foreach ( $ids as $id ) {
            if ( ( microtime( true ) - $batch_start ) >= $budget ) {
                break;
            }
            if ( PHP_INT_MAX !== $mem_limit && function_exists( 'memory_get_usage' )
                && memory_get_usage( false ) >= $mem_ceiling ) {
                break;
            }

            if ( apply_filters( 'oliverodev_media_audit_skip_attachment', false, $id ) ) {
                continue;
            }

            $file_path = get_attached_file( $id );
            $size      = $file_path ? oliverodev_media_audit_filesize( $file_path ) : 0;
            update_post_meta( $id, '_oliverodev_media_audit_file_size', $size );

            $in_use = $this->is_media_in_use( $id );
            update_post_meta( $id, '_oliverodev_media_audit_is_unused', $in_use ? '0' : '1' );

            $processed++;
        }

        if ( $processed > 0 ) {
            $this->invalidate_cache();
        }

        // Derive suggested batch size from measured throughput.
        $elapsed   = max( 0.001, microtime( true ) - $batch_start );
        if ( $processed > 0 ) {
            $rate      = $processed / $elapsed;
            $suggested = (int) floor( $rate * $budget * 0.70 );
        } else {
            $suggested = 1;
        }
        $suggested = max( 1, min( 200, $suggested ) );

        return array(
            'processed'            => $processed,
            'suggested_batch_size' => $suggested,
        );
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

    /**
     * Return a list of locations where a media item is referenced.
     * Used by the admin UI to explain WHY a file is marked as "Used".
     * Runs at most 4 targeted queries and returns up to 5 locations.
     *
     * @param int $media_id
     * @return array Array of ['label' => string, 'url' => string, 'icon' => string]
     */
    public function get_usage_locations( $media_id ) {
        global $wpdb;

        $media_id  = absint( $media_id );
        $locations = array();

        if ( absint( get_option( 'site_icon' ) ) === $media_id ) {
            $locations[] = array(
                'label' => __( 'Site Icon', 'oliverodev-media-audit' ),
                'url'   => admin_url( 'customize.php?autofocus[section]=title_tagline' ),
                'icon'  => 'dashicons-admin-site',
            );
        }

        if ( get_theme_mod( 'custom_logo' ) == $media_id ) {
            $locations[] = array(
                'label' => __( 'Site Logo (Theme)', 'oliverodev-media-audit' ),
                'url'   => admin_url( 'customize.php?autofocus[section]=title_tagline' ),
                'icon'  => 'dashicons-format-image',
            );
        }

        $thumbnail_posts = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 3",
                $media_id
            )
        );
        foreach ( $thumbnail_posts as $row ) {
            $post = get_post( absint( $row->post_id ) );
            if ( $post ) {
                $locations[] = array(
                    'label' => sprintf(
                        /* translators: %s: post title */
                        __( 'Featured image: %s', 'oliverodev-media-audit' ),
                        $post->post_title ?: __( '(no title)', 'oliverodev-media-audit' )
                    ),
                    'url'  => get_edit_post_link( $post->ID ) ?: get_permalink( $post->ID ),
                    'icon' => 'dashicons-star-filled',
                );
            }
            if ( count( $locations ) >= 5 ) {
                return $locations;
            }
        }

        // Build search terms that mirror is_media_in_use() detection patterns.
        // Gutenberg stores images via wp-image-{id} class AND "id":N JSON —
        // not just the URL — so we must search all three to find the location.
        $media_url      = wp_get_attachment_url( $media_id );
        $search_terms   = array();
        if ( $media_url ) {
            $search_terms[] = $media_url;
        }
        // Gutenberg block CSS class: class="wp-image-123"
        $search_terms[] = 'wp-image-' . $media_id;
        // Gutenberg block JSON variants: {"id":123,...} or {"id": 123,...}
        $search_terms[] = '"id":' . $media_id . ',';
        $search_terms[] = '"id":' . $media_id . '}';
        $search_terms[] = '"id": ' . $media_id . ',';
        $search_terms[] = '"id": ' . $media_id . '}';

        if ( count( $locations ) < 5 ) {
            $found_ids = array();
            foreach ( $search_terms as $term ) {
                if ( count( $locations ) >= 5 ) {
                    break;
                }
                $limit = 5 - count( $locations );
                $like  = '%' . $wpdb->esc_like( $term ) . '%';
                $posts = $wpdb->get_results(
                    $wpdb->prepare(
                        "SELECT ID, post_title, post_type FROM {$wpdb->posts}
                         WHERE post_content LIKE %s
                           AND post_type NOT IN ('attachment','revision','auto-draft')
                           AND post_status NOT IN ('auto-draft','trash')
                         LIMIT %d",
                        $like,
                        $limit
                    )
                );
                foreach ( $posts as $post ) {
                    if ( in_array( $post->ID, $found_ids, true ) ) {
                        continue;
                    }
                    $found_ids[] = $post->ID;

                    $type_label = 'page' === $post->post_type
                        ? __( 'Page', 'oliverodev-media-audit' )
                        : ucfirst( $post->post_type );

                    $locations[] = array(
                        'label' => sprintf(
                            /* translators: 1: post type label, 2: post title */
                            '[%1$s] %2$s',
                            $type_label,
                            $post->post_title ?: __( '(no title)', 'oliverodev-media-audit' )
                        ),
                        'url'  => get_edit_post_link( $post->ID ) ?: get_permalink( $post->ID ),
                        'icon' => 'page' === $post->post_type
                            ? 'dashicons-page'
                            : 'dashicons-admin-post',
                    );
                }
            }
        }

        return apply_filters( 'oliverodev_media_audit_usage_locations', $locations, $media_id );
    }

}

/**
 * Global helper for backward compatibility
 */
function oliverodev_media_audit_is_media_in_use($media_id) {
    return Oliverodev_Media_Audit_Scanner::get_instance()->is_media_in_use($media_id);
}
