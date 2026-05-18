<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

call_user_func(
	static function () {
		global $wpdb;

		$option_keys = array(
			'oliverodev_media_audit_version',
			'oliverodev_media_audit_used_count',
			'oliverodev_media_audit_unused_count',
			'oliverodev_media_audit_used_size',
			'oliverodev_media_audit_unused_size',
			'oliverodev_media_audit_breakdown',
			'oliverodev_media_audit_last_check',
			'oliverodev_media_audit_batch_size',
			'oliverodev_media_audit_scan_frequency',
			'oliverodev_media_audit_file_types',
			'oliverodev_media_audit_cron_page',
			'oliverodev_media_audit_cron_running',
			'oliverodev_media_audit_cache_salt',
		);

		$meta_keys = array(
			'_oliverodev_media_audit_file_size',
			'_oliverodev_media_audit_is_unused',
		);

		$cleanup_site = static function () use ( $wpdb, $option_keys, $meta_keys ) {
			foreach ( $option_keys as $key ) {
				delete_option( $key );
			}

			wp_clear_scheduled_hook( 'oliverodev_media_audit_cron_scan' );
			wp_clear_scheduled_hook( 'oliverodev_media_audit_cron_maintenance' );

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
