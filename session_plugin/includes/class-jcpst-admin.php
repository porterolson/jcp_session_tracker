<?php
/**
 * Admin screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JCPST_Admin {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_admin_pages' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_sessions_csv' ) );
	}

	/**
	 * Register admin pages.
	 *
	 * @return void
	 */
	public function register_admin_pages() {
		add_users_page(
			__( 'Sessions', 'jcp-session-tracker' ),
			__( 'Sessions', 'jcp-session-tracker' ),
			'list_users',
			'jcpst-sessions',
			array( $this, 'render_sessions_page' )
		);

		add_options_page(
			__( 'Session Tracker Settings', 'jcp-session-tracker' ),
			__( 'Session Tracker Settings', 'jcp-session-tracker' ),
			'manage_options',
			'jcpst-settings',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			null,
			__( 'Session Details', 'jcp-session-tracker' ),
			__( 'Session Details', 'jcp-session-tracker' ),
			'list_users',
			'jcpst-session-detail',
			array( $this, 'render_session_detail_page' )
		);
	}

	/**
	 * Register plugin settings.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			'jcpst_settings_group',
			JCPST_Settings::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'JCPST_Settings', 'sanitize' ),
				'default'           => JCPST_Settings::defaults(),
			)
		);
	}

	/**
	 * Render sessions list page.
	 *
	 * @return void
	 */
	public function render_sessions_page() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to access sessions.', 'jcp-session-tracker' ) );
		}

		$table = new JCPST_Sessions_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'JCP Session Tracker', 'jcp-session-tracker' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="jcpst-sessions" />
				<?php $this->render_filters(); ?>
				<?php $this->render_export_button(); ?>
				<?php $table->search_box( __( 'Search Sessions', 'jcp-session-tracker' ), 'jcpst-session-search' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render filter controls.
	 *
	 * @return void
	 */
	private function render_filters() {
		$settings         = JCPST_Settings::get();
		$user_id          = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		$session_id       = isset( $_GET['session_id_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id_filter'] ) ) : '';
		$ip               = isset( $_GET['ip_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ip_filter'] ) ) : '';
		$status           = isset( $_GET['logged_in_status'] ) ? sanitize_text_field( wp_unslash( $_GET['logged_in_status'] ) ) : '';
		$min_pageviews    = isset( $_GET['min_pageviews'] ) ? absint( wp_unslash( $_GET['min_pageviews'] ) ) : 0;
		$date_from        = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		$date_to          = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		$hide_noise       = isset( $_GET['hide_noise'] ) ? '1' === sanitize_text_field( wp_unslash( $_GET['hide_noise'] ) ) : ! empty( $settings['hide_noise_sessions'] );
		?>
		<p class="search-box" style="display:flex;gap:8px;flex-wrap:wrap;max-width:none;">
			<input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>" />
			<input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>" />
			<input type="number" min="0" name="user_id" placeholder="<?php esc_attr_e( 'User ID', 'jcp-session-tracker' ); ?>" value="<?php echo esc_attr( (string) $user_id ); ?>" />
			<input type="text" name="session_id_filter" placeholder="<?php esc_attr_e( 'Session ID', 'jcp-session-tracker' ); ?>" value="<?php echo esc_attr( $session_id ); ?>" />
			<input type="text" name="ip_filter" placeholder="<?php esc_attr_e( 'IP Address', 'jcp-session-tracker' ); ?>" value="<?php echo esc_attr( $ip ); ?>" />
			<select name="logged_in_status">
				<option value=""><?php esc_html_e( 'All Login States', 'jcp-session-tracker' ); ?></option>
				<option value="yes" <?php selected( $status, 'yes' ); ?>><?php esc_html_e( 'Logged in', 'jcp-session-tracker' ); ?></option>
				<option value="no" <?php selected( $status, 'no' ); ?>><?php esc_html_e( 'Guest', 'jcp-session-tracker' ); ?></option>
			</select>
			<input type="number" min="0" name="min_pageviews" placeholder="<?php esc_attr_e( 'Min Pageviews', 'jcp-session-tracker' ); ?>" value="<?php echo esc_attr( (string) $min_pageviews ); ?>" />
			<input type="hidden" name="hide_noise" value="0" />
			<label style="display:flex;align-items:center;gap:6px;padding:0 4px;">
				<input type="checkbox" name="hide_noise" value="1" <?php checked( $hide_noise ); ?> />
				<?php esc_html_e( 'Hide Suspected Scanner and Noise Traffic', 'jcp-session-tracker' ); ?>
			</label>
			<?php submit_button( __( 'Apply View Filters', 'jcp-session-tracker' ), 'secondary', 'filter_action', false ); ?>
		</p>
		<?php
	}

	/**
	 * Render export action.
	 *
	 * @return void
	 */
	private function render_export_button() {
		$query_args = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		unset( $query_args['export_jcpst_sessions'], $query_args['_wpnonce'] );
		$query_args['export_jcpst_sessions'] = '1';
		$query_args['_wpnonce']              = wp_create_nonce( 'jcpst_export_sessions' );
		$export_url                          = add_query_arg( array_map( 'sanitize_text_field', $query_args ), admin_url( 'users.php' ) );
		?>
		<p>
			<a class="button button-secondary" href="<?php echo esc_url( $export_url ); ?>">
				<?php esc_html_e( 'Export Current View JSON', 'jcp-session-tracker' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render settings page.
	 *
	 * @return void
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage settings.', 'jcp-session-tracker' ) );
		}

		$settings = JCPST_Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'JCP Session Tracker Settings', 'jcp-session-tracker' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'jcpst_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="jcpst_inactivity_timeout"><?php esc_html_e( 'Inactivity timeout (minutes)', 'jcp-session-tracker' ); ?></label></th>
						<td><input id="jcpst_inactivity_timeout" name="<?php echo esc_attr( JCPST_Settings::OPTION_NAME ); ?>[inactivity_timeout]" type="number" min="1" value="<?php echo esc_attr( (string) $settings['inactivity_timeout'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="jcpst_cookie_lifetime"><?php esc_html_e( 'Cookie lifetime (days)', 'jcp-session-tracker' ); ?></label></th>
						<td><input id="jcpst_cookie_lifetime" name="<?php echo esc_attr( JCPST_Settings::OPTION_NAME ); ?>[cookie_lifetime]" type="number" min="1" value="<?php echo esc_attr( (string) $settings['cookie_lifetime'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="jcpst_cookie_name"><?php esc_html_e( 'Cookie name', 'jcp-session-tracker' ); ?></label></th>
						<td><input id="jcpst_cookie_name" name="<?php echo esc_attr( JCPST_Settings::OPTION_NAME ); ?>[cookie_name]" type="text" value="<?php echo esc_attr( $settings['cookie_name'] ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="jcpst_retention_pageviews"><?php esc_html_e( 'Pageview retention (days)', 'jcp-session-tracker' ); ?></label></th>
						<td><input id="jcpst_retention_pageviews" name="<?php echo esc_attr( JCPST_Settings::OPTION_NAME ); ?>[retention_pageviews]" type="number" min="1" value="<?php echo esc_attr( (string) $settings['retention_pageviews'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="jcpst_retention_sessions"><?php esc_html_e( 'Session retention (days)', 'jcp-session-tracker' ); ?></label></th>
						<td><input id="jcpst_retention_sessions" name="<?php echo esc_attr( JCPST_Settings::OPTION_NAME ); ?>[retention_sessions]" type="number" min="1" value="<?php echo esc_attr( (string) $settings['retention_sessions'] ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="jcpst_dedupe_window"><?php esc_html_e( 'Duplicate request window (seconds)', 'jcp-session-tracker' ); ?></label></th>
						<td><input id="jcpst_dedupe_window" name="<?php echo esc_attr( JCPST_Settings::OPTION_NAME ); ?>[dedupe_window]" type="number" min="1" value="<?php echo esc_attr( (string) $settings['dedupe_window'] ); ?>" class="small-text" /></td>
					</tr>
					<?php $this->render_checkbox_row( 'track_guests', __( 'Track guests', 'jcp-session-tracker' ), $settings ); ?>
					<?php $this->render_checkbox_row( 'track_logged_in', __( 'Track logged-in users', 'jcp-session-tracker' ), $settings ); ?>
					<?php $this->render_checkbox_row( 'track_admins', __( 'Track administrators', 'jcp-session-tracker' ), $settings ); ?>
					<?php $this->render_checkbox_row( 'track_wp_admin', __( 'Track wp-admin pages', 'jcp-session-tracker' ), $settings ); ?>
					<?php $this->render_checkbox_row( 'track_ajax', __( 'Track AJAX requests', 'jcp-session-tracker' ), $settings ); ?>
					<?php $this->render_checkbox_row( 'bot_filtering', __( 'Enable bot filtering', 'jcp-session-tracker' ), $settings ); ?>
					<?php $this->render_checkbox_row( 'trust_proxy_headers', __( 'Trust proxy IP headers', 'jcp-session-tracker' ), $settings, __( 'Disabled by default. When enabled, X-Forwarded-For, CF-Connecting-IP, and X-Real-IP are checked before REMOTE_ADDR.', 'jcp-session-tracker' ) ); ?>
					<?php $this->render_checkbox_row( 'hide_noise_sessions', __( 'Hide likely one-hit guest noise in admin', 'jcp-session-tracker' ), $settings, __( 'Hides one-page guest sessions with unknown browser/device summaries from the admin list and exports. Raw data is still stored.', 'jcp-session-tracker' ) ); ?>
					<?php $this->render_checkbox_row( 'delete_data_on_uninstall', __( 'Delete plugin data on uninstall', 'jcp-session-tracker' ), $settings ); ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a checkbox row.
	 *
	 * @param string               $key Setting key.
	 * @param string               $label Field label.
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $description Optional description.
	 * @return void
	 */
	private function render_checkbox_row( $key, $label, $settings, $description = '' ) {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( JCPST_Settings::OPTION_NAME ); ?>[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $settings[ $key ] ) ); ?> />
					<?php esc_html_e( 'Enabled', 'jcp-session-tracker' ); ?>
				</label>
				<?php if ( $description ) : ?>
					<p class="description"><?php echo esc_html( $description ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Render session detail page.
	 *
	 * @return void
	 */
	public function render_session_detail_page() {
		if ( ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You do not have permission to access session details.', 'jcp-session-tracker' ) );
		}

		$session_id = isset( $_GET['session_id'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) ) : '';
		if ( '' === $session_id ) {
			wp_die( esc_html__( 'Missing session ID.', 'jcp-session-tracker' ) );
		}

		global $wpdb;

		$sessions_table  = $wpdb->prefix . 'jcpst_sessions';
		$pageviews_table = $wpdb->prefix . 'jcpst_pageviews';
		$session         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$sessions_table} WHERE session_id = %s LIMIT 1", $session_id ), ARRAY_A );

		if ( ! $session ) {
			wp_die( esc_html__( 'Session not found.', 'jcp-session-tracker' ) );
		}

		$pageviews = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$pageviews_table} WHERE session_id = %s ORDER BY visited_at ASC",
				$session_id
			),
			ARRAY_A
		);

		$user = ! empty( $session['user_id'] ) ? get_userdata( (int) $session['user_id'] ) : null;

		if ( isset( $_GET['format'] ) && 'json' === sanitize_text_field( wp_unslash( $_GET['format'] ) ) ) {
			$this->output_single_session_json( $session, $pageviews, $user );
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Session Details', 'jcp-session-tracker' ); ?></h1>
			<p><a class="button button-secondary" href="<?php echo esc_url( add_query_arg( array( 'page' => 'jcpst-session-detail', 'session_id' => rawurlencode( $session_id ), 'format' => 'json' ), admin_url( 'users.php' ) ) ); ?>"><?php esc_html_e( 'View Session JSON', 'jcp-session-tracker' ); ?></a></p>
			<table class="widefat striped" style="max-width:1200px;">
				<tbody>
					<?php $this->detail_row( __( 'Session ID', 'jcp-session-tracker' ), '<code>' . esc_html( $session['session_id'] ) . '</code>' ); ?>
					<?php $this->detail_row( __( 'User', 'jcp-session-tracker' ), $user ? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a>' : esc_html__( 'Guest', 'jcp-session-tracker' ) ); ?>
					<?php $this->detail_row( __( 'Session Start', 'jcp-session-tracker' ), esc_html( get_date_from_gmt( $session['session_start'], 'Y-m-d H:i:s' ) ) ); ?>
					<?php $this->detail_row( __( 'Last Activity', 'jcp-session-tracker' ), esc_html( get_date_from_gmt( $session['last_activity'], 'Y-m-d H:i:s' ) ) ); ?>
					<?php $this->detail_row( __( 'Session End', 'jcp-session-tracker' ), esc_html( $session['session_end'] ? get_date_from_gmt( $session['session_end'], 'Y-m-d H:i:s' ) : __( 'Active / open', 'jcp-session-tracker' ) ) ); ?>
					<?php $this->detail_row( __( 'Duration', 'jcp-session-tracker' ), esc_html( $this->format_duration( $session ) ) ); ?>
					<?php $this->detail_row( __( 'Pageviews', 'jcp-session-tracker' ), esc_html( (string) $session['total_pageviews'] ) ); ?>
					<?php $this->detail_row( __( 'Visited Pages JSON', 'jcp-session-tracker' ), '<code style="display:block;white-space:pre-wrap;">' . esc_html( wp_json_encode( $this->build_visited_pages_payload( $pageviews ), JSON_PRETTY_PRINT ) ) . '</code>' ); ?>
					<?php $this->detail_row( __( 'First Referrer', 'jcp-session-tracker' ), esc_html( $session['first_referrer'] ) ); ?>
					<?php $this->detail_row( __( 'First IP', 'jcp-session-tracker' ), esc_html( $session['first_ip'] ) ); ?>
					<?php $this->detail_row( __( 'Last IP', 'jcp-session-tracker' ), esc_html( $session['last_ip'] ) ); ?>
					<?php $this->detail_row( __( 'IP Hash', 'jcp-session-tracker' ), esc_html( $session['ip_hash'] ) ); ?>
					<?php $this->detail_row( __( 'User Agent', 'jcp-session-tracker' ), esc_html( $session['user_agent'] ) ); ?>
					<?php $this->detail_row( __( 'Device Summary', 'jcp-session-tracker' ), esc_html( $session['device_summary'] ) ); ?>
					<?php $this->detail_row( __( 'Login State', 'jcp-session-tracker' ), ! empty( $session['is_logged_in'] ) ? esc_html__( 'Logged in', 'jcp-session-tracker' ) : esc_html__( 'Guest', 'jcp-session-tracker' ) ); ?>
					<?php $this->detail_row( __( 'Login State Changed', 'jcp-session-tracker' ), ! empty( $session['login_state_changed'] ) ? esc_html__( 'Yes', 'jcp-session-tracker' ) : esc_html__( 'No', 'jcp-session-tracker' ) ); ?>
				</tbody>
			</table>

			<h2 style="margin-top:24px;"><?php esc_html_e( 'Pageview Timeline', 'jcp-session-tracker' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Visited At', 'jcp-session-tracker' ); ?></th>
						<th><?php esc_html_e( 'Title', 'jcp-session-tracker' ); ?></th>
						<th><?php esc_html_e( 'URL', 'jcp-session-tracker' ); ?></th>
						<th><?php esc_html_e( 'Referrer', 'jcp-session-tracker' ); ?></th>
						<th><?php esc_html_e( 'IP', 'jcp-session-tracker' ); ?></th>
						<th><?php esc_html_e( 'Flags', 'jcp-session-tracker' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $pageviews ) ) : ?>
						<tr><td colspan="6"><?php esc_html_e( 'No pageviews recorded for this session.', 'jcp-session-tracker' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $pageviews as $pageview ) : ?>
							<tr>
								<td><?php echo esc_html( get_date_from_gmt( $pageview['visited_at'], 'Y-m-d H:i:s' ) ); ?></td>
								<td><?php echo esc_html( $pageview['page_title'] ); ?></td>
								<td><?php echo esc_html( $pageview['page_url'] ); ?></td>
								<td><?php echo esc_html( $pageview['referrer'] ); ?></td>
								<td><?php echo esc_html( $pageview['ip'] ); ?></td>
								<td><?php echo esc_html( $this->format_flags( $pageview ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Export filtered sessions as CSV.
	 *
	 * @return void
	 */
	public function maybe_export_sessions_csv() {
		if ( ! is_admin() || ! current_user_can( 'list_users' ) ) {
			return;
		}

		$should_export = isset( $_GET['page'], $_GET['export_jcpst_sessions'] )
			&& 'jcpst-sessions' === sanitize_text_field( wp_unslash( $_GET['page'] ) )
			&& '1' === sanitize_text_field( wp_unslash( $_GET['export_jcpst_sessions'] ) );

		if ( ! $should_export ) {
			return;
		}

		check_admin_referer( 'jcpst_export_sessions' );

		$table = new JCPST_Sessions_List_Table();
		$rows  = $table->get_export_items();

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=jcpst-sessions-' . gmdate( 'Y-m-d-H-i-s' ) . '.json' );

		$export_rows = array();

		foreach ( $rows as $row ) {
			$user         = ! empty( $row['user_id'] ) ? get_userdata( (int) $row['user_id'] ) : null;
			$start        = strtotime( $row['session_start'] . ' UTC' );
			$end          = ! empty( $row['session_end'] ) ? strtotime( $row['session_end'] . ' UTC' ) : strtotime( $row['last_activity'] . ' UTC' );
			$duration     = max( 0, $end - $start );
			$export_rows[] = array(
				'session_id'          => $row['session_id'],
				'user_id'             => $row['user_id'],
				'user_display_name'   => $user ? $user->display_name : '',
				'session_start'       => $row['session_start'],
				'last_activity'       => $row['last_activity'],
				'session_end'         => $row['session_end'],
				'duration_seconds'    => $duration,
				'total_pageviews'     => (int) $row['total_pageviews'],
				'visited_pages'       => isset( $row['visited_pages'] ) && is_array( $row['visited_pages'] ) ? $row['visited_pages'] : array(),
				'first_referrer'      => $row['first_referrer'],
				'first_ip'            => $row['first_ip'],
				'last_ip'             => $row['last_ip'],
				'ip_hash'             => $row['ip_hash'],
				'user_agent'          => $row['user_agent'],
				'device_summary'      => $row['device_summary'],
				'is_logged_in'        => (bool) $row['is_logged_in'],
				'login_state_changed' => (bool) $row['login_state_changed'],
				'created_at'          => $row['created_at'],
				'updated_at'          => $row['updated_at'],
			);
		}

		echo wp_json_encode(
			array(
				'exported_at' => gmdate( 'c' ),
				'count'       => count( $export_rows ),
				'sessions'    => $export_rows,
			),
			JSON_PRETTY_PRINT
		);
		exit;
	}

	/**
	 * Output a detail row.
	 *
	 * @param string $label Label.
	 * @param string $value HTML-safe value.
	 * @return void
	 */
	private function detail_row( $label, $value ) {
		echo '<tr><th style="width:220px;">' . esc_html( $label ) . '</th><td>' . $value . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Format duration from session timestamps.
	 *
	 * @param array<string, mixed> $session Session row.
	 * @return string
	 */
	private function format_duration( $session ) {
		$start = strtotime( $session['session_start'] . ' UTC' );
		$end   = $this->get_effective_session_end_timestamp( $session );
		return $this->format_elapsed_seconds( max( 0, $end - $start ) );
	}

	/**
	 * Format pageview flags.
	 *
	 * @param array<string, mixed> $pageview Pageview row.
	 * @return string
	 */
	private function format_flags( $pageview ) {
		$flags = array();
		if ( ! empty( $pageview['is_admin'] ) ) {
			$flags[] = 'admin';
		}
		if ( ! empty( $pageview['is_ajax'] ) ) {
			$flags[] = 'ajax';
		}
		if ( ! empty( $pageview['is_logged_in'] ) ) {
			$flags[] = 'logged-in';
		}

		return $flags ? implode( ', ', $flags ) : 'front-end';
	}

	/**
	 * Build a compact visited-pages payload.
	 *
	 * @param array<int, array<string, mixed>> $pageviews Pageviews.
	 * @return array<int, array<string, string>>
	 */
	private function build_visited_pages_payload( $pageviews ) {
		$payload = array();

		foreach ( $pageviews as $pageview ) {
			$payload[] = array(
				'visited_at' => isset( $pageview['visited_at'] ) ? (string) $pageview['visited_at'] : '',
				'page_url'   => isset( $pageview['page_url'] ) ? (string) $pageview['page_url'] : '',
			);
		}

		return $payload;
	}

	/**
	 * Output a single session payload as JSON.
	 *
	 * @param array<string, mixed>            $session Session row.
	 * @param array<int, array<string, mixed>> $pageviews Pageviews.
	 * @param WP_User|null                    $user User object.
	 * @return void
	 */
	private function output_single_session_json( $session, $pageviews, $user ) {
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );

		echo wp_json_encode(
			array(
				'session' => array(
					'session_id'          => $session['session_id'],
					'user_id'             => $session['user_id'],
					'user_display_name'   => $user ? $user->display_name : '',
					'session_start'       => $session['session_start'],
					'last_activity'       => $session['last_activity'],
					'session_end'         => $session['session_end'],
					'total_pageviews'     => (int) $session['total_pageviews'],
					'visited_pages'       => $this->build_visited_pages_payload( $pageviews ),
					'first_referrer'      => $session['first_referrer'],
					'first_ip'            => $session['first_ip'],
					'last_ip'             => $session['last_ip'],
					'ip_hash'             => $session['ip_hash'],
					'user_agent'          => $session['user_agent'],
					'device_summary'      => $session['device_summary'],
					'is_logged_in'        => (bool) $session['is_logged_in'],
					'login_state_changed' => (bool) $session['login_state_changed'],
					'created_at'          => $session['created_at'],
					'updated_at'          => $session['updated_at'],
				),
			),
			JSON_PRETTY_PRINT
		);
		exit;
	}

	/**
	 * Calculate the active session end point.
	 *
	 * @param array<string, mixed> $session Session row.
	 * @return int
	 */
	private function get_effective_session_end_timestamp( $session ) {
		if ( ! empty( $session['session_end'] ) ) {
			return strtotime( $session['session_end'] . ' UTC' );
		}

		$settings      = JCPST_Settings::get();
		$last_activity = strtotime( $session['last_activity'] . ' UTC' );
		$timeout       = max( 60, absint( $settings['inactivity_timeout'] ) * MINUTE_IN_SECONDS );

		return ( time() - $last_activity ) < $timeout ? time() : $last_activity;
	}

	/**
	 * Format elapsed seconds in a human-friendly way.
	 *
	 * @param int $seconds Duration in seconds.
	 * @return string
	 */
	private function format_elapsed_seconds( $seconds ) {
		$seconds = max( 0, (int) $seconds );

		if ( $seconds < MINUTE_IN_SECONDS ) {
			return sprintf( _n( '%s second', '%s seconds', $seconds, 'jcp-session-tracker' ), number_format_i18n( $seconds ) );
		}

		if ( $seconds < HOUR_IN_SECONDS ) {
			$minutes = floor( $seconds / MINUTE_IN_SECONDS );
			return sprintf( _n( '%s minute', '%s minutes', $minutes, 'jcp-session-tracker' ), number_format_i18n( $minutes ) );
		}

		if ( $seconds < DAY_IN_SECONDS ) {
			$hours   = floor( $seconds / HOUR_IN_SECONDS );
			$minutes = floor( ( $seconds % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );
			return $minutes > 0
				? sprintf( '%1$s %2$s', sprintf( _n( '%s hour', '%s hours', $hours, 'jcp-session-tracker' ), number_format_i18n( $hours ) ), sprintf( _n( '%s minute', '%s minutes', $minutes, 'jcp-session-tracker' ), number_format_i18n( $minutes ) ) )
				: sprintf( _n( '%s hour', '%s hours', $hours, 'jcp-session-tracker' ), number_format_i18n( $hours ) );
		}

		$days  = floor( $seconds / DAY_IN_SECONDS );
		$hours = floor( ( $seconds % DAY_IN_SECONDS ) / HOUR_IN_SECONDS );
		return $hours > 0
			? sprintf( '%1$s %2$s', sprintf( _n( '%s day', '%s days', $days, 'jcp-session-tracker' ), number_format_i18n( $days ) ), sprintf( _n( '%s hour', '%s hours', $hours, 'jcp-session-tracker' ), number_format_i18n( $hours ) ) )
			: sprintf( _n( '%s day', '%s days', $days, 'jcp-session-tracker' ), number_format_i18n( $days ) );
	}
}
