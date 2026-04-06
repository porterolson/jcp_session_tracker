<?php
/**
 * Installation and schema management.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JCPST_Installer {

	/**
	 * DB schema version.
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Activate plugin.
	 *
	 * @return void
	 */
	public static function activate() {
		self::create_tables();
		JCPST_Settings::maybe_set_defaults();
		JCPST_Cleanup::schedule();

		update_option( 'jcpst_db_version', self::DB_VERSION );
	}

	/**
	 * Deactivate plugin.
	 *
	 * @return void
	 */
	public static function deactivate() {
		JCPST_Cleanup::unschedule();
	}

	/**
	 * Create custom tables.
	 *
	 * @return void
	 */
	public static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$sessions_table  = $wpdb->prefix . 'jcpst_sessions';
		$pageviews_table = $wpdb->prefix . 'jcpst_pageviews';

		$sessions_sql = "CREATE TABLE {$sessions_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(128) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			session_start datetime NOT NULL,
			last_activity datetime NOT NULL,
			session_end datetime DEFAULT NULL,
			total_pageviews bigint(20) unsigned NOT NULL DEFAULT 0,
			landing_page longtext NULL,
			exit_page longtext NULL,
			first_referrer longtext NULL,
			first_ip varchar(100) NULL,
			last_ip varchar(100) NULL,
			ip_hash varchar(128) NULL,
			user_agent longtext NULL,
			device_summary varchar(255) NULL,
			is_logged_in tinyint(1) NOT NULL DEFAULT 0,
			login_state_changed tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY session_id (session_id),
			KEY user_id (user_id),
			KEY session_start (session_start),
			KEY last_activity (last_activity)
		) {$charset_collate};";

		$pageviews_sql = "CREATE TABLE {$pageviews_table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(128) NOT NULL,
			user_id bigint(20) unsigned DEFAULT NULL,
			visited_at datetime NOT NULL,
			page_url longtext NULL,
			path longtext NULL,
			query_string longtext NULL,
			referrer longtext NULL,
			ip varchar(100) NULL,
			user_agent longtext NULL,
			post_id bigint(20) unsigned DEFAULT NULL,
			page_title text NULL,
			is_admin tinyint(1) NOT NULL DEFAULT 0,
			is_ajax tinyint(1) NOT NULL DEFAULT 0,
			is_logged_in tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY session_id (session_id),
			KEY user_id (user_id),
			KEY visited_at (visited_at),
			KEY post_id (post_id)
		) {$charset_collate};";

		dbDelta( $sessions_sql );
		dbDelta( $pageviews_sql );
	}
}
