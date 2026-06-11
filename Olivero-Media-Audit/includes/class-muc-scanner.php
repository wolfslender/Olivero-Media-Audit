<?php
/**
 * Media Usage Checker — Scanner
 *
 * Detection strategy: Inverted-Index Engine
 * ─────────────────────────────────────────
 * Instead of running 50+ LIKE queries per attachment (O(N×M)), the scanner
 * builds a single "used IDs" set ONCE at the start of each scan by scanning
 * every content source in the database exactly once. Each per-file check
 * during scan_batch() is then an O(1) array lookup.
 *
 * Outside an active scan (e.g. the UI "Where is it used?" button), a
 * lightweight check_single_item() fallback is used instead.
 *
 * @package Oliverodev_Media_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Oliverodev_Media_Audit_Scanner {

	private static $instance = null;

	/** In-memory index for the current PHP request. */
	private static $used_ids_runtime = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	// ── Cache helpers ──────────────────────────────────────────────────────

	private function cache_group() {
		return 'oliverodev-media-audit';
	}

	private function get_cache_salt() {
		$salt = absint( get_option( 'oliverodev_media_audit_cache_salt', 0 ) );
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
		self::$used_ids_runtime = null;
		$this->bump_cache_salt();
	}

	// ── Filesystem helpers ─────────────────────────────────────────────────

	private function get_filesystem() {
		global $wp_filesystem;
		if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
			return $wp_filesystem;
		}
		if ( function_exists( 'WP_Filesystem' ) ) {
			WP_Filesystem();
		}
		if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
			return $wp_filesystem;
		}
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			$inc = ABSPATH . 'wp-admin/includes/';
			if ( file_exists( $inc . 'class-wp-filesystem-base.php' ) ) {
				require_once $inc . 'class-wp-filesystem-base.php';
			}
			if ( file_exists( $inc . 'class-wp-filesystem-direct.php' ) ) {
				require_once $inc . 'class-wp-filesystem-direct.php';
			}
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

	// ── Elementor decompression helper ────────────────────────────────────

	private function elementor_decompress( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return '';
		}
		if ( "\x1f\x8b" === substr( $raw, 0, 2 ) ) {
			$decoded = @gzdecode( $raw );
			return ( false !== $decoded ) ? (string) $decoded : '';
		}
		$maybe = @base64_decode( $raw, true );
		if ( false !== $maybe && strlen( $maybe ) > 2 && "\x1f\x8b" === substr( $maybe, 0, 2 ) ) {
			$decoded = @gzdecode( $maybe );
			return ( false !== $decoded ) ? (string) $decoded : '';
		}
		return $raw;
	}

	// ═══════════════════════════════════════════════════════════════════════
	// INVERTED-INDEX ENGINE
	// ═══════════════════════════════════════════════════════════════════════

	/**
	 * Returns the in-use attachment ID index for the current scan session.
	 * Returns null when no index has been built yet (triggers fallback path).
	 *
	 * @return array<int,true>|null
	 */
	public function get_used_ids_index() {
		if ( null !== self::$used_ids_runtime ) {
			return self::$used_ids_runtime;
		}
		$cached = get_transient( 'omau_idx_' . $this->get_cache_salt() );
		if ( is_array( $cached ) ) {
			self::$used_ids_runtime = $cached;
			return $cached;
		}
		return null;
	}

	/**
	 * Builds the inverted index and caches it in a transient + memory.
	 * Called once per scan (at offset 0 of the first batch).
	 *
	 * @return int Number of in-use attachment IDs found.
	 */
	public function build_and_cache_index() {
		self::$used_ids_runtime = null;
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 );
		}
		$index = $this->build_used_ids_index();
		set_transient( 'omau_idx_' . $this->get_cache_salt(), $index, 2 * HOUR_IN_SECONDS );
		self::$used_ids_runtime = $index;
		return count( $index );
	}

	/**
	 * Scans every content source in the database once and returns a flat map
	 * of { attachment_id => true } for every attachment referenced anywhere.
	 *
	 * Sources covered:
	 *  1.  Site icon option
	 *  2.  Custom logo theme mod
	 *  3.  Featured images (_thumbnail_id)
	 *  4.  WooCommerce product galleries (_product_image_gallery)
	 *  5.  ACF image / file / gallery fields (ID stored with shadow key)
	 *  6.  All post_content (regex: wp-image-N, "id":N, gallery shortcodes, URLs)
	 *  7.  All postmeta values containing upload URLs
	 *  8.  wp_options containing upload URLs
	 *  9.  wp_usermeta containing upload URLs
	 * 10.  wp_termmeta containing upload URLs
	 * 11.  Elementor atomic CSS files (disk)
	 * 12.  Elementor compressed _elementor_data (gzip variants)
	 * 13.  Slider Revolution slides table
	 *
	 * Extensible via the oliverodev_media_audit_used_ids_index filter — the
	 * PRO plugin hooks here to add WooCommerce term thumbnails, ACF gallery
	 * arrays, ACF options page fields, etc.
	 *
	 * @return array<int,true>
	 */
	private function build_used_ids_index() {
		global $wpdb;

		$used = array();

		// ── 1. Site icon ─────────────────────────────────────────────────
		$v = absint( get_option( 'site_icon' ) );
		if ( $v > 0 ) {
			$used[ $v ] = true;
		}

		// ── 2. Custom logo ────────────────────────────────────────────────
		$v = absint( get_theme_mod( 'custom_logo' ) );
		if ( $v > 0 ) {
			$used[ $v ] = true;
		}

		// ── 3. Featured images ────────────────────────────────────────────
		$rows = $wpdb->get_col(
			"SELECT DISTINCT CAST(meta_value AS UNSIGNED)
			 FROM {$wpdb->postmeta}
			 WHERE meta_key = '_thumbnail_id'
			   AND meta_value REGEXP '^[0-9]+$'"
		);
		foreach ( $rows as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$used[ $id ] = true;
			}
		}

		// ── 4. WooCommerce product gallery ────────────────────────────────
		$galleries = $wpdb->get_col(
			"SELECT meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = '_product_image_gallery'
			   AND meta_value != ''"
		);
		foreach ( $galleries as $gallery ) {
			foreach ( array_filter( array_map( 'absint', explode( ',', (string) $gallery ) ) ) as $id ) {
				$used[ $id ] = true;
			}
		}

		// ── 5. ACF image / file / gallery fields (ID-based) ──────────────
		// ACF always writes a shadow meta "_field_name = field_xxxxx" alongside
		// the real field value. We JOIN on that and on wp_posts to ensure only
		// actual attachment IDs are collected (no false positives).
		$acf_ids = $wpdb->get_col(
			"SELECT DISTINCT CAST(pm.meta_value AS UNSIGNED) AS id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->postmeta} pm_shadow
			     ON  pm_shadow.post_id   = pm.post_id
			     AND pm_shadow.meta_key  = CONCAT( '_', pm.meta_key )
			     AND pm_shadow.meta_value LIKE 'field\_%'
			 INNER JOIN {$wpdb->posts} att
			     ON  att.ID           = CAST(pm.meta_value AS UNSIGNED)
			     AND att.post_type    = 'attachment'
			 WHERE pm.meta_key    NOT LIKE '\\_%%'
			   AND pm.meta_value  REGEXP '^[0-9]+$'"
		);
		foreach ( $acf_ids as $id ) {
			$id = absint( $id );
			if ( $id > 0 ) {
				$used[ $id ] = true;
			}
		}

		// ── Build URL→ID reverse map ──────────────────────────────────────
		// Used by all content-scanning steps below.
		$upload_dir = wp_upload_dir();
		$base_url   = untrailingslashit( (string) $upload_dir['baseurl'] );
		$base_dir   = untrailingslashit( (string) $upload_dir['basedir'] );

		// path_to_id maps both relative paths ("2024/01/img.jpg") and basenames
		// ("img.jpg") back to the attachment ID. Generated sizes are also indexed
		// so a reference to a thumbnail correctly marks the original as in-use.
		$path_to_id = array();

		$att_rows = $wpdb->get_results(
			"SELECT p.ID,
			        pm_file.meta_value AS file,
			        pm_meta.meta_value AS metadata
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm_file
			     ON pm_file.post_id = p.ID
			     AND pm_file.meta_key = '_wp_attached_file'
			 LEFT JOIN {$wpdb->postmeta} pm_meta
			     ON pm_meta.post_id = p.ID
			     AND pm_meta.meta_key = '_wp_attachment_metadata'
			 WHERE p.post_type  = 'attachment'
			   AND p.post_status != 'trash'"
		);

		foreach ( $att_rows as $row ) {
			$id   = absint( $row->ID );
			$file = ltrim( (string) $row->file, '/' );
			if ( $id < 1 || '' === $file ) {
				continue;
			}
			$path_to_id[ $file ]             = $id;
			$path_to_id[ basename( $file ) ] = $id;

			// Index generated thumbnail filenames.
			if ( ! empty( $row->metadata ) ) {
				$meta = maybe_unserialize( $row->metadata );
				if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
					$dir = trailingslashit( dirname( $file ) );
					foreach ( $meta['sizes'] as $size ) {
						if ( empty( $size['file'] ) ) {
							continue;
						}
						$path_to_id[ $dir . $size['file'] ] = $id;
						$path_to_id[ $size['file'] ]         = $id;
					}
				}
			}
		}

		// ── 6. Scan post_content ──────────────────────────────────────────
		$this->scan_column(
			$wpdb->posts,
			'post_content',
			"post_type NOT IN ('attachment','revision','auto-draft')
			 AND post_status NOT IN ('auto-draft','trash')",
			$base_url,
			$path_to_id,
			$used
		);

		// ── 7. Scan postmeta values ───────────────────────────────────────
		$burl_like  = '%' . $wpdb->esc_like( $base_url ) . '%';
		$meta_where = $wpdb->prepare( 'meta_value LIKE %s', $burl_like );

		// Some builders (Elementor, etc.) persist URLs with a different scheme
		// than the current wp_upload_dir() returns.  Also search with the
		// alternate protocol (http ↔ https) to catch those cases.
		$alt_url = str_replace( array( 'https://', 'http://' ), array( 'http://', 'https://' ), $base_url );
		if ( $alt_url !== $base_url ) {
			$alt_like   = '%' . $wpdb->esc_like( $alt_url ) . '%';
			$meta_where .= ' OR ' . $wpdb->prepare( 'meta_value LIKE %s', $alt_like );
		}

		$this->scan_column(
			$wpdb->postmeta,
			'meta_value',
			$meta_where,
			$base_url,
			$path_to_id,
			$used
		);

		// ── 8. Scan wp_options ────────────────────────────────────────────
		$opt_where = $wpdb->prepare(
			"( option_value LIKE %s AND option_name NOT LIKE '\\_transient%%' )",
			$burl_like
		);
		if ( $alt_url !== $base_url ) {
			$opt_where .= $wpdb->prepare(
				" OR ( option_value LIKE %s AND option_name NOT LIKE '\\_transient%%' )",
				$alt_like
			);
		}
		$this->scan_column(
			$wpdb->options,
			'option_value',
			$opt_where,
			$base_url,
			$path_to_id,
			$used
		);

		// ── 9. Scan wp_usermeta ───────────────────────────────────────────
		$umeta_where = $wpdb->prepare( 'meta_value LIKE %s', $burl_like );
		if ( $alt_url !== $base_url ) {
			$umeta_where .= ' OR ' . $wpdb->prepare( 'meta_value LIKE %s', $alt_like );
		}
		$this->scan_column(
			$wpdb->usermeta,
			'meta_value',
			$umeta_where,
			$base_url,
			$path_to_id,
			$used
		);

		// ── 10. Scan wp_termmeta ──────────────────────────────────────────
		$tmeta_where = $wpdb->prepare( 'meta_value LIKE %s', $burl_like );
		if ( $alt_url !== $base_url ) {
			$tmeta_where .= ' OR ' . $wpdb->prepare( 'meta_value LIKE %s', $alt_like );
		}
		$this->scan_column(
			$wpdb->termmeta,
			'meta_value',
			$tmeta_where,
			$base_url,
			$path_to_id,
			$used
		);

		// ── 11. Elementor atomic CSS files (Flexbox Container, disk) ──────
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$el_css_dir = trailingslashit( $base_dir ) . 'elementor/css/';
			$el_css_dir = (string) realpath( $el_css_dir );
			if ( '' !== $el_css_dir && is_dir( $el_css_dir ) ) {
				$el_files = get_transient( 'omau_el_css_list' );
				if ( false === $el_files ) {
					$el_files = glob( $el_css_dir . '/*.css' ) ?: array();
					$el_files = array_values( array_filter( $el_files, function ( $f ) {
						return is_string( $f ) && 0 === strpos( (string) realpath( $f ), $el_css_dir . '/' );
					} ) );
					set_transient( 'omau_el_css_list', $el_files, MINUTE_IN_SECONDS );
				}
				foreach ( (array) $el_files as $css_file ) {
					if ( ! is_string( $css_file ) || ! file_exists( $css_file ) ) {
						continue;
					}
					$content = @file_get_contents( $css_file );
					if ( false === $content || '' === $content ) {
						continue;
					}
					$this->extract_refs( $content, $base_url, $path_to_id, $used );
				}
			}

			// ── 12. Elementor compressed _elementor_data ─────────────────
			$compressed = $wpdb->get_results(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta}
				 WHERE meta_key = '_elementor_data'
				   AND meta_value NOT LIKE '[%'
				   AND meta_value NOT LIKE '{%'
				 LIMIT 500"
			);
			foreach ( $compressed as $row ) {
				$raw = $this->elementor_decompress( (string) $row->meta_value );
				if ( '' !== $raw ) {
					$this->extract_refs( $raw, $base_url, $path_to_id, $used );
				}
			}
		}

		// ── 13. Slider plugins (Slider Revolution, Smart Slider 3, LayerSlider) ──
		$slider_tables = $this->get_existing_slider_tables();

		if ( isset( $slider_tables['revslider'] ) ) {
			$this->scan_column(
				$slider_tables['revslider'],
				'params',
				$wpdb->prepare( 'params LIKE %s OR layers LIKE %s', $burl_like, $burl_like ),
				$base_url,
				$path_to_id,
				$used
			);
		}

		if ( isset( $slider_tables['smartslider3'] ) ) {
			$this->scan_column(
				$slider_tables['smartslider3'],
				'params',
				$wpdb->prepare( 'params LIKE %s', $burl_like ),
				$base_url,
				$path_to_id,
				$used
			);
		}

		if ( isset( $slider_tables['layerslider'] ) ) {
			$this->scan_column(
				$slider_tables['layerslider'],
				'data',
				$wpdb->prepare( 'data LIKE %s', $burl_like ),
				$base_url,
				$path_to_id,
				$used
			);
		}

		return apply_filters( 'oliverodev_media_audit_used_ids_index', $used );
	}

	/**
	 * Reads a table column in memory-safe chunks and feeds every value through
	 * extract_refs(). The $where clause is built by internal callers only.
	 *
	 * @param string             $table      Table name (no backticks needed).
	 * @param string             $column     Column to read.
	 * @param string             $where      Raw SQL WHERE clause (trusted caller).
	 * @param string             $base_url   Upload base URL.
	 * @param array<string,int>  $path_to_id Reverse-map built by caller.
	 * @param array<int,true>   &$used       Running set, modified in place.
	 */
	/**
	 * Validates a table name against known WordPress and plugin table names.
	 *
	 * @param string $table Raw table name.
	 * @return string Sanitized table name, or empty string if invalid.
	 */
	private function validate_table_name( $table ) {
		global $wpdb;
		$table = is_string( $table ) ? $table : '';
		if ( '' === $table ) {
			return '';
		}
		$allowed = array(
			$wpdb->posts,
			$wpdb->postmeta,
			$wpdb->options,
			$wpdb->usermeta,
			$wpdb->termmeta,
			$wpdb->prefix . 'revslider_slides',
			$wpdb->prefix . 'nextend2_smartslider3_slides',
			$wpdb->prefix . 'layerslider',
		);
		return in_array( $table, $allowed, true ) ? $table : '';
	}

	/**
	 * Validates a column name string — only allow simple alphanumeric/underscore names.
	 *
	 * @param string $column Raw column name.
	 * @return string Sanitized column name, or empty string if invalid.
	 */
	private function validate_column_name( $column ) {
		$column = is_string( $column ) ? $column : '';
		if ( '' === $column ) {
			return '';
		}
		return preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column ) ? $column : '';
	}

	private function scan_column( $table, $column, $where, $base_url, array $path_to_id, array &$used ) {
		global $wpdb;

		$table  = $this->validate_table_name( $table );
		$column = $this->validate_column_name( $column );
		if ( '' === $table || '' === $column ) {
			return;
		}

		$chunk  = 300;
		$offset = 0;

		do {
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT `{$column}` FROM `{$table}` WHERE {$where} LIMIT %d OFFSET %d",
				$chunk,
				$offset
			) );
			foreach ( $rows as $value ) {
				if ( '' !== (string) $value ) {
					$this->extract_refs( (string) $value, $base_url, $path_to_id, $used );
				}
			}
			$offset += $chunk;
		} while ( count( $rows ) === $chunk );
	}

	/**
	 * Extracts every attachment ID referenced in $str and adds it to $used.
	 *
	 * Patterns detected:
	 *  a) wp-image-N      CSS class  (Gutenberg, classic editor)
	 *  b) "id": N         JSON key   (Gutenberg, Elementor, Divi, Beaver, …)
	 *  c) ids="…"         shortcode  ([gallery ids="1,2,3"])
	 *  d) Upload-dir URL  mapped to attachment ID via $path_to_id
	 *
	 * @param string             $str
	 * @param string             $base_url
	 * @param array<string,int>  $path_to_id
	 * @param array<int,true>   &$used
	 */
	private function extract_refs( $str, $base_url, array $path_to_id, array &$used ) {
		if ( '' === $str ) {
			return;
		}

		// a) wp-image-N
		if ( false !== strpos( $str, 'wp-image-' )
			&& preg_match_all( '/\bwp-image-(\d+)\b/', $str, $m ) ) {
			foreach ( $m[1] as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$used[ $id ] = true;
				}
			}
		}

		// b) JSON "id": N  (integer and quoted-string variants)
		if ( false !== strpos( $str, '"id"' )
			&& preg_match_all( '/"id"\s*:\s*"?(\d+)"?/', $str, $m ) ) {
			foreach ( $m[1] as $id ) {
				$id = absint( $id );
				if ( $id > 0 ) {
					$used[ $id ] = true;
				}
			}
		}

		// c) Gallery shortcode ids list
		if ( false !== strpos( $str, 'ids=' )
			&& preg_match_all( '/\bids=["\']([0-9,\s]+)["\']/', $str, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( array_filter( array_map( 'absint', explode( ',', $list ) ) ) as $id ) {
					$used[ $id ] = true;
				}
			}
		}

		// d) Upload-directory URL → ID
		if ( false !== strpos( $str, $base_url ) ) {
			$escaped = preg_quote( $base_url, '/' );
			if ( preg_match_all( '/' . $escaped . '\/?([^\s"\'<>()\[\]]+)/', $str, $m ) ) {
				foreach ( $m[1] as $raw ) {
					$path     = ltrim( (string) strtok( $raw, '?#' ), '/' );
					$basename = basename( $path );
					if ( isset( $path_to_id[ $path ] ) ) {
						$used[ $path_to_id[ $path ] ] = true;
					} elseif ( isset( $path_to_id[ $basename ] ) ) {
						$used[ $path_to_id[ $basename ] ] = true;
					}
				}
			}
		}
	}

	/**
	 * Detects which third-party slider plugin tables exist on this site.
	 * Result is cached for the duration of the request (and reused by both
	 * the inverted-index builder and the single-item fallback).
	 *
	 * @return array<string,string> Map of slider key => table name, only for tables that exist.
	 */
	private function get_existing_slider_tables() {
		static $tables = null;
		if ( null !== $tables ) {
			return $tables;
		}

		global $wpdb;
		$tables     = array();
		$candidates = array(
			'revslider'    => $wpdb->prefix . 'revslider_slides',
			'smartslider3' => $wpdb->prefix . 'nextend2_smartslider3_slides',
			'layerslider'  => $wpdb->prefix . 'layerslider',
		);

		foreach ( $candidates as $key => $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$tables[ $key ] = $table;
			}
		}

		return $tables;
	}

	// ═══════════════════════════════════════════════════════════════════════
	// PUBLIC DETECTION API
	// ═══════════════════════════════════════════════════════════════════════

	/**
	 * Returns true if the attachment is referenced anywhere on the site.
	 *
	 * Fast path (during scan): O(1) index lookup.
	 * Slow path (UI / cron without active scan): lightweight targeted queries.
	 *
	 * @param int $media_id
	 * @return bool
	 */
	public function is_media_in_use( $media_id ) {
		$media_id = absint( $media_id );
		if ( $media_id < 1 ) {
			return false;
		}

		$index = $this->get_used_ids_index();
		if ( null !== $index ) {
			return isset( $index[ $media_id ] );
		}

		return $this->check_single_item( $media_id );
	}

	/**
	 * Lightweight fallback used outside an active scan.
	 * Covers the most common reference types with targeted queries.
	 * No full-table LIKE scans; fast enough for single-item UI calls.
	 *
	 * @param int $media_id
	 * @return bool
	 */
	private function check_single_item( $media_id ) {
		global $wpdb;
		$media_id = absint( $media_id );

		if ( absint( get_option( 'site_icon' ) ) === $media_id ) {
			return true;
		}
		if ( absint( get_theme_mod( 'custom_logo' ) ) === $media_id ) {
			return true;
		}

		// Featured image
		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1",
			$media_id
		) ) ) {
			return true;
		}

		// WooCommerce gallery
		$id_s = (string) $media_id;
		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_product_image_gallery'
			   AND ( meta_value = %s OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s )
			 LIMIT 1",
			$id_s,
			$wpdb->esc_like( $id_s ) . ',%',
			'%,' . $wpdb->esc_like( $id_s ) . ',%',
			'%,' . $wpdb->esc_like( $id_s )
		) ) ) {
			return true;
		}

		// ACF single-value image field
		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT pm.post_id
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->postmeta} pm_shadow
			     ON pm_shadow.post_id  = pm.post_id
			     AND pm_shadow.meta_key = CONCAT('_', pm.meta_key)
			     AND pm_shadow.meta_value LIKE 'field\_%'
			 WHERE pm.meta_value = %s AND pm.meta_key NOT LIKE '\\_%%'
			 LIMIT 1",
			$id_s
		) ) ) {
			return true;
		}

		// Post content: wp-image class + URL
		$media_url = wp_get_attachment_url( $media_id );
		$likes     = array( '%wp-image-' . $wpdb->esc_like( $id_s ) . '%' );
		if ( $media_url ) {
			$likes[] = '%' . $wpdb->esc_like( $media_url ) . '%';
		}
		foreach ( $likes as $like ) {
			if ( $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				 WHERE post_content LIKE %s
				   AND post_type   NOT IN ('attachment','revision','auto-draft')
				   AND post_status NOT IN ('auto-draft','trash')
				 LIMIT 1",
				$like
			) ) ) {
				return true;
			}
		}

		// Postmeta URL
		if ( $media_url && $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_value LIKE %s AND post_id != %d LIMIT 1",
			'%' . $wpdb->esc_like( $media_url ) . '%',
			$media_id
		) ) ) {
			return true;
		}

		// Slider plugins (Slider Revolution, Smart Slider 3, LayerSlider)
		if ( $media_url ) {
			$url_like      = '%' . $wpdb->esc_like( $media_url ) . '%';
			$slider_tables = $this->get_existing_slider_tables();

			if ( isset( $slider_tables['revslider'] ) && $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$slider_tables['revslider']}` WHERE params LIKE %s OR layers LIKE %s LIMIT 1",
				$url_like,
				$url_like
			) ) ) {
				return true;
			}

			if ( isset( $slider_tables['smartslider3'] ) && $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$slider_tables['smartslider3']}` WHERE params LIKE %s LIMIT 1",
				$url_like
			) ) ) {
				return true;
			}

			if ( isset( $slider_tables['layerslider'] ) && $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM `{$slider_tables['layerslider']}` WHERE data LIKE %s LIMIT 1",
				$url_like
			) ) ) {
				return true;
			}
		}

		return (bool) apply_filters( 'oliverodev_media_audit_is_media_used', false, $media_id, '' );
	}

	// ═══════════════════════════════════════════════════════════════════════
	// SCAN ENGINE
	// ═══════════════════════════════════════════════════════════════════════

	private function get_scan_query_args() {
		$file_types = get_option( 'oliverodev_media_audit_file_types', array( 'image', 'document', 'video', 'audio', 'archive' ) );
		if ( ! is_array( $file_types ) ) {
			$file_types = array( 'image', 'document', 'video', 'audio', 'archive' );
		}
		$file_types = array_values( array_intersect(
			array_map( 'sanitize_key', $file_types ),
			array( 'image', 'document', 'video', 'audio', 'archive' )
		) );

		$mime_types = array();
		foreach ( $file_types as $type ) {
			switch ( $type ) {
				case 'image':    $mime_types[] = 'image/%'; break;
				case 'video':    $mime_types[] = 'video/%'; break;
				case 'audio':    $mime_types[] = 'audio/%'; break;
				case 'document':
					$mime_types = array_merge( $mime_types, array(
						'application/pdf',
						'application/msword',
						'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
						'application/vnd.ms-excel',
						'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
						'text/plain',
					) );
					break;
				case 'archive':
					$mime_types = array_merge( $mime_types, array(
						'application/zip',
						'application/x-rar-compressed',
						'application/x-tar',
					) );
					break;
			}
		}

		$args = array(
			'post_type'              => 'attachment',
			'post_status'            => array( 'inherit', 'publish', 'private' ),
			'fields'                 => 'ids',
			'posts_per_page'         => 20,
			'no_found_rows'          => false,
			'update_post_term_cache' => false,
			'update_post_meta_cache' => false,
			'post_mime_type'         => ! empty( $mime_types ) ? $mime_types : '',
		);

		return apply_filters( 'oliverodev_media_audit_scan_query_args', $args );
	}

	public function get_total_attachments() {
		$args                    = $this->get_scan_query_args();
		$args['posts_per_page']  = 1;
		$args['paged']           = 1;
		$args['fields']          = 'ids';
		$args['no_found_rows']   = false;
		$query = new WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Process a batch of attachments starting at $offset.
	 *
	 * Builds the inverted index on the first batch (offset = 0) so subsequent
	 * batches get O(1) per-item lookups. Adapts batch size based on measured
	 * throughput so the scan stays within server memory and time limits.
	 *
	 * @param int $offset     Zero-based offset into the full attachment list.
	 * @param int $batch_size Maximum items to attempt this call.
	 * @return array{processed:int,used_in_batch:int,unused_in_batch:int,suggested_batch_size:int}
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

		$args                       = $this->get_scan_query_args();
		$args['posts_per_page']     = $batch_size;
		$args['offset']             = $offset;
		$args['fields']             = 'ids';
		$args['no_found_rows']      = true;
		unset( $args['paged'] );

		$query        = new WP_Query( $args );
		$ids          = $query->posts;
		$processed    = 0;
		$used_count   = 0;
		$unused_count = 0;
		$batch_start  = microtime( true );

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

			if ( $in_use ) {
				$used_count++;
			} else {
				$unused_count++;
			}
			$processed++;
		}

		if ( $processed > 0 ) {
			$this->invalidate_cache();
		}

		$elapsed   = max( 0.001, microtime( true ) - $batch_start );
		$suggested = $processed > 0
			? (int) floor( ( $processed / $elapsed ) * $budget * 0.70 )
			: 1;
		$suggested = max( 1, min( 200, $suggested ) );

		return array(
			'processed'            => $processed,
			'used_in_batch'        => $used_count,
			'unused_in_batch'      => $unused_count,
			'suggested_batch_size' => $suggested,
		);
	}

	private function get_safe_time_budget() {
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit <= 0 ) {
			return 15;
		}
		return min( 20, max( 3, (int) floor( $limit * 0.50 ) ) );
	}

	private function get_memory_limit_bytes() {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '-1' === $raw ) {
			return PHP_INT_MAX;
		}
		$unit  = strtolower( substr( $raw, -1 ) );
		$value = (int) $raw;
		switch ( $unit ) {
			case 'g': $value *= 1024;
			// fall through
			case 'm': $value *= 1024;
			// fall through
			case 'k': $value *= 1024;
		}
		return max( 1, $value );
	}

	// ═══════════════════════════════════════════════════════════════════════
	// STATS
	// ═══════════════════════════════════════════════════════════════════════

	public function calculate_stats_from_meta() {
		global $wpdb;
		$total_media = (int) $this->get_total_attachments();
		$salt        = $this->get_cache_salt();
		$cg          = $this->cache_group();

		$used_count = wp_cache_get( $salt . ':stats_used_count', $cg );
		if ( false === $used_count ) {
			$used_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key = '_oliverodev_media_audit_is_unused' AND pm.meta_value = '0' AND p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft')" );
			wp_cache_set( $salt . ':stats_used_count', $used_count, $cg, 10 );
		}

		$unused_count = wp_cache_get( $salt . ':stats_unused_count', $cg );
		if ( false === $unused_count ) {
			$unused_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key = '_oliverodev_media_audit_is_unused' AND pm.meta_value = '1' AND p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft')" );
			wp_cache_set( $salt . ':stats_unused_count', $unused_count, $cg, 10 );
		}

		$used_size = wp_cache_get( $salt . ':stats_used_size', $cg );
		if ( false === $used_size ) {
			$used_size = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft') AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_oliverodev_media_audit_is_unused' AND meta_value='0')" );
			wp_cache_set( $salt . ':stats_used_size', $used_size, $cg, 10 );
		}

		$unused_size = wp_cache_get( $salt . ':stats_unused_size', $cg );
		if ( false === $unused_size ) {
			$unused_size = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft') AND p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_oliverodev_media_audit_is_unused' AND meta_value='1')" );
			wp_cache_set( $salt . ':stats_unused_size', $unused_size, $cg, 10 );
		}

		$breakdown = array(
			'image'    => array( 'count' => 0, 'size' => 0 ),
			'document' => array( 'count' => 0, 'size' => 0 ),
			'video'    => array( 'count' => 0, 'size' => 0 ),
			'audio'    => array( 'count' => 0, 'size' => 0 ),
			'archive'  => array( 'count' => 0, 'size' => 0 ),
		);

		foreach ( array( 'image' => 'image/%', 'video' => 'video/%', 'audio' => 'audio/%' ) as $type => $mime_like ) {
			$k = $salt . ':bd_' . $type . '_count';
			$v = wp_cache_get( $k, $cg );
			if ( false === $v ) {
				$v = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status NOT IN ('trash','auto-draft') AND post_mime_type LIKE %s", $mime_like ) );
				wp_cache_set( $k, $v, $cg, 30 );
			}
			$breakdown[ $type ]['count'] = $v;
		}

		$k = $salt . ':bd_archive_count';
		$v = wp_cache_get( $k, $cg );
		if ( false === $v ) {
			$v = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status NOT IN ('trash','auto-draft') AND post_mime_type IN ('application/zip','application/x-rar-compressed','application/x-tar')" );
			wp_cache_set( $k, $v, $cg, 30 );
		}
		$breakdown['archive']['count']   = $v;
		$breakdown['document']['count']  = max( 0, $total_media - $breakdown['image']['count'] - $breakdown['video']['count'] - $breakdown['audio']['count'] - $breakdown['archive']['count'] );

		foreach ( array( 'image' => 'image/%', 'video' => 'video/%', 'audio' => 'audio/%' ) as $type => $mime_like ) {
			$k = $salt . ':bd_' . $type . '_size';
			$v = wp_cache_get( $k, $cg );
			if ( false === $v ) {
				$v = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft') AND p.post_mime_type LIKE %s", $mime_like ) );
				wp_cache_set( $k, $v, $cg, 30 );
			}
			$breakdown[ $type ]['size'] = $v;
		}

		$k = $salt . ':bd_archive_size';
		$v = wp_cache_get( $k, $cg );
		if ( false === $v ) {
			$v = (int) $wpdb->get_var( "SELECT COALESCE(SUM(CAST(pm.meta_value AS UNSIGNED)),0) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE pm.meta_key='_oliverodev_media_audit_file_size' AND p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft') AND p.post_mime_type IN ('application/zip','application/x-rar-compressed','application/x-tar')" );
			wp_cache_set( $k, $v, $cg, 30 );
		}
		$breakdown['archive']['size']  = $v;
		$breakdown['document']['size'] = max( 0, ( $used_size + $unused_size ) - $breakdown['image']['size'] - $breakdown['video']['size'] - $breakdown['audio']['size'] - $breakdown['archive']['size'] );

		$this->save_stats( $total_media, $used_count, $unused_count, $used_size, $unused_size, $breakdown );
		return $this->get_formatted_stats( $total_media, $used_count, $unused_count, $used_size, $unused_size );
	}

	private function save_stats( $total, $used, $unused, $used_size, $unused_size, $breakdown ) {
		update_option( 'oliverodev_media_audit_used_count',   $used );
		update_option( 'oliverodev_media_audit_unused_count', $unused );
		update_option( 'oliverodev_media_audit_used_size',    $used_size );
		update_option( 'oliverodev_media_audit_unused_size',  $unused_size );
		update_option( 'oliverodev_media_audit_breakdown',    $breakdown );
		update_option( 'oliverodev_media_audit_last_check',   time() );
	}

	private function get_formatted_stats( $total, $used, $unused, $used_size, $unused_size ) {
		return array(
			'total'          => $total,
			'used'           => $used,
			'unused'         => $unused,
			'used_size'      => size_format( $used_size ),
			'unused_size'    => size_format( $unused_size ),
			'raw_used_size'  => $used_size,
			'raw_unused_size'=> $unused_size,
		);
	}

	public function update_stats() {
		$this->invalidate_cache();
		return $this->calculate_stats_from_meta();
	}

	public function update_item_status( $media_id ) {
		$media_id = absint( $media_id );
		if ( 0 === $media_id ) {
			return false;
		}
		$in_use = $this->is_media_in_use( $media_id );
		update_post_meta( $media_id, '_oliverodev_media_audit_is_unused', $in_use ? '0' : '1' );
		$this->invalidate_cache();
		return $in_use;
	}

	// ═══════════════════════════════════════════════════════════════════════
	// DELETION
	// ═══════════════════════════════════════════════════════════════════════

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

		$size      = (int) get_post_meta( $media_id, '_oliverodev_media_audit_file_size', true );
		$mime      = get_post_mime_type( $media_id );
		$is_unused = ( '1' === get_post_meta( $media_id, '_oliverodev_media_audit_is_unused', true ) );
		$paths     = $this->get_attachment_paths( $media_id );

		$result = wp_delete_attachment( $media_id, true );
		if ( ! $result ) {
			$result = wp_delete_post( $media_id, true );
		}

		if ( $result ) {
			foreach ( array_unique( array_filter( $paths ) ) as $path ) {
				$this->delete_local_file( $path );
				$this->clean_empty_parent_dirs( dirname( $path ) );
			}
			$this->invalidate_cache();

			if ( $is_unused && $size > 0 ) {
				$cur = (int) get_option( 'oliverodev_media_audit_unused_size', 0 );
				if ( $cur > 0 ) {
					update_option( 'oliverodev_media_audit_unused_size', $cur - $size );
				}
			}

			$cat = 'document';
			if ( strpos( $mime, 'image/' ) !== false )    { $cat = 'image'; }
			elseif ( strpos( $mime, 'video/' ) !== false ) { $cat = 'video'; }
			elseif ( strpos( $mime, 'audio/' ) !== false ) { $cat = 'audio'; }
			elseif ( in_array( $mime, array( 'application/zip', 'application/x-rar-compressed', 'application/x-tar' ), true ) ) { $cat = 'archive'; }

			$breakdown = get_option( 'oliverodev_media_audit_breakdown', array() );
			if ( isset( $breakdown[ $cat ] ) ) {
				$breakdown[ $cat ]['size'] = max( 0, $breakdown[ $cat ]['size'] - $size );
				update_option( 'oliverodev_media_audit_breakdown', $breakdown );
			}
		}

		if ( $update_stats ) {
			$this->update_stats();
		}
		return $result;
	}

	private function get_attachment_paths( $media_id ) {
		$paths     = array();
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
		$uploads    = wp_upload_dir();
		$base_dir   = wp_normalize_path( (string) $uploads['basedir'] );
		$real_base  = (string) realpath( $base_dir );
		if ( '' === $real_base ) {
			$real_base = $base_dir;
		}
		$path      = wp_normalize_path( $path );
		$real_path = (string) realpath( $path );
		if ( '' !== $real_path ) {
			$path = $real_path;
		}
		if ( 0 !== strpos( $path, $real_base . '/' ) && $path !== $real_base ) {
			return false;
		}
		if ( $fs->exists( $path ) ) {
			return $this->delete_file( $path );
		}
		return true;
	}

	private function clean_empty_parent_dirs( $dir ) {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return;
		}
		$fs = $this->get_filesystem();
		if ( ! $fs || ! method_exists( $fs, 'exists' ) ) {
			return;
		}
		$base_dir = wp_normalize_path( untrailingslashit( (string) $uploads['basedir'] ) );
		$dir      = wp_normalize_path( untrailingslashit( (string) $dir ) );
		if ( empty( $dir ) || $dir === $base_dir || 0 !== strpos( $dir, $base_dir . '/' ) ) {
			return;
		}
		if ( empty( $this->list_dir( $dir ) ) ) {
			if ( method_exists( $fs, 'rmdir' ) ) {
				$fs->rmdir( $dir );
			}
			if ( ! $fs->exists( $dir ) ) {
				$this->clean_empty_parent_dirs( dirname( $dir ) );
			}
		}
	}

	// ═══════════════════════════════════════════════════════════════════════
	// USAGE LOCATIONS (UI — targeted per-item queries)
	// ═══════════════════════════════════════════════════════════════════════

	/**
	 * Return up to 5 locations where a media item is referenced.
	 * Used by the "Where is it used?" button in the admin UI.
	 *
	 * @param int $media_id
	 * @return array<int,array{label:string,url:string,icon:string}>
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

		if ( absint( get_theme_mod( 'custom_logo' ) ) === $media_id ) {
			$locations[] = array(
				'label' => __( 'Site Logo (Theme)', 'oliverodev-media-audit' ),
				'url'   => admin_url( 'customize.php?autofocus[section]=title_tagline' ),
				'icon'  => 'dashicons-format-image',
			);
		}

		$thumb_rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 3",
			$media_id
		) );
		foreach ( $thumb_rows as $row ) {
			$post = get_post( absint( $row->post_id ) );
			if ( $post ) {
				$locations[] = array(
					/* translators: %s: post title */
					'label' => sprintf( __( 'Featured image: %s', 'oliverodev-media-audit' ), $post->post_title ?: __( '(no title)', 'oliverodev-media-audit' ) ),
					'url'   => get_edit_post_link( $post->ID ) ?: (string) get_permalink( $post->ID ),
					'icon'  => 'dashicons-star-filled',
				);
			}
			if ( count( $locations ) >= 5 ) {
				return $locations;
			}
		}

		$media_url    = wp_get_attachment_url( $media_id );
		$search_terms = array( 'wp-image-' . $media_id );
		if ( $media_url ) {
			$search_terms[] = $media_url;
		}
		array_push( $search_terms, '"id":' . $media_id . ',', '"id":' . $media_id . '}', '"id": ' . $media_id . ',', '"id": ' . $media_id . '}' );

		$found_ids = array();
		foreach ( $search_terms as $term ) {
			if ( count( $locations ) >= 5 ) {
				break;
			}
			$limit = 5 - count( $locations );
			$posts = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_title, post_type FROM {$wpdb->posts}
				 WHERE post_content LIKE %s
				   AND post_type NOT IN ('attachment','revision','auto-draft')
				   AND post_status NOT IN ('auto-draft','trash')
				 LIMIT %d",
				'%' . $wpdb->esc_like( $term ) . '%',
				$limit
			) );
			foreach ( $posts as $post ) {
				if ( in_array( $post->ID, $found_ids, true ) ) {
					continue;
				}
				$found_ids[] = $post->ID;
				$type_label  = 'page' === $post->post_type ? __( 'Page', 'oliverodev-media-audit' ) : ucfirst( $post->post_type );
				$locations[] = array(
					'label' => sprintf( '[%1$s] %2$s', $type_label, $post->post_title ?: __( '(no title)', 'oliverodev-media-audit' ) ),
					'url'   => get_edit_post_link( $post->ID ) ?: (string) get_permalink( $post->ID ),
					'icon'  => 'page' === $post->post_type ? 'dashicons-page' : 'dashicons-admin-post',
				);
			}
		}

		return apply_filters( 'oliverodev_media_audit_usage_locations', $locations, $media_id );
	}

}

/**
 * Global helper for backward compatibility.
 */
function oliverodev_media_audit_is_media_in_use( $media_id ) {
	return Oliverodev_Media_Audit_Scanner::get_instance()->is_media_in_use( $media_id );
}
