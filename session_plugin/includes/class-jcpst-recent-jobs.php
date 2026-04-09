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
				'limit'            => 5,
				'cta_url'          => home_url( '/survey/' ),
				'cta_label'        => __( 'Share Your Search', 'jcp-session-tracker' ),
				'cta_heading'      => __( 'Interested in how this role is trending?', 'jcp-session-tracker' ),
				'cta_description'  => __( 'Answer a few quick questions and we can share more context about interest in this position through Job Connections Project.', 'jcp-session-tracker' ),
				'empty_message'    => __( 'No recently viewed job pages yet.', 'jcp-session-tracker' ),
				'login_message'    => __( 'Sign in to see your recently viewed jobs.', 'jcp-session-tracker' ),
				'tab_label'        => __( 'Recently Viewed Jobs', 'jcp-session-tracker' ),
			),
			$atts,
			'jcp_recently_viewed_jobs'
		);

		$limit = max( 1, min( 20, absint( $atts['limit'] ) ) );

		ob_start();
		$this->render_styles_once();

		echo '<section class="jcpst-recent-jobs" aria-label="' . esc_attr( $atts['tab_label'] ) . '">';
		echo '<div class="jcpst-recent-jobs__header">';
		echo '<span class="jcpst-recent-jobs__label">' . esc_html( $atts['tab_label'] ) . '</span>';
		echo '</div>';
		echo '<div class="jcpst-recent-jobs__panel">';

		if ( ! is_user_logged_in() ) {
			echo '<p class="jcpst-recent-jobs__message">' . esc_html( $atts['login_message'] ) . '</p>';
			echo '</div></section>';
			return (string) ob_get_clean();
		}

		$jobs = $this->get_recent_jobs_for_user( get_current_user_id(), $limit );

		if ( empty( $jobs ) ) {
			echo '<p class="jcpst-recent-jobs__message">' . esc_html( $atts['empty_message'] ) . '</p>';
			echo '</div></section>';
			return (string) ob_get_clean();
		}

		echo '<div class="jcpst-recent-jobs__intro">';
		echo '<div class="jcpst-recent-jobs__intro-copy">';
		echo '<div class="jcpst-recent-jobs__intro-heading">' . esc_html( $atts['cta_heading'] ) . '</div>';
		echo '<p class="jcpst-recent-jobs__intro-description">' . esc_html( $atts['cta_description'] ) . '</p>';
		echo '</div>';
		if ( ! empty( $atts['cta_url'] ) ) {
			echo '<a class="jcpst-recent-jobs__intro-button" href="' . esc_url( $atts['cta_url'] ) . '">' . esc_html( $atts['cta_label'] ) . '</a>';
		}
		echo '</div>';

		echo '<ul class="jcpst-recent-jobs__list">';

		foreach ( $jobs as $job ) {
			$job_title = ! empty( $job['page_title'] ) ? $job['page_title'] : $this->build_title_from_path( $job['path'] );
			$visited   = ! empty( $job['visited_at'] ) ? get_date_from_gmt( $job['visited_at'], 'M j, Y g:i a' ) : '';
			$page_url  = ! empty( $job['page_url'] ) ? $job['page_url'] : home_url( $job['path'] );

			echo '<li class="jcpst-recent-jobs__item">';
			echo '<div class="jcpst-recent-jobs__details">';
			echo '<a class="jcpst-recent-jobs__title" href="' . esc_url( $page_url ) . '">' . esc_html( $job_title ) . '</a>';
			echo '<div class="jcpst-recent-jobs__meta">';
			echo '<span>' . esc_html__( 'Viewed', 'jcp-session-tracker' ) . ' ' . esc_html( $visited ) . '</span>';
			echo '<span>' . sprintf( esc_html( _n( '%s visit', '%s visits', (int) $job['visit_count'], 'jcp-session-tracker' ) ), number_format_i18n( (int) $job['visit_count'] ) ) . '</span>';
			echo '</div>';
			echo '</div>';
			echo '</li>';
		}

		echo '</ul>';
		echo '</div></section>';

		return (string) ob_get_clean();
	}

	/**
	 * Get recently viewed unique job pages for a user.
	 *
	 * @param int $user_id User ID.
	 * @param int $limit Maximum jobs to return.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_recent_jobs_for_user( $user_id, $limit ) {
		global $wpdb;

		$table          = $wpdb->prefix . 'jcpst_pageviews';
		$jobs_pattern   = '%/jobs/%';
		$jobs_index_uri = '/jobs/';
		$results      = $wpdb->get_results(
			$wpdb->prepare(
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
				ORDER BY latest.visited_at DESC
				LIMIT %d",
				$user_id,
				$jobs_pattern,
				$jobs_index_uri,
				$user_id,
				$jobs_pattern,
				$jobs_index_uri,
				$user_id,
				$jobs_pattern,
				$jobs_index_uri,
				$limit
			),
			ARRAY_A
		);

		return is_array( $results ) ? array_values( $this->dedupe_job_rows( $results ) ) : array();
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
				border-radius: 0;
				padding: 0;
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
				align-items: center;
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
			.jcpst-recent-jobs__intro-button {
				flex-shrink: 0;
				background: #ffffff;
				color: #0a66c2;
				text-decoration: none;
				border-radius: 999px;
				padding: 11px 18px;
				font-weight: 600;
				white-space: nowrap;
				border: 1px solid #d6dae1;
				box-shadow: 0 6px 16px rgba(15, 23, 42, 0.06);
			}
			.jcpst-recent-jobs__intro-button:hover {
				background: #f8fafc;
				color: #0a66c2;
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
			.jcpst-recent-jobs__message {
				margin: 0;
				color: #64748b;
			}
			@media (max-width: 860px) {
				.jcpst-recent-jobs__intro {
					flex-direction: column;
					align-items: flex-start;
				}
				.jcpst-recent-jobs__intro-button {
					width: 100%;
					text-align: center;
				}
			}
		</style>
		<?php
	}
}
