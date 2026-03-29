<?php
/**
 * Plugin Name: Olivero Media Audit
 * Plugin URI: https://github.com/wolfslender/Media-Usage-Checker
 * Description: Identifies which media library files are in use in WordPress content and allows you to delete unused ones.
 * Version: 3.2.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Alexis Olivero
 * Author URI: https://oliverodev.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: olivero-media-audit
 * Domain Path: /languages
 *
 * @package Media_Usage_Checker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MEDIA_USAGE_CHECKER_VERSION', '3.2.0' );
define( 'MEDIA_USAGE_CHECKER_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MEDIA_USAGE_CHECKER_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MEDIA_USAGE_CHECKER_CRON_HOOK', 'media_usage_checker_cron_scan' );
define( 'MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK', 'media_usage_checker_cron_maintenance' );

if ( ! function_exists( 'media_usage_checker_get_filesystem' ) ) {
	function media_usage_checker_get_filesystem() {
		global $wp_filesystem;
		if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			$file_api = ABSPATH . 'wp-admin/includes/file.php';
			require_once $file_api;
		}

		if ( function_exists( 'WP_Filesystem' ) ) {
			WP_Filesystem();
		}

		if ( $wp_filesystem && is_object( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			$base   = ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			$direct = ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
			require_once $base;
			require_once $direct;
		}

		if ( class_exists( 'WP_Filesystem_Direct' ) ) {
			return new WP_Filesystem_Direct( null );
		}

		return null;
	}
}

if ( ! function_exists( 'media_usage_checker_filesize' ) ) {
	function media_usage_checker_filesize( $path ) {
		$path = is_string( $path ) ? $path : '';
		if ( '' === $path ) {
			return 0;
		}

		$fs = media_usage_checker_get_filesystem();
		if ( ! $fs || ! method_exists( $fs, 'exists' ) || ! $fs->exists( $path ) ) {
			return 0;
		}

		if ( method_exists( $fs, 'size' ) ) {
			return (int) $fs->size( $path );
		}

		return 0;
	}
}

function media_usage_checker_add_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'media-usage-checker' ),
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'media_usage_checker_add_cron_schedules' );

require_once MEDIA_USAGE_CHECKER_PLUGIN_DIR . 'includes/class-muc-logger.php';
require_once MEDIA_USAGE_CHECKER_PLUGIN_DIR . 'includes/class-muc-validator.php';
require_once MEDIA_USAGE_CHECKER_PLUGIN_DIR . 'includes/class-muc-scanner.php';
require_once MEDIA_USAGE_CHECKER_PLUGIN_DIR . 'includes/class-muc-admin.php';

function media_usage_checker_init_plugin() {
	if ( is_admin() ) {
		Media_Usage_Checker_Admin::get_instance();
	}

	$stored_version = get_option( 'muc_version' );
	if ( MEDIA_USAGE_CHECKER_VERSION !== $stored_version ) {
		update_option( 'muc_version', MEDIA_USAGE_CHECKER_VERSION );
	}

	if ( ! wp_next_scheduled( MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK ) ) {
		media_usage_checker_schedule_maintenance_cron();
	}
}
add_action( 'plugins_loaded', 'media_usage_checker_init_plugin' );

function media_usage_checker_schedule_cron( $frequency = null ) {
	if ( ! empty( $frequency ) && is_string( $frequency ) ) {
		$frequency = sanitize_key( $frequency );
	} else {
		$frequency = sanitize_key( (string) get_option( 'muc_scan_frequency', 'daily' ) );
	}

	$allowed = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
	if ( ! in_array( $frequency, $allowed, true ) ) {
		$frequency = 'daily';
	}

	$next = wp_next_scheduled( MEDIA_USAGE_CHECKER_CRON_HOOK );
	if ( $next ) {
		wp_unschedule_event( $next, MEDIA_USAGE_CHECKER_CRON_HOOK );
	}

	wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, MEDIA_USAGE_CHECKER_CRON_HOOK );
}

function media_usage_checker_schedule_maintenance_cron() {
	$next = wp_next_scheduled( MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK );
	if ( $next ) {
		wp_unschedule_event( $next, MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK );
	}

	wp_schedule_event( time() + 2 * MINUTE_IN_SECONDS, 'daily', MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK );
}

function media_usage_checker_unschedule_maintenance_cron() {
	$next = wp_next_scheduled( MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK );
	while ( $next ) {
		wp_unschedule_event( $next, MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK );
		$next = wp_next_scheduled( MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK );
	}
}

function media_usage_checker_unschedule_cron() {
	$next = wp_next_scheduled( MEDIA_USAGE_CHECKER_CRON_HOOK );
	while ( $next ) {
		wp_unschedule_event( $next, MEDIA_USAGE_CHECKER_CRON_HOOK );
		$next = wp_next_scheduled( MEDIA_USAGE_CHECKER_CRON_HOOK );
	}
}

function media_usage_checker_activate() {
	if ( false === get_option( 'muc_used_count', false ) ) {
		update_option( 'muc_used_count', 0 );
	}

	if ( false === get_option( 'muc_unused_count', false ) ) {
		update_option( 'muc_unused_count', 0 );
	}

	if ( false === get_option( 'muc_scan_frequency', false ) ) {
		update_option( 'muc_scan_frequency', 'daily' );
	}

	media_usage_checker_schedule_cron();
	media_usage_checker_schedule_maintenance_cron();
}

function media_usage_checker_deactivate() {
	media_usage_checker_unschedule_cron();
	media_usage_checker_unschedule_maintenance_cron();
	delete_option( 'muc_cron_page' );
	delete_option( 'muc_cron_running' );
}

function media_usage_checker_run_cron_scan() {
	if ( is_admin() && wp_doing_ajax() ) {
		return;
	}

	if ( wp_doing_cron() && '1' === (string) get_option( 'muc_cron_running', '0' ) ) {
		return;
	}

	update_option( 'muc_cron_running', '1', false );

	$page = absint( get_option( 'muc_cron_page', 1 ) );
	if ( $page < 1 ) {
		$page = 1;
	}

	$batch_size = function_exists( 'media_usage_checker_is_pro' ) && media_usage_checker_is_pro()
		? absint( get_option( 'muc_batch_size', 100 ) )
		: 20;
	if ( $batch_size < 1 ) {
		$batch_size = function_exists( 'media_usage_checker_is_pro' ) && media_usage_checker_is_pro() ? 100 : 20;
	}

	$scanner   = Media_Usage_Checker_Scanner::get_instance();
	$processed = $scanner->scan_batch( $page, $batch_size );

	if ( $processed < $batch_size ) {
		$scanner->calculate_stats_from_meta();
		update_option( 'muc_cron_page', 1, false );
	} else {
		update_option( 'muc_cron_page', $page + 1, false );
	}

	update_option( 'muc_cron_running', '0', false );
}

add_action( MEDIA_USAGE_CHECKER_CRON_HOOK, 'media_usage_checker_run_cron_scan' );

function media_usage_checker_run_maintenance() {
	if ( ! media_usage_checker_is_pro() ) {
		return;
	}

	$scanner = Media_Usage_Checker_Scanner::get_instance();

	$auto_trash_enabled = '1' === (string) get_option( 'muc_pro_auto_trash_enabled', '0' );
	if ( $auto_trash_enabled ) {
		$min_days = absint( get_option( 'muc_pro_auto_trash_min_days', 0 ) );
		$limit    = absint( get_option( 'muc_pro_auto_trash_limit', 50 ) );
		$scanner->cron_auto_trash_unused( $limit, $min_days );
	}

	$purge_enabled = '1' === (string) get_option( 'muc_pro_auto_purge_enabled', '0' );
	if ( $purge_enabled ) {
		$days  = absint( get_option( 'muc_pro_trash_retention_days', 30 ) );
		$limit = absint( get_option( 'muc_pro_auto_purge_limit', 50 ) );
		$scanner->cron_purge_trash_older_than( $days, $limit );
	}

	$report_enabled = '1' === (string) get_option( 'muc_pro_weekly_report_enabled', '0' );
	if ( $report_enabled ) {
		$last_sent = absint( get_option( 'muc_pro_report_last_sent', 0 ) );
		$now       = current_time( 'timestamp' );
		if ( $last_sent < 1 || ( $now - $last_sent ) >= 7 * DAY_IN_SECONDS ) {
			$to = get_option( 'muc_pro_report_email_to', '' );
			$to = is_string( $to ) && is_email( $to ) ? $to : get_option( 'admin_email' );

			$used_count    = absint( get_option( 'muc_used_count', 0 ) );
			$unused_count  = absint( get_option( 'muc_unused_count', 0 ) );
			$unused_size   = absint( get_option( 'muc_unused_size', 0 ) );
			$trashed_count = absint( get_option( 'muc_trashed_count', 0 ) );

			$subject = __( 'Media Usage Checker: Weekly Report', 'media-usage-checker' );
			$dashboard_url = admin_url( 'admin.php?page=media-usage-checker&tab=dashboard' );
			$unused_url    = admin_url( 'admin.php?page=media-usage-checker&tab=unused-files' );
			$trash_url     = admin_url( 'admin.php?page=media-usage-checker&tab=trash' );
			$activity_url  = admin_url( 'admin.php?page=media-usage-checker&tab=activity' );

			$log = get_option( 'muc_pro_audit_log', array() );
			$summary = array(
				'trash_manual'  => 0,
				'trash_auto'    => 0,
				'restore'       => 0,
				'delete_manual' => 0,
				'delete_auto'   => 0,
			);
			if ( is_array( $log ) && ! empty( $log ) ) {
				foreach ( $log as $entry ) {
					$ts = isset( $entry['ts'] ) ? absint( $entry['ts'] ) : 0;
					if ( $ts < 1 || $ts <= $last_sent ) {
						continue;
					}
					$action = isset( $entry['action'] ) ? sanitize_key( $entry['action'] ) : '';
					$mode   = isset( $entry['mode'] ) ? sanitize_key( $entry['mode'] ) : '';
					if ( 'trash' === $action ) {
						if ( 'auto' === $mode ) {
							$summary['trash_auto']++;
						} else {
							$summary['trash_manual']++;
						}
					} elseif ( 'delete' === $action ) {
						if ( 'auto' === $mode ) {
							$summary['delete_auto']++;
						} else {
							$summary['delete_manual']++;
						}
					} elseif ( 'restore' === $action ) {
						$summary['restore']++;
					}
				}
			}

			$message = implode(
				"\n",
				array(
					sprintf( __( 'Used: %d', 'media-usage-checker' ), $used_count ),
					sprintf( __( 'Unused: %d', 'media-usage-checker' ), $unused_count ),
					sprintf( __( 'Potential savings: %s', 'media-usage-checker' ), size_format( $unused_size ) ),
					sprintf( __( 'Items in Trash: %d', 'media-usage-checker' ), $trashed_count ),
					'',
					__( 'Changes since last report:', 'media-usage-checker' ),
					sprintf( __( 'Auto-trashed: %d', 'media-usage-checker' ), $summary['trash_auto'] ),
					sprintf( __( 'Manually trashed: %d', 'media-usage-checker' ), $summary['trash_manual'] ),
					sprintf( __( 'Auto-deleted: %d', 'media-usage-checker' ), $summary['delete_auto'] ),
					sprintf( __( 'Manually deleted: %d', 'media-usage-checker' ), $summary['delete_manual'] ),
					sprintf( __( 'Restored: %d', 'media-usage-checker' ), $summary['restore'] ),
					'',
					__( 'Quick links:', 'media-usage-checker' ),
					$dashboard_url,
					$unused_url,
					$trash_url,
					$activity_url,
				)
			);

			wp_mail( $to, $subject, $message );
			update_option( 'muc_pro_report_last_sent', $now, false );
		}
	}
}

add_action( MEDIA_USAGE_CHECKER_CRON_MAINT_HOOK, 'media_usage_checker_run_maintenance' );

register_activation_hook( __FILE__, 'media_usage_checker_activate' );
register_deactivation_hook( __FILE__, 'media_usage_checker_deactivate' );
