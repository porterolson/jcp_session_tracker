<?php
/**
 * Recently viewed jobs account-page integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JCPST_Recent_Jobs {

	/**
	 * Whether styles have already been printed.
	 *
	 * @var bool
	 */
	private static $did_render_styles = false;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_shortcode( 'jcp_recently_viewed_jobs', array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_ajax_jcpst_save_job_response', array( $this, 'handle_save_job_response' ) );
	}

	/**
	 * Register front-end assets.
	 *
	 * @return void
	 */
	public function register_assets() {
		wp_register_script(
			'jcpst-recent-jobs',
			JCPST_PLUGIN_URL . 'assets/js/jcpst-recent-jobs.js',
			array(),
			JCPST_VERSION,
			true
		);
	}

	/**
	 * Render the recently viewed jobs block.
	 *
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'limit'                 => 0,
				'cta_heading'           => __( 'Curious about the other applicants?', 'jcp-session-tracker' ),
				'cta_description'       => __( 'Share a little about yourself and we can provide detail about the hiring companies and applicants who have applied through Job Connections Project.', 'jcp-session-tracker' ),
				'empty_message'         => __( 'No recently viewed job pages yet.', 'jcp-session-tracker' ),
				'login_message'         => __( 'Sign in to see your recently viewed jobs.', 'jcp-session-tracker' ),
				'tab_label'             => __( 'Recently Viewed Jobs', 'jcp-session-tracker' ),
				'submit_label'          => __( 'Submit', 'jcp-session-tracker' ),
				'question_applied'      => __( 'Apply?', 'jcp-session-tracker' ),
				'question_interviewed'  => __( 'Interview?', 'jcp-session-tracker' ),
				'question_offered'      => __( 'Offer?', 'jcp-session-tracker' ),
				'choice_yes'            => __( 'Yes', 'jcp-session-tracker' ),
				'choice_no'             => __( 'No', 'jcp-session-tracker' ),
			),
			$atts,
			'jcp_recently_viewed_jobs'
		);

		ob_start();
		$this->render_styles_once();

		echo '<section class="jcpst-recent-jobs" data-interactive="1" aria-label="' . esc_attr( $atts['tab_label'] ) . '">';
		echo '<div class="jcpst-recent-jobs__header">';
		echo '<span class="jcpst-recent-jobs__label">' . esc_html( $atts['tab_label'] ) . '</span>';
		echo '</div>';
		echo '<div class="jcpst-recent-jobs__panel">';

		if ( ! is_user_logged_in() ) {
			echo '<p class="jcpst-recent-jobs__message">' . esc_html( $atts['login_message'] ) . '</p>';
			echo '</div></section>';
			return (string) ob_get_clean();
		}

		$jobs = $this->get_recent_jobs_for_user( get_current_user_id(), absint( $atts['limit'] ) );

		if ( empty( $jobs ) ) {
			echo '<p class="jcpst-recent-jobs__message">' . esc_html( $atts['empty_message'] ) . '</p>';
			echo '</div></section>';
			return (string) ob_get_clean();
		}

		wp_enqueue_script( 'jcpst-recent-jobs' );
		wp_localize_script(
			'jcpst-recent-jobs',
			'jcpstRecentJobs',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'jcpst_save_job_response' ),
			)
		);

		echo '<div class="jcpst-recent-jobs__intro">';
		echo '<div class="jcpst-recent-jobs__intro-copy">';
		echo '<div class="jcpst-recent-jobs__intro-heading">' . esc_html( $atts['cta_heading'] ) . '</div>';
		echo '<p class="jcpst-recent-jobs__intro-description">' . esc_html( $atts['cta_description'] ) . '</p>';
		echo '</div>';
		echo '</div>';

		echo '<ul class="jcpst-recent-jobs__list">';

		foreach ( $jobs as $job ) {
			$job_title     = ! empty( $job['page_title'] ) ? $job['page_title'] : $this->build_title_from_path( $job['path'] );
			$visited       = ! empty( $job['visited_at'] ) ? get_date_from_gmt( $job['visited_at'], 'M j, Y g:i a' ) : '';
			$page_url      = ! empty( $job['page_url'] ) ? $job['page_url'] : home_url( $job['path'] );
			$user_response = $this->get_user_response( get_current_user_id(), $job['path'] );
			$stats         = $this->get_job_stats( $job['path'] );

			echo '<li class="jcpst-recent-jobs__item" data-job-path="' . esc_attr( $job['path'] ) . '" data-job-url="' . esc_url( $page_url ) . '" data-job-title="' . esc_attr( $job_title ) . '">';
			echo '<div class="jcpst-recent-jobs__details">';
			echo '<a class="jcpst-recent-jobs__title" href="' . esc_url( $page_url ) . '">' . esc_html( $job_title ) . '</a>';
			echo '<div class="jcpst-recent-jobs__meta">';
			echo '<span>' . esc_html__( 'Viewed', 'jcp-session-tracker' ) . ' ' . esc_html( $visited ) . '</span>';
			echo '<span>' . sprintf( esc_html( _n( '%s visit', '%s visits', (int) $job['visit_count'], 'jcp-session-tracker' ) ), number_format_i18n( (int) $job['visit_count'] ) ) . '</span>';
			echo '</div>';
			echo '</div>';

			echo '<div class="jcpst-recent-jobs__response">';
			$this->render_response_form( $job['path'], $user_response, $atts );
			echo '<div class="jcpst-job-response__stats" ' . ( $user_response ? '' : 'hidden' ) . '>';
			if ( $user_response ) {
				$this->render_stats_markup( $stats );
			}
			echo '</div>';
			echo '</div>';
			echo '</li>';
		}

		echo '</ul>';
		echo '</div></section>';

		return (string) ob_get_clean();
	}

	/**
	 * Handle AJAX response saving.
	 *
	 * @return void
	 */
	public function handle_save_job_response() {
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 401 );
		}

		check_ajax_referer( 'jcpst_save_job_response' );

		$user_id     = get_current_user_id();
		$job_path    = isset( $_POST['job_path'] ) ? sanitize_text_field( wp_unslash( $_POST['job_path'] ) ) : '';
		$job_url     = isset( $_POST['job_url'] ) ? esc_url_raw( wp_unslash( $_POST['job_url'] ) ) : '';
		$job_title   = isset( $_POST['job_title'] ) ? sanitize_text_field( wp_unslash( $_POST['job_title'] ) ) : '';
		$applied     = isset( $_POST['applied'] ) ? absint( wp_unslash( $_POST['applied'] ) ) : null;
		$interviewed = isset( $_POST['interviewed'] ) ? absint( wp_unslash( $_POST['interviewed'] ) ) : null;
		$offered     = isset( $_POST['offered'] ) ? absint( wp_unslash( $_POST['offered'] ) ) : null;

		if ( '' === $job_path || 0 !== strpos( $job_path, '/jobs/' ) || '/jobs/' === $job_path ) {
			wp_send_json_error( array( 'message' => 'Invalid job.' ), 400 );
		}

		if ( ! in_array( $applied, array( 0, 1 ), true ) || ! in_array( $interviewed, array( 0, 1 ), true ) || ! in_array( $offered, array( 0, 1 ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid response.' ), 400 );
		}

		if ( 0 === $applied ) {
			$interviewed = 0;
			$offered     = 0;
		}

		$this->upsert_user_response(
			$user_id,
			array(
				'job_path'    => $job_path,
				'job_url'     => $job_url,
				'job_title'   => $job_title,
				'applied'     => $applied,
				'interviewed' => $interviewed,
				'offered'     => $offered,
			)
		);

		wp_send_json_success(
			array(
				'stats' => $this->get_job_stats( $job_path ),
			)
		);
	}

	/**
	 * Render the response form.
	 *
	 * @param string               $job_path Job path.
	 * @param array<string, mixed> $user_response User response.
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @return void
	 */
	private function render_response_form( $job_path, $user_response, $atts ) {
		$has_response = ! empty( $user_response );
		$questions    = array(
			array(
				'key'      => 'applied',
				'label'    => $atts['question_applied'],
				'position' => 'first',
			),
			array(
				'key'      => 'interviewed',
				'label'    => $atts['question_interviewed'],
				'position' => 'middle',
			),
			array(
				'key'      => 'offered',
				'label'    => $atts['question_offered'],
				'position' => 'last',
			),
		);

		echo '<div class="jcpst-job-response" ' . ( $has_response ? 'hidden' : '' ) . '>';
		echo '<div class="jcpst-job-response__interaction">';
		echo '<div class="jcpst-job-response__controls">';
		echo '<div class="jcpst-job-response__labels">';
		foreach ( $questions as $question ) {
			echo '<div class="jcpst-job-response__prompt">' . esc_html( $question['label'] ) . '</div>';
		}
		echo '</div>';
		echo '<div class="jcpst-job-response__segments">';
		foreach ( $questions as $question ) {
			$this->render_toggle_group( $question['key'], $user_response, $atts, $question['position'] );
		}
		echo '</div>';
		echo '</div>';
		echo '<div class="jcpst-job-response__footer">';
		echo '<button type="button" class="jcpst-job-response__submit">' . esc_html( $atts['submit_label'] ) . '</button>';
		echo '</div>';
		echo '</div>';
		echo '<div class="jcpst-job-response__status" aria-live="polite"></div>';
		echo '</div>';
	}

	/**
	 * Render a yes/no toggle group.
	 *
	 * @param string               $key Question key.
	 * @param array<string, mixed> $user_response User response.
	 * @param array<string, mixed> $atts Shortcode attributes.
	 * @param string               $position Segment position.
	 * @return void
	 */
	private function render_toggle_group( $key, $user_response, $atts, $position ) {
		$selected = isset( $user_response[ $key ] ) ? (string) absint( $user_response[ $key ] ) : '';

		echo '<div class="jcpst-job-response__segment jcpst-job-response__segment--' . esc_attr( $position ) . '">';
		echo '<div class="jcpst-job-response__group" data-question="' . esc_attr( $key ) . '" data-selected="' . esc_attr( $selected ) . '">';
		echo '<button type="button" class="jcpst-job-response__option' . ( '1' === $selected ? ' is-selected' : '' ) . '" data-value="1">' . esc_html( $atts['choice_yes'] ) . '</button>';
		echo '<button type="button" class="jcpst-job-response__option' . ( '0' === $selected ? ' is-selected' : '' ) . '" data-value="0">' . esc_html( $atts['choice_no'] ) . '</button>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render aggregate stats markup.
	 *
	 * @param array<string, int> $stats Stats.
	 * @return void
	 */
	private function render_stats_markup( $stats ) {
		$applicant_label = 1 === (int) $stats['applicant_count'] ? __( 'applicant', 'jcp-session-tracker' ) : __( 'applicants', 'jcp-session-tracker' );

		echo '<div class="jcpst-job-stats">';
		echo '<div class="jcpst-job-stats__count"><span class="jcpst-job-stats__count-number">' . esc_html( number_format_i18n( (int) $stats['applicant_count'] ) ) . '</span> <span class="jcpst-job-stats__count-label">' . esc_html( $applicant_label ) . '</span></div>';
		echo '<div class="jcpst-job-stats__chart">';
		echo '<div class="jcpst-job-stats__chart-head">';
		echo '<span>' . esc_html__( 'Interview', 'jcp-session-tracker' ) . '</span>';
		echo '<span>' . esc_html__( 'Offer', 'jcp-session-tracker' ) . '</span>';
		echo '</div>';
		echo '<div class="jcpst-job-stats__plot">';
		echo '<div class="jcpst-job-stats__bar-wrap"><span class="jcpst-job-stats__bar jcpst-job-stats__bar--interview" style="height:' . esc_attr( (string) $stats['interview_rate'] ) . '%"></span></div>';
		echo '<div class="jcpst-job-stats__bar-wrap"><span class="jcpst-job-stats__bar jcpst-job-stats__bar--offer" style="height:' . esc_attr( (string) $stats['offer_rate'] ) . '%"></span></div>';
		echo '</div>';
		echo '<div class="jcpst-job-stats__chart-values">';
		echo '<span>' . esc_html( (string) $stats['interview_rate'] ) . '%</span>';
		echo '<span>' . esc_html( (string) $stats['offer_rate'] ) . '%</span>';
		echo '</div>';
		echo '</div>';
		echo '<button type="button" class="jcpst-job-stats__edit">' . esc_html__( 'Update answers', 'jcp-session-tracker' ) . '</button>';
		echo '<div class="jcpst-job-stats__note">' . esc_html__( 'Aggregated from Job Connections Project responses.', 'jcp-session-tracker' ) . '</div>';
		echo '</div>';
	}

	/**
	 * Get recently viewed unique job pages for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit Maximum jobs to return. Zero means all.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_recent_jobs_for_user( $user_id, $limit ) {
		global $wpdb;

		$table          = $wpdb->prefix . 'jcpst_pageviews';
		$jobs_pattern   = '%/jobs/%';
		$jobs_index_uri = '/jobs/';
		$limit_sql      = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
		$query          = $wpdb->prepare(
			"SELECT latest.path, latest.page_url, latest.page_title, latest.visited_at, counts.visit_count
			FROM (
				SELECT pv.path, pv.page_url, pv.page_title, pv.visited_at
				FROM {$table} pv
				INNER JOIN (
					SELECT path, MAX(visited_at) AS latest_visited_at
					FROM {$table}
					WHERE user_id = %d
						AND is_logged_in = 1
						AND path LIKE %s
						AND path <> %s
					GROUP BY path
				) latest_match
					ON pv.path = latest_match.path
					AND pv.visited_at = latest_match.latest_visited_at
				WHERE pv.user_id = %d
					AND pv.is_logged_in = 1
					AND pv.path LIKE %s
					AND pv.path <> %s
			) latest
			INNER JOIN (
				SELECT path, COUNT(*) AS visit_count
				FROM {$table}
				WHERE user_id = %d
					AND is_logged_in = 1
					AND path LIKE %s
					AND path <> %s
				GROUP BY path
			) counts
				ON latest.path = counts.path
			ORDER BY latest.visited_at DESC",
			$user_id,
			$jobs_pattern,
			$jobs_index_uri,
			$user_id,
			$jobs_pattern,
			$jobs_index_uri,
			$user_id,
			$jobs_pattern,
			$jobs_index_uri
		) . $limit_sql;

		$results = $wpdb->get_results( $query, ARRAY_A );

		return is_array( $results ) ? array_values( $this->dedupe_job_rows( $results ) ) : array();
	}

	/**
	 * Get the saved response for a user and job.
	 *
	 * @param int    $user_id User ID.
	 * @param string $job_path Job path.
	 * @return array<string, mixed>
	 */
	private function get_user_response( $user_id, $job_path ) {
		global $wpdb;

		$table = $wpdb->prefix . 'jcpst_job_responses';
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE user_id = %d AND job_path = %s LIMIT 1",
				$user_id,
				$job_path
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * Insert or update a user's response for a job.
	 *
	 * @param int                  $user_id User ID.
	 * @param array<string, mixed> $payload Response payload.
	 * @return void
	 */
	private function upsert_user_response( $user_id, $payload ) {
		global $wpdb;

		$table    = $wpdb->prefix . 'jcpst_job_responses';
		$existing = $this->get_user_response( $user_id, $payload['job_path'] );
		$now      = current_time( 'mysql', true );
		$data     = array(
			'user_id'     => $user_id,
			'job_path'    => $payload['job_path'],
			'job_url'     => $payload['job_url'],
			'job_title'   => $payload['job_title'],
			'applied'     => (int) $payload['applied'],
			'interviewed' => (int) $payload['interviewed'],
			'offered'     => (int) $payload['offered'],
			'updated_at'  => $now,
		);

		if ( empty( $existing ) ) {
			$data['created_at'] = $now;
			$wpdb->insert(
				$table,
				$data,
				array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%s' )
			);
			return;
		}

		$wpdb->update(
			$table,
			$data,
			array(
				'user_id'  => $user_id,
				'job_path' => $payload['job_path'],
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Get aggregate job stats for display.
	 *
	 * @param string $job_path Job path.
	 * @return array<string, int>
	 */
	private function get_job_stats( $job_path ) {
		global $wpdb;

		$table            = $wpdb->prefix . 'jcpst_job_responses';
		$applicant_count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE job_path = %s AND applied = 1",
				$job_path
			)
		);
		$interview_count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE job_path = %s AND applied = 1 AND interviewed = 1",
				$job_path
			)
		);
		$offer_count      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE job_path = %s AND applied = 1 AND offered = 1",
				$job_path
			)
		);
		$interview_rate   = $applicant_count > 0 ? (int) round( ( $interview_count / $applicant_count ) * 100 ) : 0;
		$offer_rate       = $applicant_count > 0 ? (int) round( ( $offer_count / $applicant_count ) * 100 ) : 0;

		return array(
			'applicant_count' => $applicant_count,
			'interview_rate'  => $interview_rate,
			'offer_rate'      => $offer_rate,
		);
	}

	/**
	 * Dedupe rows in PHP in case multiple pageviews share the same latest timestamp.
	 *
	 * @param array<int, array<string, mixed>> $rows Raw rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function dedupe_job_rows( $rows ) {
		$deduped = array();

		foreach ( $rows as $row ) {
			$key = isset( $row['path'] ) ? (string) $row['path'] : '';
			if ( '' === $key || isset( $deduped[ $key ] ) ) {
				continue;
			}

			$deduped[ $key ] = $row;
		}

		return $deduped;
	}

	/**
	 * Build a human-readable fallback title from a path.
	 *
	 * @param string $path Page path.
	 * @return string
	 */
	private function build_title_from_path( $path ) {
		$slug = trim( (string) $path, '/' );
		$slug = preg_replace( '#^jobs/#', '', $slug );
		$slug = str_replace( array( '-', '_' ), ' ', (string) $slug );
		$slug = trim( preg_replace( '/\s+/', ' ', (string) $slug ) );

		return $slug ? ucwords( $slug ) : __( 'Viewed Job', 'jcp-session-tracker' );
	}

	/**
	 * Print shortcode styles once.
	 *
	 * @return void
	 */
	private function render_styles_once() {
		if ( self::$did_render_styles ) {
			return;
		}

		self::$did_render_styles = true;
		?>
		<style>
			.jcpst-recent-jobs {
				margin-top: 28px;
			}
			.jcpst-recent-jobs__header {
				margin-bottom: 14px;
			}
			.jcpst-recent-jobs__label {
				display: inline-flex;
				align-items: center;
				color: #1f2937;
				font-weight: 600;
				font-size: 24px;
				line-height: 1.2;
			}
			.jcpst-recent-jobs__panel {
				background: #ffffff;
				border: 1px solid #e5e7eb;
				border-radius: 18px;
				box-shadow: 0 14px 35px rgba(15, 23, 42, 0.06);
				padding: 24px;
			}
			.jcpst-recent-jobs__intro {
				display: flex;
				align-items: flex-start;
				justify-content: space-between;
				gap: 18px;
				padding-bottom: 18px;
				margin-bottom: 18px;
				border-bottom: 1px solid #edf0f4;
			}
			.jcpst-recent-jobs__intro-heading {
				font-size: 18px;
				font-weight: 700;
				color: #111827;
			}
			.jcpst-recent-jobs__intro-description {
				margin: 8px 0 0;
				color: #667085;
				line-height: 1.6;
				font-size: 15px;
				max-width: 700px;
			}
			.jcpst-recent-jobs__list {
				list-style: none;
				margin: 0;
				padding: 0;
				display: flex;
				flex-direction: column;
				gap: 16px;
			}
			.jcpst-recent-jobs__item {
				display: grid;
				grid-template-columns: minmax(0, 1fr) minmax(360px, 420px);
				gap: 22px;
				align-items: flex-start;
				border: 1px solid #edf0f4;
				border-radius: 16px;
				padding: 18px;
				background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
			}
			.jcpst-recent-jobs__title {
				display: inline-block;
				font-size: 18px;
				font-weight: 700;
				color: #0f172a;
				text-decoration: none;
			}
			.jcpst-recent-jobs__title:hover {
				color: #0a66c2;
			}
			.jcpst-recent-jobs__meta {
				margin-top: 8px;
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
				color: #64748b;
				font-size: 14px;
			}
			.jcpst-recent-jobs__response {
				border-left: 1px solid #edf0f4;
				padding-left: 22px;
			}
			.jcpst-job-response {
				display: block;
				max-width: 460px;
			}
			.jcpst-job-response__interaction {
				display: flex;
				align-items: flex-end;
				gap: 10px;
			}
			.jcpst-job-response__controls {
				display: grid;
				row-gap: 4px;
				flex: 1 1 auto;
				min-width: 0;
			}
			.jcpst-job-response__labels,
			.jcpst-job-response__segments {
				display: grid;
				grid-template-columns: repeat(3, minmax(0, 1fr));
				column-gap: 0;
			}
			.jcpst-job-response__labels {
				margin-bottom: 0;
				padding: 0 1px;
			}
			.jcpst-job-response__stats {
				min-height: 118px;
				display: flex;
				align-items: stretch;
				max-width: 460px;
				width: 100%;
			}
			.jcpst-job-response[hidden],
			.jcpst-job-response__stats[hidden] {
				display: none !important;
			}
			.jcpst-job-response__prompt {
				font-size: 12px;
				font-weight: 600;
				color: #111827;
				text-align: center;
				min-width: 78px;
				letter-spacing: 0.01em;
			}
			.jcpst-job-response__segment {
				display: flex;
			}
			.jcpst-job-response__group {
				display: flex;
				border: 1px solid #d9dee7;
				border-right-width: 0;
				border-radius: 0;
				padding: 0;
				background:
					linear-gradient(180deg, #ffffff 0%, #fbfcff 100%),
					radial-gradient(circle at top left, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0) 40%);
				gap: 0;
				overflow: hidden;
				width: 100%;
				min-height: 46px;
				box-shadow: 0 10px 24px rgba(15, 23, 42, 0.06);
			}
			.jcpst-job-response__segment--first .jcpst-job-response__group {
				border-top-left-radius: 16px;
				border-bottom-left-radius: 16px;
			}
			.jcpst-job-response__segment--last .jcpst-job-response__group {
				border-right-width: 1px;
				border-top-right-radius: 16px;
				border-bottom-right-radius: 16px;
			}
			.jcpst-job-response__segment--middle .jcpst-job-response__group,
			.jcpst-job-response__segment--last .jcpst-job-response__group {
				position: relative;
			}
			.jcpst-job-response__segment--middle .jcpst-job-response__group::before,
			.jcpst-job-response__segment--last .jcpst-job-response__group::before {
				content: "";
				position: absolute;
				left: 0;
				top: 9px;
				bottom: 9px;
				width: 1px;
				background: #d9dee7;
			}
			.jcpst-job-response__option {
				border: 0;
				background: transparent;
				color: #3f4d61;
				padding: 7px 10px;
				font-weight: 600;
				cursor: pointer;
				flex: 1 1 50%;
				text-align: center;
				position: relative;
				font-size: 14px;
				transition: background-color 0.18s ease, color 0.18s ease;
			}
			.jcpst-job-response__option + .jcpst-job-response__option {
				border-left: 1px solid #e2e8f0;
			}
			.jcpst-job-response__option:hover:not(.is-selected),
			.jcpst-job-response__option:focus-visible:not(.is-selected) {
				background: #eef6ff;
				color: #0f4f95;
				outline: none;
			}
			.jcpst-job-response__option.is-selected {
				background: #0a84ff;
				color: #ffffff;
			}
			.jcpst-job-response__option.is-selected:hover,
			.jcpst-job-response__option.is-selected:focus-visible {
				background: #0673e8;
				color: #ffffff;
				outline: none;
			}
			.jcpst-job-response__footer {
				display: flex;
				align-items: center;
				justify-content: center;
				flex: 0 0 auto;
				min-height: 46px;
			}
			.jcpst-job-response__submit,
			.jcpst-job-stats__edit {
				background:
					linear-gradient(180deg, #ffffff 0%, #f8fbff 100%),
					radial-gradient(circle at top left, rgba(255,255,255,0.9) 0%, rgba(255,255,255,0) 42%);
				color: #0a66c2;
				border: 1px solid #d6dae1;
				border-radius: 999px;
				padding: 8px 16px;
				font-weight: 600;
				cursor: pointer;
				box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
				white-space: nowrap;
			}
			.jcpst-job-response__submit:hover,
			.jcpst-job-stats__edit:hover {
				background: #eef6ff;
				border-color: #9dc2ef;
				color: #0857a8;
			}
			.jcpst-job-response__submit:focus-visible,
			.jcpst-job-stats__edit:focus-visible {
				outline: 2px solid #8fc2ff;
				outline-offset: 2px;
			}
			.jcpst-job-response__submit:disabled {
				background: linear-gradient(180deg, #f8fbff 0%, #edf4ff 100%);
				color: #6b8fba;
				border-color: #d6e4f6;
				box-shadow: none;
				cursor: wait;
				opacity: 1;
			}
			.jcpst-job-response__status {
				font-size: 13px;
				color: #64748b;
				min-height: 16px;
				padding-left: 2px;
				margin-top: 6px;
			}
			.jcpst-job-stats__count {
				display: flex;
				flex-direction: column;
				align-items: flex-start;
				justify-content: center;
				min-width: 94px;
				padding-right: 0;
				align-self: stretch;
			}
			.jcpst-job-stats__count-number {
				font-size: 32px;
				line-height: 1;
				font-weight: 700;
				color: #0a66c2;
			}
			.jcpst-job-stats__count-label {
				margin-top: 6px;
				font-size: 11px;
				font-weight: 700;
				letter-spacing: 0.02em;
				text-transform: uppercase;
				color: #0a66c2;
			}
			.jcpst-job-stats {
				width: 100%;
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 12px;
				min-height: 118px;
				padding: 8px 12px 28px;
				border-radius: 16px;
				background:
					linear-gradient(180deg, #f7f9fd 0%, #eff4fb 100%),
					radial-gradient(circle at top left, rgba(255,255,255,0.85) 0%, rgba(255,255,255,0) 45%);
				border: 1px solid #e0e8f5;
				position: relative;
			}
			.jcpst-job-stats__chart {
				flex: 1 1 auto;
				display: flex;
				flex-direction: column;
				gap: 4px;
			}
			.jcpst-job-stats__chart-head,
			.jcpst-job-stats__chart-values {
				display: flex;
				align-items: center;
				justify-content: space-evenly;
				font-size: 11px;
				font-weight: 600;
				color: #475467;
			}
			.jcpst-job-stats__chart-values {
				font-weight: 700;
				color: #111827;
			}
			.jcpst-job-stats__plot {
				height: 48px;
				display: flex;
				align-items: flex-end;
				justify-content: space-evenly;
				padding: 0 12px;
				background:
					linear-gradient(180deg, rgba(15, 23, 42, 0.05) 1px, transparent 1px),
					linear-gradient(90deg, rgba(15, 23, 42, 0.05) 1px, transparent 1px),
					#edf2f8;
				background-size: 100% 12px, 16px 100%, auto;
				border-radius: 8px;
				border: 1px solid #dbe4ef;
			}
			.jcpst-job-stats__bar-wrap {
				width: 28px;
				height: 100%;
				display: flex;
				align-items: flex-end;
				justify-content: center;
			}
			.jcpst-job-stats__bar {
				display: block;
				width: 20px;
				border-radius: 4px 4px 0 0;
			}
			.jcpst-job-stats__bar--interview {
				background: linear-gradient(180deg, #f87171 0%, #dc2626 100%);
			}
			.jcpst-job-stats__bar--offer {
				background: linear-gradient(180deg, #34d399 0%, #059669 100%);
			}
			.jcpst-job-stats__edit {
				margin-top: 0;
				flex-shrink: 0;
				align-self: center;
			}
			.jcpst-job-stats__note {
				position: absolute;
				left: 12px;
				right: 12px;
				bottom: 6px;
				font-size: 10px;
				line-height: 1.3;
				color: #667085;
				text-align: left;
			}
			.jcpst-recent-jobs__message {
				margin: 0;
				color: #64748b;
			}
			@media (max-width: 980px) {
				.jcpst-recent-jobs__item {
					grid-template-columns: 1fr;
				}
				.jcpst-recent-jobs__response {
					border-left: 0;
					border-top: 1px solid #edf0f4;
					padding-left: 0;
					padding-top: 18px;
				}
				.jcpst-job-response {
					min-height: 0;
					max-width: none;
				}
				.jcpst-job-response__interaction {
					flex-direction: column;
					align-items: stretch;
				}
				.jcpst-job-response__labels,
				.jcpst-job-response__segments {
					grid-template-columns: 1fr;
					row-gap: 8px;
				}
				.jcpst-job-response__prompt {
					min-width: 0;
				}
				.jcpst-job-response__group,
				.jcpst-job-response__segment--last .jcpst-job-response__group,
				.jcpst-job-response__segment--first .jcpst-job-response__group {
					border-right-width: 1px;
					border-radius: 18px;
				}
				.jcpst-job-response__segment--middle .jcpst-job-response__group::before,
				.jcpst-job-response__segment--last .jcpst-job-response__group::before {
					display: none;
				}
				.jcpst-job-response__footer {
					align-items: stretch;
					min-height: 0;
				}
				.jcpst-job-response__submit {
					width: 100%;
				}
				.jcpst-job-response__stats {
					min-height: 0;
					max-width: none;
				}
				.jcpst-job-stats {
					flex-direction: column;
					align-items: stretch;
					padding-bottom: 30px;
				}
				.jcpst-job-stats__edit {
					align-self: stretch;
				}
				.jcpst-job-stats__note {
					left: 12px;
					right: 12px;
				}
			}
		</style>
		<?php
	}
}
