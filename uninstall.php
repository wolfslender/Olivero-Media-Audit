<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

call_user_func(
	static function () {
		global $wpdb;

		$option_keys = array(
			'muc_version',
			'muc_used_count',
			'muc_unused_count',
			'muc_used_size',
			'muc_unused_size',
			'muc_breakdown',
			'muc_last_check',
			'muc_trashed_count',
			'muc_batch_size',
			'muc_scan_frequency',
			'muc_file_types',
			'muc_cron_page',
			'muc_cron_running',
			'muc_cache_salt',
			'muc_pro_auto_trash_enabled',
			'muc_pro_auto_trash_min_days',
			'muc_pro_auto_trash_limit',
			'muc_pro_auto_purge_enabled',
			'muc_pro_trash_retention_days',
			'muc_pro_auto_purge_limit',
			'muc_pro_weekly_report_enabled',
			'muc_pro_report_email_to',
			'muc_pro_report_last_sent',
			'muc_pro_exclude_filename_contains',
			'muc_pro_exclude_larger_than_mb',
			'muc_pro_exclude_mime_prefixes',
			'muc_pro_exclude_paths',
			'muc_pro_exclude_author_ids',
			'muc_pro_audit_log',
		);

		$meta_keys = array(
			'_muc_file_size',
			'_muc_is_unused',
			'_muc_trashed_at',
			'_muc_trash_path',
			'_muc_original_path',
			'_muc_trash_map',
		);

		$cleanup_site = static function () use ( $wpdb, $option_keys, $meta_keys ) {
			foreach ( $option_keys as $key ) {
				delete_option( $key );
			}

			wp_clear_scheduled_hook( 'media_usage_checker_cron_scan' );
			wp_clear_scheduled_hook( 'media_usage_checker_cron_maintenance' );

			foreach ( $meta_keys as $meta_key ) {
				delete_post_meta_by_key( $meta_key );
			}
		};

		if ( is_multisite() && function_exists( 'get_sites' ) ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
				)
			);

			if ( is_array( $sites ) && ! empty( $sites ) ) {
				foreach ( $sites as $site_id ) {
					$site_id = (int) $site_id;
					if ( $site_id < 1 ) {
						continue;
					}

					switch_to_blog( $site_id );
					$cleanup_site();
					restore_current_blog();
				}
			} else {
				$cleanup_site();
			}
		} else {
			$cleanup_site();
		}
	}
);
