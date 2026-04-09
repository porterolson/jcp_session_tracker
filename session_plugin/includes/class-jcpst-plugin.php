<?php
/**
 * Main plugin bootstrap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JCPST_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var JCPST_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Tracker instance.
	 *
	 * @var JCPST_Tracker
	 */
	private $tracker;

	/**
	 * Admin instance.
	 *
	 * @var JCPST_Admin|null
	 */
	private $admin;

	/**
	 * Cleanup instance.
	 *
	 * @var JCPST_Cleanup
	 */
	private $cleanup;

	/**
	 * User profile instance.
	 *
	 * @var JCPST_User_Profile|null
	 */
	private $user_profile;

	/**
	 * Recently viewed jobs instance.
	 *
	 * @var JCPST_Recent_Jobs
	 */
	private $recent_jobs;

	/**
	 * Get singleton instance.
	 *
	 * @return JCPST_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->tracker      = new JCPST_Tracker();
		$this->cleanup      = new JCPST_Cleanup();
		$this->admin        = is_admin() ? new JCPST_Admin() : null;
		$this->user_profile = is_admin() ? new JCPST_User_Profile() : null;
		$this->recent_jobs  = new JCPST_Recent_Jobs();

		add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load translation files.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'jcp-session-tracker', false, dirname( plugin_basename( JCPST_PLUGIN_FILE ) ) . '/languages' );
	}
}
