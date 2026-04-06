<?php
/**
 * Plugin settings helper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JCPST_Settings {

	/**
	 * Option name.
	 */
	const OPTION_NAME = 'jcpst_settings';

	/**
	 * Get defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults() {
		return array(
			'inactivity_timeout'       => 30,
			'cookie_lifetime'          => 30,
			'cookie_name'              => 'jcp_session_id',
			'track_guests'             => 1,
			'track_logged_in'          => 1,
			'track_admins'             => 0,
			'track_wp_admin'           => 0,
			'track_ajax'               => 0,
			'retention_pageviews'      => 90,
			'retention_sessions'       => 365,
			'delete_data_on_uninstall' => 0,
			'bot_filtering'            => 1,
			'trust_proxy_headers'      => 0,
			'dedupe_window'            => 5,
			'hide_noise_sessions'      => 1,
		);
	}

	/**
	 * Get merged settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function get() {
		$saved = get_option( self::OPTION_NAME, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Ensure defaults exist.
	 *
	 * @return void
	 */
	public static function maybe_set_defaults() {
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, self::defaults() );
		}
	}

	/**
	 * Sanitize settings payload.
	 *
	 * @param array<string, mixed> $input Raw input.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$output   = array();

		$output['inactivity_timeout']       = max( 1, absint( self::array_get( $input, 'inactivity_timeout', $defaults['inactivity_timeout'] ) ) );
		$output['cookie_lifetime']          = max( 1, absint( self::array_get( $input, 'cookie_lifetime', $defaults['cookie_lifetime'] ) ) );
		$output['cookie_name']              = self::sanitize_cookie_name( self::array_get( $input, 'cookie_name', $defaults['cookie_name'] ) );
		$output['track_guests']             = self::sanitize_checkbox( $input, 'track_guests' );
		$output['track_logged_in']          = self::sanitize_checkbox( $input, 'track_logged_in' );
		$output['track_admins']             = self::sanitize_checkbox( $input, 'track_admins' );
		$output['track_wp_admin']           = self::sanitize_checkbox( $input, 'track_wp_admin' );
		$output['track_ajax']               = self::sanitize_checkbox( $input, 'track_ajax' );
		$output['retention_pageviews']      = max( 1, absint( self::array_get( $input, 'retention_pageviews', $defaults['retention_pageviews'] ) ) );
		$output['retention_sessions']       = max( 1, absint( self::array_get( $input, 'retention_sessions', $defaults['retention_sessions'] ) ) );
		$output['delete_data_on_uninstall'] = self::sanitize_checkbox( $input, 'delete_data_on_uninstall' );
		$output['bot_filtering']            = self::sanitize_checkbox( $input, 'bot_filtering' );
		$output['trust_proxy_headers']      = self::sanitize_checkbox( $input, 'trust_proxy_headers' );
		$output['dedupe_window']            = max( 1, absint( self::array_get( $input, 'dedupe_window', $defaults['dedupe_window'] ) ) );
		$output['hide_noise_sessions']      = self::sanitize_checkbox( $input, 'hide_noise_sessions' );

		return $output;
	}

	/**
	 * Get value from array.
	 *
	 * @param array<string, mixed> $array Array.
	 * @param string               $key Key.
	 * @param mixed                $default Default.
	 * @return mixed
	 */
	private static function array_get( $array, $key, $default ) {
		return isset( $array[ $key ] ) ? $array[ $key ] : $default;
	}

	/**
	 * Sanitize checkbox.
	 *
	 * @param array<string, mixed> $array Input.
	 * @param string               $key Key.
	 * @return int
	 */
	private static function sanitize_checkbox( $array, $key ) {
		return empty( $array[ $key ] ) ? 0 : 1;
	}

	/**
	 * Sanitize cookie names to RFC-safe characters.
	 *
	 * @param mixed $name Raw name.
	 * @return string
	 */
	private static function sanitize_cookie_name( $name ) {
		$name = sanitize_key( (string) $name );
		return $name ? $name : 'jcp_session_id';
	}
}
