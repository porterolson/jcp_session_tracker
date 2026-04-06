<?php
/**
 * User profile sessions integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JCPST_User_Profile {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'show_user_profile', array( $this, 'render_sessions_panel' ) );
		add_action( 'edit_user_profile', array( $this, 'render_sessions_panel' ) );
	}

	/**
	 * Render recent sessions for a user.
	 *
	 * @param WP_User $user User object.
	 * @return void
	 */
	public function render_sessions_panel( $user ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return;
		}

		global $wpdb;

		$table    = $wpdb->prefix . 'jcpst_sessions';
		$sessions = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d ORDER BY session_start DESC LIMIT 10",
				$user->ID
			),
			ARRAY_A
		);
		?>
		<h2><?php esc_html_e( 'Recent Sessions', 'jcp-session-tracker' ); ?></h2>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Session Start', 'jcp-session-tracker' ); ?></th>
					<th><?php esc_html_e( 'Duration', 'jcp-session-tracker' ); ?></th>
					<th><?php esc_html_e( 'Pageviews', 'jcp-session-tracker' ); ?></th>
					<th><?php esc_html_e( 'IP', 'jcp-session-tracker' ); ?></th>
					<th><?php esc_html_e( 'Details', 'jcp-session-tracker' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $sessions ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No tracked sessions for this user yet.', 'jcp-session-tracker' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $sessions as $session ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $session['session_start'], 'Y-m-d H:i:s' ) ); ?></td>
							<td><?php echo esc_html( $this->format_duration( $session ) ); ?></td>
							<td><?php echo esc_html( (string) $session['total_pageviews'] ); ?></td>
							<td><?php echo esc_html( $session['last_ip'] ); ?></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => 'jcpst-session-detail', 'session_id' => rawurlencode( $session['session_id'] ) ), admin_url( 'users.php' ) ) ); ?>">
									<?php esc_html_e( 'View Session', 'jcp-session-tracker' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Format duration from session timestamps.
	 *
	 * @param array<string, mixed> $session Session row.
	 * @return string
	 */
	private function format_duration( $session ) {
		$start = strtotime( $session['session_start'] . ' UTC' );
		$settings      = JCPST_Settings::get();
		$last_activity = strtotime( $session['last_activity'] . ' UTC' );
		$timeout       = max( 60, absint( $settings['inactivity_timeout'] ) * MINUTE_IN_SECONDS );
		$end           = ! empty( $session['session_end'] ) ? strtotime( $session['session_end'] . ' UTC' ) : ( ( time() - $last_activity ) < $timeout ? time() : $last_activity );
		$diff  = max( 0, $end - $start );

		if ( $diff < MINUTE_IN_SECONDS ) {
			return sprintf( _n( '%s second', '%s seconds', $diff, 'jcp-session-tracker' ), number_format_i18n( $diff ) );
		}

		if ( $diff < HOUR_IN_SECONDS ) {
			$minutes = floor( $diff / MINUTE_IN_SECONDS );
			return sprintf( _n( '%s minute', '%s minutes', $minutes, 'jcp-session-tracker' ), number_format_i18n( $minutes ) );
		}

		$hours   = floor( $diff / HOUR_IN_SECONDS );
		$minutes = floor( ( $diff % HOUR_IN_SECONDS ) / MINUTE_IN_SECONDS );

		return $minutes > 0
			? sprintf( '%1$s %2$s', sprintf( _n( '%s hour', '%s hours', $hours, 'jcp-session-tracker' ), number_format_i18n( $hours ) ), sprintf( _n( '%s minute', '%s minutes', $minutes, 'jcp-session-tracker' ), number_format_i18n( $minutes ) ) )
			: sprintf( _n( '%s hour', '%s hours', $hours, 'jcp-session-tracker' ), number_format_i18n( $hours ) );
	}
}
