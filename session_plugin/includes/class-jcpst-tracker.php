<?php
/**
 * Session and pageview tracking.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JCPST_Tracker {

	/**
	 * Maximum session insert retries on collision.
	 */
	const MAX_SESSION_INSERT_ATTEMPTS = 5;

	/**
	 * Request context cache.
	 *
	 * @var array<string, mixed>|null
	 */
	private $request_context = null;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_upgrade_schema' ) );
		add_action( 'template_redirect', array( $this, 'track_front_request' ), 1 );
		add_action( 'admin_init', array( $this, 'track_admin_request' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_tracking_script' ) );
		add_action( 'wp_ajax_jcpst_track_pageview', array( $this, 'handle_async_track_pageview' ) );
		add_action( 'wp_ajax_nopriv_jcpst_track_pageview', array( $this, 'handle_async_track_pageview' ) );
		add_action( 'wp_ajax_nopriv_jcpst_track_pageview_get', array( $this, 'handle_async_track_pageview' ) );
		add_action( 'wp_ajax_jcpst_track_pageview_get', array( $this, 'handle_async_track_pageview' ) );
		add_action( 'wp_login', array( $this, 'handle_login' ), 10, 2 );
		add_action( 'wp_logout', array( $this, 'handle_logout' ) );
		add_action( 'set_current_user', array( $this, 'sync_logged_in_session_state' ) );
	}

	/**
	 * Ensure schema exists after updates.
	 *
	 * @return void
	 */
	public function maybe_upgrade_schema() {
		if ( get_option( 'jcpst_db_version' ) !== JCPST_Installer::DB_VERSION ) {
			JCPST_Installer::create_tables();
			update_option( 'jcpst_db_version', JCPST_Installer::DB_VERSION );
		}
	}

	/**
	 * Track front-end requests.
	 *
	 * @return void
	 */
	public function track_front_request() {
		if ( ! is_user_logged_in() && ! $this->should_track_guest_server_side() ) {
			return;
		}

		$this->track_request( false );
	}

	/**
	 * Track admin requests when enabled.
	 *
	 * @return void
	 */
	public function track_admin_request() {
		$this->track_request( true );
	}

	/**
	 * Core tracking flow.
	 *
	 * @param bool $is_admin_request Whether request is in admin.
	 * @return void
	 */
	private function track_request( $is_admin_request ) {
		$context = $this->build_request_context( $is_admin_request );

		if ( ! $this->should_track_request( $context ) ) {
			return;
		}

		$session = $this->get_or_create_session( $context );
		if ( empty( $session['session_id'] ) ) {
			return;
		}

		if ( $this->is_duplicate_pageview( $session['session_id'], $context ) ) {
			$this->touch_session_activity( $session, $context );
			return;
		}

		$this->record_pageview( $session, $context );
		$this->update_session_after_pageview( $session, $context );
	}

	/**
	 * Handle user logins occurring mid-session.
	 *
	 * @param string  $user_login Username.
	 * @param WP_User $user User object.
	 * @return void
	 */
	public function handle_login( $user_login, $user ) {
		unset( $user_login );
		if ( ! ( $user instanceof WP_User ) ) {
			return;
		}

		$session_id = $this->get_cookie_session_id();
		if ( ! $session_id ) {
			return;
		}

		$this->associate_session_with_user( $session_id, (int) $user->ID );
	}

	/**
	 * Close the current authenticated session on logout.
	 *
	 * @return void
	 */
	public function handle_logout() {
		$session_id = $this->get_cookie_session_id();
		if ( ! $session_id ) {
			return;
		}

		$this->close_session( $session_id, current_time( 'mysql', true ) );
		$this->clear_session_cookie();
	}

	/**
	 * Sync session state with current auth state.
	 *
	 * @return void
	 */
	public function sync_logged_in_session_state() {
		$session_id = $this->get_cookie_session_id();
		if ( ! $session_id || ! is_user_logged_in() ) {
			return;
		}

		$this->associate_session_with_user( $session_id, get_current_user_id() );
	}

	/**
	 * Enqueue front-end tracking script.
	 *
	 * @return void
	 */
	public function enqueue_tracking_script() {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}

		$context = $this->build_request_context( false );
		if ( ! $this->should_track_request( $context ) ) {
			return;
		}

		wp_register_script(
			'jcpst-tracker',
			JCPST_PLUGIN_URL . 'assets/js/jcpst-tracker.js',
			array(),
			JCPST_VERSION,
			true
		);

		wp_localize_script(
			'jcpst-tracker',
			'jcpstTracker',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'jcpst_track_pageview' ),
				'enabled' => true,
			)
		);

		wp_enqueue_script( 'jcpst-tracker' );
	}

	/**
	 * Handle async browser beacon requests.
	 *
	 * @return void
	 */
	public function handle_async_track_pageview() {
		nocache_headers();

		$raw_request = ! empty( $_POST ) ? $_POST : $_GET;

		if ( isset( $raw_request['_jcpst_nonce'] ) && ! wp_verify_nonce( sanitize_text_field( wp_unslash( $raw_request['_jcpst_nonce'] ) ), 'jcpst_track_pageview' ) ) {
			wp_send_json_error( array( 'reason' => 'invalid_nonce' ), 403 );
		}

		$context = $this->build_async_request_context();
		if ( ! $context || ! $this->should_track_request( $context ) ) {
			wp_send_json_error( array( 'reason' => 'not_trackable' ), 400 );
		}

		$session = $this->get_or_create_session( $context );
		if ( empty( $session['session_id'] ) ) {
			wp_send_json_error( array( 'reason' => 'session_creation_failed' ), 500 );
		}

		if ( $this->is_duplicate_pageview( $session['session_id'], $context ) ) {
			$this->touch_session_activity( $session, $context );
			wp_send_json_success(
				array(
					'session_id' => $session['session_id'],
					'ip'         => $context['ip'],
					'duplicate'  => true,
				)
			);
		}

		$this->record_pageview( $session, $context );
		$this->update_session_after_pageview( $session, $context );

		wp_send_json_success(
			array(
				'session_id' => $session['session_id'],
				'ip'         => $context['ip'],
				'duplicate'  => false,
			)
		);
	}

	/**
	 * Build request context once per request.
	 *
	 * @param bool $is_admin_request Whether request is admin.
	 * @return array<string, mixed>
	 */
	private function build_request_context( $is_admin_request ) {
		if ( null !== $this->request_context && (bool) $this->request_context['is_admin'] === (bool) $is_admin_request ) {
			return $this->request_context;
		}

		$settings      = JCPST_Settings::get();
		$server        = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_uri   = isset( $server['REQUEST_URI'] ) ? (string) $server['REQUEST_URI'] : '/';
		$parsed_uri    = wp_parse_url( $request_uri );
		$scheme        = is_ssl() ? 'https' : 'http';
		$host          = isset( $server['HTTP_HOST'] ) ? sanitize_text_field( $server['HTTP_HOST'] ) : wp_parse_url( home_url(), PHP_URL_HOST );
		$path          = isset( $parsed_uri['path'] ) ? $parsed_uri['path'] : '/';
		$query_string  = isset( $parsed_uri['query'] ) ? $parsed_uri['query'] : '';
		$referrer      = wp_get_raw_referer();
		$user_agent    = isset( $server['HTTP_USER_AGENT'] ) ? sanitize_text_field( $server['HTTP_USER_AGENT'] ) : '';
		$ip_address    = $this->detect_ip_address( $settings, $server );
		$user_id       = get_current_user_id();
		$post_id       = $is_admin_request ? 0 : get_queried_object_id();
		$page_title    = $this->resolve_page_title( $is_admin_request, $post_id );
		$is_ajax       = wp_doing_ajax();
		$is_rest       = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'rest_get_url_prefix' ) && 0 === strpos( ltrim( $path, '/' ), trim( rest_get_url_prefix(), '/' ) ) );
		$is_heartbeat  = $is_ajax && isset( $server['REQUEST_METHOD'], $_REQUEST['action'] ) && 'POST' === strtoupper( $server['REQUEST_METHOD'] ) && 'heartbeat' === sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		$is_prefetch   = $this->is_prefetch_request( $server );
		$is_bot        = ! empty( $settings['bot_filtering'] ) && $this->is_bot_request( $user_agent );

		$this->request_context = array(
			'settings'       => $settings,
			'request_uri'    => $request_uri,
			'page_url'       => esc_url_raw( home_url( $request_uri ) ),
			'path'           => sanitize_text_field( $path ),
			'query_string'   => sanitize_text_field( $query_string ),
			'referrer'       => $referrer ? esc_url_raw( $referrer ) : '',
			'ip'             => $ip_address,
			'user_agent'     => $user_agent,
			'user_id'        => $user_id ? (int) $user_id : null,
			'post_id'        => $post_id ? (int) $post_id : null,
			'page_title'     => $page_title,
			'is_admin'       => (bool) $is_admin_request,
			'is_ajax'        => $is_ajax,
			'is_rest'        => $is_rest,
			'is_heartbeat'   => $is_heartbeat,
			'is_prefetch'    => $is_prefetch,
			'is_bot'         => $is_bot,
			'is_logged_in'   => is_user_logged_in(),
			'is_admin_user'  => current_user_can( 'manage_options' ),
			'timestamp'      => current_time( 'mysql', true ),
			'device_summary' => $this->summarize_device( $user_agent ),
		);

		return $this->request_context;
	}

	/**
	 * Build an async tracking context from browser beacon payload.
	 *
	 * @return array<string, mixed>|null
	 */
	private function build_async_request_context() {
		$settings     = JCPST_Settings::get();
		$server       = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$request_data = ! empty( $_POST ) ? wp_unslash( $_POST ) : wp_unslash( $_GET );
		$page_url_raw = isset( $request_data['page_url'] ) ? esc_url_raw( $request_data['page_url'] ) : '';
		$path_raw     = isset( $request_data['path'] ) ? sanitize_text_field( $request_data['path'] ) : '/';
		$query_raw    = isset( $request_data['query_string'] ) ? sanitize_text_field( $request_data['query_string'] ) : '';
		$referrer_raw = isset( $request_data['referrer'] ) ? esc_url_raw( $request_data['referrer'] ) : '';
		$title_raw    = isset( $request_data['page_title'] ) ? sanitize_text_field( $request_data['page_title'] ) : '';
		$home_host    = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host     = $page_url_raw ? wp_parse_url( $page_url_raw, PHP_URL_HOST ) : '';

		if ( $page_url_raw && $url_host && $home_host && strtolower( $url_host ) !== strtolower( $home_host ) ) {
			return null;
		}

		$page_url = $page_url_raw ? $page_url_raw : home_url( $path_raw . ( $query_raw ? '?' . $query_raw : '' ) );

		return array(
			'settings'       => $settings,
			'request_uri'    => $path_raw . ( $query_raw ? '?' . $query_raw : '' ),
			'page_url'       => $page_url,
			'path'           => $path_raw ? $path_raw : '/',
			'query_string'   => $query_raw,
			'referrer'       => $referrer_raw,
			'ip'             => $this->detect_ip_address( $settings, $server ),
			'user_agent'     => isset( $server['HTTP_USER_AGENT'] ) ? sanitize_text_field( $server['HTTP_USER_AGENT'] ) : '',
			'user_id'        => is_user_logged_in() ? get_current_user_id() : null,
			'post_id'        => null,
			'page_title'     => $title_raw,
			'is_admin'       => false,
			'is_ajax'        => false,
			'is_rest'        => false,
			'is_heartbeat'   => false,
			'is_prefetch'    => false,
			'is_bot'         => ! empty( $settings['bot_filtering'] ) && isset( $server['HTTP_USER_AGENT'] ) ? $this->is_bot_request( sanitize_text_field( $server['HTTP_USER_AGENT'] ) ) : false,
			'is_logged_in'   => is_user_logged_in(),
			'is_admin_user'  => current_user_can( 'manage_options' ),
			'timestamp'      => current_time( 'mysql', true ),
			'device_summary' => $this->summarize_device( isset( $server['HTTP_USER_AGENT'] ) ? sanitize_text_field( $server['HTTP_USER_AGENT'] ) : '' ),
		);
	}

	/**
	 * Check if request should be tracked.
	 *
	 * @param array<string, mixed> $context Request context.
	 * @return bool
	 */
	private function should_track_request( $context ) {
		$settings     = $context['settings'];
		$should_track = true;

		if ( ! empty( $context['is_prefetch'] ) || ! empty( $context['is_rest'] ) || ! empty( $context['is_heartbeat'] ) || wp_doing_cron() ) {
			$should_track = false;
		}

		if ( ! empty( $context['is_bot'] ) ) {
			$should_track = false;
		}

		if ( ! empty( $context['is_admin'] ) && empty( $settings['track_wp_admin'] ) ) {
			$should_track = false;
		}

		if ( ! empty( $context['is_ajax'] ) && empty( $settings['track_ajax'] ) ) {
			$should_track = false;
		}

		if ( ! empty( $context['is_logged_in'] ) && empty( $settings['track_logged_in'] ) ) {
			$should_track = false;
		}

		if ( empty( $context['is_logged_in'] ) && empty( $settings['track_guests'] ) ) {
			$should_track = false;
		}

		if ( ! empty( $context['is_admin_user'] ) && empty( $settings['track_admins'] ) ) {
			$should_track = false;
		}

		return (bool) apply_filters( 'jcpst_should_track_request', $should_track, $context );
	}

	/**
	 * Fetch current session or create a new one.
	 *
	 * @param array<string, mixed> $context Request context.
	 * @return array<string, mixed>
	 */
	private function get_or_create_session( $context ) {
		$session_id = $this->get_cookie_session_id();
		$session    = $session_id ? $this->get_session_by_session_id( $session_id ) : null;

		if ( $session && $this->should_rotate_session_for_identity_change( $session, $context ) ) {
			$this->close_session( $session['session_id'], current_time( 'mysql', true ) );
			$this->clear_session_cookie();
			$session = null;
		}

		if ( $session && ! $this->is_session_expired( $session, $context ) ) {
			if ( ! empty( $context['is_logged_in'] ) ) {
				$this->associate_session_with_user( $session['session_id'], (int) $context['user_id'] );
				$session = $this->get_session_by_session_id( $session['session_id'] );
			}

			$this->refresh_session_cookie( $session['session_id'], $context['settings'] );
			return $session;
		}

		if ( $session && $this->is_session_expired( $session, $context ) ) {
			$this->close_session( $session['session_id'], $session['last_activity'] );
		}

		return $this->create_session( $context );
	}

	/**
	 * Create a session with collision-safe insert logic.
	 *
	 * @param array<string, mixed> $context Request context.
	 * @return array<string, mixed>
	 */
	private function create_session( $context ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'jcpst_sessions';
		$attempts   = 0;
		$inserted   = false;
		$session_id = '';
		$now        = $context['timestamp'];
		$ip_hash    = $context['ip'] ? wp_hash( $context['ip'] ) : null;

		while ( $attempts < self::MAX_SESSION_INSERT_ATTEMPTS && ! $inserted ) {
			++$attempts;
			$session_id = $this->generate_session_id();

			$result = $wpdb->insert(
				$table,
				array(
					'session_id'          => $session_id,
					'user_id'             => $context['user_id'],
					'session_start'       => $now,
					'last_activity'       => $now,
					'session_end'         => null,
					'total_pageviews'     => 0,
					'landing_page'        => $context['page_url'],
					'exit_page'           => $context['page_url'],
					'first_referrer'      => $context['referrer'],
					'first_ip'            => $context['ip'],
					'last_ip'             => $context['ip'],
					'ip_hash'             => $ip_hash,
					'user_agent'          => $context['user_agent'],
					'device_summary'      => $context['device_summary'],
					'is_logged_in'        => ! empty( $context['is_logged_in'] ) ? 1 : 0,
					'login_state_changed' => 0,
					'created_at'          => $now,
					'updated_at'          => $now,
				),
				array(
					'%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s',
					'%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s',
				)
			);

			if ( false !== $result ) {
				$inserted = true;
				break;
			}

			if ( false === strpos( strtolower( $wpdb->last_error ), 'duplicate' ) ) {
				break;
			}
		}

		if ( ! $inserted ) {
			error_log( 'JCP Session Tracker: failed to create unique session after retries.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return array();
		}

		$this->refresh_session_cookie( $session_id, $context['settings'] );

		$session = $this->get_session_by_session_id( $session_id );
		do_action( 'jcpst_after_session_created', $session, $context );

		return $session ? $session : array();
	}

	/**
	 * Generate a secure session identifier.
	 *
	 * @return string
	 */
	private function generate_session_id() {
		try {
			$session_id = bin2hex( random_bytes( 32 ) );
		} catch ( Exception $exception ) {
			unset( $exception );
			$session_id = str_replace( '-', '', wp_generate_uuid4() );
		}

		$filtered = apply_filters( 'jcpst_generate_session_id', $session_id );

		return is_string( $filtered ) && strlen( $filtered ) >= 32 ? $filtered : $session_id;
	}

	/**
	 * Get session by secure identifier.
	 *
	 * @param string $session_id Session ID.
	 * @return array<string, mixed>|null
	 */
	private function get_session_by_session_id( $session_id ) {
		global $wpdb;

		$table = $wpdb->prefix . 'jcpst_sessions';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE session_id = %s LIMIT 1",
				$session_id
			),
			ARRAY_A
		);

		return $row ? $row : null;
	}

	/**
	 * Close an ended session.
	 *
	 * @param string $session_id Session ID.
	 * @param string $ended_at End timestamp.
	 * @return void
	 */
	private function close_session( $session_id, $ended_at ) {
		global $wpdb;

		$table = $wpdb->prefix . 'jcpst_sessions';

		$wpdb->update(
			$table,
			array(
				'session_end' => $ended_at,
				'updated_at'  => current_time( 'mysql', true ),
			),
			array( 'session_id' => $session_id ),
			array( '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Determine whether session expired from inactivity.
	 *
	 * @param array<string, mixed> $session Session record.
	 * @param array<string, mixed> $context Request context.
	 * @return bool
	 */
	private function is_session_expired( $session, $context ) {
		$timeout_seconds = max( 60, absint( $context['settings']['inactivity_timeout'] ) * MINUTE_IN_SECONDS );
		$last_activity   = strtotime( $session['last_activity'] . ' UTC' );
		return ( time() - $last_activity ) > $timeout_seconds;
	}

	/**
	 * Refresh session cookie.
	 *
	 * @param string               $session_id Session ID.
	 * @param array<string, mixed> $settings Settings.
	 * @return void
	 */
	private function refresh_session_cookie( $session_id, $settings ) {
		if ( headers_sent() ) {
			return;
		}

		$cookie_name = apply_filters( 'jcpst_cookie_name', $settings['cookie_name'] );
		$expires     = time() + ( absint( $settings['cookie_lifetime'] ) * DAY_IN_SECONDS );
		$cookie_path = '/';
		$cookie_domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';

		setcookie(
			$cookie_name,
			$session_id,
			array(
				'expires'  => $expires,
				'path'     => $cookie_path,
				'domain'   => $cookie_domain,
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		$_COOKIE[ $cookie_name ] = $session_id; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
	}

	/**
	 * Clear the current session cookie.
	 *
	 * @return void
	 */
	private function clear_session_cookie() {
		if ( headers_sent() ) {
			return;
		}

		$settings    = JCPST_Settings::get();
		$cookie_name = apply_filters( 'jcpst_cookie_name', $settings['cookie_name'] );

		setcookie(
			$cookie_name,
			'',
			array(
				'expires'  => time() - HOUR_IN_SECONDS,
				'path'     => '/',
				'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		unset( $_COOKIE[ $cookie_name ] );
	}

	/**
	 * Get cookie session ID.
	 *
	 * @return string|null
	 */
	private function get_cookie_session_id() {
		$settings    = JCPST_Settings::get();
		$cookie_name = apply_filters( 'jcpst_cookie_name', $settings['cookie_name'] );

		if ( empty( $_COOKIE[ $cookie_name ] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			return null;
		}

		$session_id = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );

		return preg_match( '/^[A-Za-z0-9\-_]{32,128}$/', $session_id ) ? $session_id : null;
	}

	/**
	 * Associate session with a user.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $user_id User ID.
	 * @return void
	 */
	private function associate_session_with_user( $session_id, $user_id ) {
		global $wpdb;

		if ( empty( $user_id ) ) {
			return;
		}

		$table   = $wpdb->prefix . 'jcpst_sessions';
		$session = $this->get_session_by_session_id( $session_id );

		if ( ! $session ) {
			return;
		}

		$login_state_changed = ( empty( $session['user_id'] ) || (int) $session['user_id'] !== (int) $user_id ) ? 1 : (int) $session['login_state_changed'];

		$wpdb->update(
			$table,
			array(
				'user_id'             => $user_id,
				'is_logged_in'        => 1,
				'login_state_changed' => $login_state_changed,
				'updated_at'          => current_time( 'mysql', true ),
			),
			array( 'session_id' => $session_id ),
			array( '%d', '%d', '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Check for repeated identical requests inside the dedupe window.
	 *
	 * @param string               $session_id Session ID.
	 * @param array<string, mixed> $context Request context.
	 * @return bool
	 */
	private function is_duplicate_pageview( $session_id, $context ) {
		global $wpdb;

		$table         = $wpdb->prefix . 'jcpst_pageviews';
		$window        = max( 1, absint( $context['settings']['dedupe_window'] ) );
		$window_cutoff = gmdate( 'Y-m-d H:i:s', time() - $window );

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE session_id = %s
				AND path = %s
				AND is_admin = %d
				AND is_ajax = %d
				AND visited_at >= %s",
				$session_id,
				$context['path'],
				! empty( $context['is_admin'] ) ? 1 : 0,
				! empty( $context['is_ajax'] ) ? 1 : 0,
				$window_cutoff
			)
		);

		return $count > 0;
	}

	/**
	 * Insert pageview row.
	 *
	 * @param array<string, mixed> $session Session record.
	 * @param array<string, mixed> $context Request context.
	 * @return void
	 */
	private function record_pageview( $session, $context ) {
		global $wpdb;

		$table = $wpdb->prefix . 'jcpst_pageviews';

		$wpdb->insert(
			$table,
			array(
				'session_id'   => $session['session_id'],
				'user_id'      => $context['user_id'],
				'visited_at'   => $context['timestamp'],
				'page_url'     => $context['page_url'],
				'path'         => $context['path'],
				'query_string' => $context['query_string'],
				'referrer'     => $context['referrer'],
				'ip'           => $context['ip'],
				'user_agent'   => $context['user_agent'],
				'post_id'      => $context['post_id'],
				'page_title'   => $context['page_title'],
				'is_admin'     => ! empty( $context['is_admin'] ) ? 1 : 0,
				'is_ajax'      => ! empty( $context['is_ajax'] ) ? 1 : 0,
				'is_logged_in' => ! empty( $context['is_logged_in'] ) ? 1 : 0,
			),
			array(
				'%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s',
				'%s', '%d', '%s', '%d', '%d', '%d',
			)
		);

		do_action( 'jcpst_after_pageview_recorded', $session, $context, $wpdb->insert_id );
	}

	/**
	 * Update session summary fields after pageview insert.
	 *
	 * @param array<string, mixed> $session Session.
	 * @param array<string, mixed> $context Context.
	 * @return void
	 */
	private function update_session_after_pageview( $session, $context ) {
		global $wpdb;

		$table      = $wpdb->prefix . 'jcpst_sessions';
		$total      = isset( $session['total_pageviews'] ) ? (int) $session['total_pageviews'] + 1 : 1;
		$user_id    = ! empty( $context['user_id'] ) ? (int) $context['user_id'] : null;
		$is_logged  = ! empty( $context['is_logged_in'] ) ? 1 : 0;
		$login_flip = ( (int) $session['is_logged_in'] !== $is_logged ) ? 1 : (int) $session['login_state_changed'];

		$wpdb->update(
			$table,
			array(
				'user_id'             => $user_id,
				'last_activity'       => $context['timestamp'],
				'session_end'         => null,
				'total_pageviews'     => $total,
				'exit_page'           => $context['page_url'],
				'last_ip'             => $context['ip'],
				'user_agent'          => $context['user_agent'],
				'device_summary'      => $context['device_summary'],
				'is_logged_in'        => $is_logged,
				'login_state_changed' => $login_flip,
				'updated_at'          => $context['timestamp'],
			),
			array( 'session_id' => $session['session_id'] ),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Update activity timestamps for requests deduped as pageviews.
	 *
	 * @param array<string, mixed> $session Session row.
	 * @param array<string, mixed> $context Request context.
	 * @return void
	 */
	private function touch_session_activity( $session, $context ) {
		global $wpdb;

		$table = $wpdb->prefix . 'jcpst_sessions';

		$wpdb->update(
			$table,
			array(
				'last_activity'  => $context['timestamp'],
				'session_end'    => null,
				'last_ip'        => $context['ip'],
				'user_agent'     => $context['user_agent'],
				'device_summary' => $context['device_summary'],
				'updated_at'     => $context['timestamp'],
			),
			array( 'session_id' => $session['session_id'] ),
			array( '%s', '%s', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Decide whether an existing cookie session should rotate because auth identity changed.
	 *
	 * @param array<string, mixed> $session Session row.
	 * @param array<string, mixed> $context Request context.
	 * @return bool
	 */
	private function should_rotate_session_for_identity_change( $session, $context ) {
		$session_user_id = ! empty( $session['user_id'] ) ? (int) $session['user_id'] : 0;
		$current_user_id = ! empty( $context['user_id'] ) ? (int) $context['user_id'] : 0;
		$is_logged_in    = ! empty( $context['is_logged_in'] );
		$was_logged_in   = ! empty( $session['is_logged_in'] );

		if ( $was_logged_in && ! $is_logged_in ) {
			return true;
		}

		if ( $is_logged_in && $session_user_id && $session_user_id !== $current_user_id ) {
			return true;
		}

		return false;
	}

	/**
	 * Detect IP with optional proxy support.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param array<string, mixed> $server Server vars.
	 * @return string
	 */
	private function detect_ip_address( $settings, $server ) {
		$remote_addr = ! empty( $server['REMOTE_ADDR'] ) ? (string) $server['REMOTE_ADDR'] : '';
		if ( $this->is_public_ip_address( $remote_addr ) ) {
			return $remote_addr;
		}

		$allow_proxy_headers = ! empty( $settings['trust_proxy_headers'] ) || ( $remote_addr && ! $this->is_public_ip_address( $remote_addr ) );
		if ( $allow_proxy_headers ) {
			$headers = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' );
			foreach ( $headers as $header ) {
				if ( empty( $server[ $header ] ) ) {
					continue;
				}

				$parts = array_map( 'trim', explode( ',', (string) $server[ $header ] ) );
				foreach ( $parts as $part ) {
					if ( $this->is_public_ip_address( $part ) ) {
						return $part;
					}
				}
			}
		}

		if ( filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
			return $remote_addr;
		}

		return '';
	}

	/**
	 * Determine whether an IP is public and routable.
	 *
	 * @param string $ip_address Candidate IP.
	 * @return bool
	 */
	private function is_public_ip_address( $ip_address ) {
		if ( ! filter_var( $ip_address, FILTER_VALIDATE_IP ) ) {
			return false;
		}

		return false !== filter_var( $ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Decide whether a logged-out front-end request is safe to track server-side.
	 *
	 * @return bool
	 */
	private function should_track_guest_server_side() {
		$settings   = JCPST_Settings::get();
		$server     = wp_unslash( $_SERVER ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$user_agent = isset( $server['HTTP_USER_AGENT'] ) ? sanitize_text_field( $server['HTTP_USER_AGENT'] ) : '';
		$ip_address = $this->detect_ip_address( $settings, $server );

		if ( ! $this->is_public_ip_address( $ip_address ) ) {
			return false;
		}

		if ( '' === $user_agent || $this->is_bot_request( $user_agent ) ) {
			return false;
		}

		return (bool) preg_match( '/chrome|firefox|safari|edg|opera|iphone|android/i', $user_agent );
	}

	/**
	 * Detect bots from user agent.
	 *
	 * @param string $user_agent User agent.
	 * @return bool
	 */
	private function is_bot_request( $user_agent ) {
		if ( '' === $user_agent ) {
			return false;
		}

		return (bool) preg_match( '/bot|crawl|spider|slurp|bingpreview|mediapartners|facebookexternalhit|python-requests|headless/i', $user_agent );
	}

	/**
	 * Detect speculative prefetch requests.
	 *
	 * @param array<string, mixed> $server Server vars.
	 * @return bool
	 */
	private function is_prefetch_request( $server ) {
		$purpose = isset( $server['HTTP_PURPOSE'] ) ? strtolower( sanitize_text_field( $server['HTTP_PURPOSE'] ) ) : '';
		$moz     = isset( $server['HTTP_X_MOZ'] ) ? strtolower( sanitize_text_field( $server['HTTP_X_MOZ'] ) ) : '';
		$sec     = isset( $server['HTTP_SEC_PURPOSE'] ) ? strtolower( sanitize_text_field( $server['HTTP_SEC_PURPOSE'] ) ) : '';

		return false !== strpos( $purpose, 'prefetch' ) || false !== strpos( $moz, 'prefetch' ) || false !== strpos( $sec, 'prefetch' );
	}

	/**
	 * Build a short device summary for admin browsing.
	 *
	 * @param string $user_agent User agent.
	 * @return string
	 */
	private function summarize_device( $user_agent ) {
		$device  = preg_match( '/mobile|iphone|android/i', $user_agent ) ? 'Mobile' : 'Desktop';
		$browser = 'Unknown Browser';
		$os      = 'Unknown OS';

		if ( preg_match( '/edg/i', $user_agent ) ) {
			$browser = 'Edge';
		} elseif ( preg_match( '/chrome/i', $user_agent ) ) {
			$browser = 'Chrome';
		} elseif ( preg_match( '/safari/i', $user_agent ) && ! preg_match( '/chrome/i', $user_agent ) ) {
			$browser = 'Safari';
		} elseif ( preg_match( '/firefox/i', $user_agent ) ) {
			$browser = 'Firefox';
		}

		if ( preg_match( '/windows/i', $user_agent ) ) {
			$os = 'Windows';
		} elseif ( preg_match( '/mac os|macintosh/i', $user_agent ) ) {
			$os = 'macOS';
		} elseif ( preg_match( '/android/i', $user_agent ) ) {
			$os = 'Android';
		} elseif ( preg_match( '/iphone|ipad|ios/i', $user_agent ) ) {
			$os = 'iOS';
		} elseif ( preg_match( '/linux/i', $user_agent ) ) {
			$os = 'Linux';
		}

		return trim( "{$device} / {$browser} / {$os}" );
	}

	/**
	 * Resolve a reasonable page title.
	 *
	 * @param bool $is_admin_request Admin request flag.
	 * @param int  $post_id Post ID.
	 * @return string
	 */
	private function resolve_page_title( $is_admin_request, $post_id ) {
		if ( $is_admin_request ) {
			return sanitize_text_field( get_admin_page_title() );
		}

		if ( $post_id ) {
			$title = get_the_title( $post_id );
			if ( $title ) {
				return wp_strip_all_tags( $title );
			}
		}

		$title = wp_get_document_title();
		return is_string( $title ) ? wp_strip_all_tags( $title ) : '';
	}
}
