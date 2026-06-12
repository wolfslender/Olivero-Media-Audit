<?php
/**
 * Plugin Name: OliveroDev Media Audit – Media Library Cleaner & Optimizer
 * Description: Find and delete unused media files in your WordPress media library. Smart scanning, safe cleanup, and storage optimization — completely free.
 * Version: 3.4.6
 * Requires at least: 5.0
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Author: Alexis Olivero
 * Author URI: https://oliverodev.com/
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: oliverodev-media-audit
 * Domain Path: /languages
 *
 * @package Oliverodev_Media_Audit
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OLIVERODEV_MEDIA_AUDIT_VERSION', '3.4.6' );
define( 'OLIVERODEV_MEDIA_AUDIT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'OLIVERODEV_MEDIA_AUDIT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'OLIVERODEV_MEDIA_AUDIT_CRON_HOOK', 'oliverodev_media_audit_cron_scan' );

function oliverodev_media_audit_filesize( $path ) {
	$path = is_string( $path ) ? $path : '';
	if ( '' === $path || ! @file_exists( $path ) ) {
		return 0;
	}
	$real_path = (string) realpath( $path );
	if ( '' === $real_path ) {
		$real_path = $path;
	}
	$uploads  = wp_upload_dir();
	$base_dir = (string) realpath( $uploads['basedir'] );
	if ( '' === $base_dir ) {
		$base_dir = wp_normalize_path( (string) $uploads['basedir'] );
	}
	if ( 0 !== strpos( $real_path, $base_dir . '/' ) && $real_path !== $base_dir ) {
		return 0;
	}
	$size = @filesize( $real_path );
	return ( false !== $size ) ? (int) $size : 0;
}

function oliverodev_media_audit_add_cron_schedules( $schedules ) {
	if ( ! isset( $schedules['weekly'] ) ) {
		$schedules['weekly'] = array(
			'interval' => 7 * DAY_IN_SECONDS,
			'display'  => __( 'Once Weekly', 'oliverodev-media-audit' ),
		);
	}

	return $schedules;
}
add_filter( 'cron_schedules', 'oliverodev_media_audit_add_cron_schedules' );

require_once OLIVERODEV_MEDIA_AUDIT_PLUGIN_DIR . 'includes/class-muc-logger.php';
require_once OLIVERODEV_MEDIA_AUDIT_PLUGIN_DIR . 'includes/class-muc-validator.php';
require_once OLIVERODEV_MEDIA_AUDIT_PLUGIN_DIR . 'includes/class-muc-scanner.php';
require_once OLIVERODEV_MEDIA_AUDIT_PLUGIN_DIR . 'includes/class-muc-admin.php';

function oliverodev_media_audit_is_pro() {
	return (bool) apply_filters( 'oliverodev_media_audit_is_pro', false );
}

function oliverodev_media_audit_get_pro_provider() {
	return apply_filters( 'oliverodev_media_audit_pro_provider', null );
}

function oliverodev_media_audit_init_plugin() {
	load_plugin_textdomain( 'oliverodev-media-audit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( is_admin() ) {
		Oliverodev_Media_Audit_Admin::get_instance();
	}

	$stored_version = get_option( 'oliverodev_media_audit_version' );
	if ( OLIVERODEV_MEDIA_AUDIT_VERSION !== $stored_version ) {
		update_option( 'oliverodev_media_audit_version', OLIVERODEV_MEDIA_AUDIT_VERSION );
	}

	// Clear Elementor CSS list transient when a post is saved, so the scanner
	// picks up newly generated CSS files on the next scan instead of stale data.
	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		add_action( 'save_post', 'oliverodev_media_audit_clear_el_transients' );
	}

	// Signal that the FREE plugin is fully loaded.
	// The PRO addon listens for this action to initialize safely.
	do_action( 'oliverodev_media_audit_loaded' );
}

function oliverodev_media_audit_clear_el_transients( $post_id ) {
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
	if ( wp_is_post_revision( $post_id ) ) return;
	delete_transient( 'omau_el_css_list' );
}
add_action( 'plugins_loaded', 'oliverodev_media_audit_init_plugin' );

function oliverodev_media_audit_schedule_cron( $frequency = null ) {
	if ( ! empty( $frequency ) && is_string( $frequency ) ) {
		$frequency = sanitize_key( $frequency );
	} else {
		$frequency = sanitize_key( (string) get_option( 'oliverodev_media_audit_scan_frequency', 'daily' ) );
	}

	$allowed = array( 'hourly', 'twicedaily', 'daily', 'weekly' );
	if ( ! in_array( $frequency, $allowed, true ) ) {
		$frequency = 'daily';
	}

	$next = wp_next_scheduled( OLIVERODEV_MEDIA_AUDIT_CRON_HOOK );
	if ( $next ) {
		wp_unschedule_event( $next, OLIVERODEV_MEDIA_AUDIT_CRON_HOOK );
	}

	wp_schedule_event( time() + MINUTE_IN_SECONDS, $frequency, OLIVERODEV_MEDIA_AUDIT_CRON_HOOK );
}

function oliverodev_media_audit_unschedule_cron() {
	$next = wp_next_scheduled( OLIVERODEV_MEDIA_AUDIT_CRON_HOOK );
	while ( $next ) {
		wp_unschedule_event( $next, OLIVERODEV_MEDIA_AUDIT_CRON_HOOK );
		$next = wp_next_scheduled( OLIVERODEV_MEDIA_AUDIT_CRON_HOOK );
	}
}

function oliverodev_media_audit_activate() {
	if ( false === get_option( 'oliverodev_media_audit_used_count', false ) ) {
		update_option( 'oliverodev_media_audit_used_count', 0 );
	}

	if ( false === get_option( 'oliverodev_media_audit_unused_count', false ) ) {
		update_option( 'oliverodev_media_audit_unused_count', 0 );
	}

	if ( false === get_option( 'oliverodev_media_audit_scan_frequency', false ) ) {
		update_option( 'oliverodev_media_audit_scan_frequency', 'daily' );
	}

	oliverodev_media_audit_schedule_cron();
}

function oliverodev_media_audit_deactivate() {
	oliverodev_media_audit_unschedule_cron();
	delete_option( 'oliverodev_media_audit_cron_page' );
	delete_option( 'oliverodev_media_audit_cron_running' );
}

function oliverodev_media_audit_run_cron_scan() {
	if ( is_admin() && wp_doing_ajax() ) {
		return;
	}

	if ( wp_doing_cron() && '1' === (string) get_option( 'oliverodev_media_audit_cron_running', '0' ) ) {
		return;
	}

	update_option( 'oliverodev_media_audit_cron_running', '1', false );

	$offset = absint( get_option( 'oliverodev_media_audit_cron_offset', 0 ) );

	$batch_size = absint( get_option( 'oliverodev_media_audit_batch_size', 20 ) );
	if ( $batch_size < 1 ) {
		$batch_size = 20;
	}
	if ( $batch_size > 200 ) {
		$batch_size = 200;
	}

	$scanner = Oliverodev_Media_Audit_Scanner::get_instance();
	$result  = $scanner->scan_batch( $offset, $batch_size );
	$processed = is_array( $result ) ? (int) $result['processed'] : (int) $result;

	$total = $scanner->get_total_attachments();
	if ( $processed === 0 || ( $offset + $processed ) >= $total ) {
		$scanner->calculate_stats_from_meta();
		update_option( 'oliverodev_media_audit_cron_offset', 0, false );
	} else {
		update_option( 'oliverodev_media_audit_cron_offset', $offset + $processed, false );
	}

	update_option( 'oliverodev_media_audit_cron_running', '0', false );
}

add_action( OLIVERODEV_MEDIA_AUDIT_CRON_HOOK, 'oliverodev_media_audit_run_cron_scan' );

register_activation_hook( __FILE__, 'oliverodev_media_audit_activate' );
register_deactivation_hook( __FILE__, 'oliverodev_media_audit_deactivate' );
