<?php
/**
 * Plugin Name: JCP Session Tracker
 * Description: Tracks first-party visitor sessions and pageviews with WordPress admin reporting.
 * Version: 1.1.6	
 * Author: Porter Olson
 * License: GPL-2.0-or-later
 * Text Domain: jcp-session-tracker
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JCPST_VERSION', '1.1.6' );
define( 'JCPST_PLUGIN_FILE', __FILE__ );
define( 'JCPST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JCPST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-installer.php';
require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-settings.php';
require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-cleanup.php';
require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-tracker.php';
require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-sessions-list-table.php';
require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-admin.php';
require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-user-profile.php';
require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-recent-jobs.php';
require_once JCPST_PLUGIN_DIR . 'includes/class-jcpst-plugin.php';

register_activation_hook( JCPST_PLUGIN_FILE, array( 'JCPST_Installer', 'activate' ) );
register_deactivation_hook( JCPST_PLUGIN_FILE, array( 'JCPST_Installer', 'deactivate' ) );

JCPST_Plugin::instance();
