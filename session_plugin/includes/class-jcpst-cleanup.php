<?php
/**
 * Retention cleanup.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JCPST_Cleanup {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'jcpst_cleanup_event', array( __CLASS__, 'run' ) );
	}

	/**
	 * Schedule cron.
	 *
	 * @return void
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( 'jcpst_cleanup_event' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'jcpst_cleanup_event' );
		}
	}

	/**
	 * Unschedule cron.
	 *
	 * @return void
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( 'jcpst_cleanup_event' );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'jcpst_cleanup_event' );
		}
	}

	/**
	 * Execute retention cleanup.
	 *
	 * @return void
	 */
	public static function run() {
		global $wpdb;

		$settings        = JCPST_Settings::get();
		$sessions_table  = $wpdb->prefix . 'jcpst_sessions';
		$pageviews_table = $wpdb->prefix . 'jcpst_pageviews';
		$pageview_cutoff = gmdate( 'Y-m-d H:i:s', time() - ( absint( $settings['retention_pageviews'] ) * DAY_IN_SECONDS ) );
		$session_cutoff  = gmdate( 'Y-m-d H:i:s', time() - ( absint( $settings['retention_sessions'] ) * DAY_IN_SECONDS ) );

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$pageviews_table} WHERE visited_at < %s",
				$pageview_cutoff
			)
		);

		$expired_session_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT session_id FROM {$sessions_table} WHERE last_activity < %s",
				$session_cutoff
			)
		);

		if ( ! empty( $expired_session_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $expired_session_ids ), '%s' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$pageviews_table} WHERE session_id IN ({$placeholders})",
					$expired_session_ids
				)
			);
		}

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$sessions_table} WHERE last_activity < %s",
				$session_cutoff
			)
		);
	}
}
