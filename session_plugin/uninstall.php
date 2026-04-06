<?php
/**
 * Uninstall handler for JCP Session Tracker.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$options = get_option( 'jcpst_settings', array() );

if ( empty( $options['delete_data_on_uninstall'] ) ) {
	return;
}

$sessions_table  = $wpdb->prefix . 'jcpst_sessions';
$pageviews_table = $wpdb->prefix . 'jcpst_pageviews';

$wpdb->query( "DROP TABLE IF EXISTS {$pageviews_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$sessions_table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'jcpst_settings' );
delete_option( 'jcpst_db_version' );
delete_option( 'jcpst_cleanup_version' );

$timestamp = wp_next_scheduled( 'jcpst_cleanup_event' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'jcpst_cleanup_event' );
}
