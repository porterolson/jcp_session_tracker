<?php
/**
 * Admin sessions list table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class JCPST_Sessions_List_Table extends WP_List_Table {

	/**
	 * Allowed order by mapping.
	 *
	 * @var array<string, string>
	 */
	private $order_by_map = array(
		'session_id'      => 'session_id',
		'session_start'   => 'session_start',
		'last_activity'   => 'last_activity',
		'total_pageviews' => 'total_pageviews',
		'pageviews'       => 'total_pageviews',
	);

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'jcpst-session',
				'plural'   => 'jcpst-sessions',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Columns.
	 *
	 * @return array<string, string>
	 */
	public function get_columns() {
		return array(
			'session_id'    => __( 'Session ID', 'jcp-session-tracker' ),
			'user'          => __( 'User', 'jcp-session-tracker' ),
			'session_start' => __( 'Start Time', 'jcp-session-tracker' ),
			'last_activity' => __( 'Last Activity', 'jcp-session-tracker' ),
			'duration'      => __( 'Duration', 'jcp-session-tracker' ),
			'pageviews'     => __( 'Pageviews', 'jcp-session-tracker' ),
			'visited_pages' => __( 'Visited Pages', 'jcp-session-tracker' ),
			'ip'            => __( 'IP', 'jcp-session-tracker' ),
			'login_status'  => __( 'Login Status', 'jcp-session-tracker' ),
			'device'        => __( 'Device Summary', 'jcp-session-tracker' ),
		);
	}

	/**
	 * Sortable columns.
	 *
	 * @return array<string, array<int, string|bool>>
	 */
	protected function get_sortable_columns() {
		return array(
			'session_id'    => array( 'session_id', false ),
			'session_start' => array( 'session_start', true ),
			'last_activity' => array( 'last_activity', false ),
			'pageviews'     => array( 'total_pageviews', false ),
		);
	}

	/**
	 * Default column rendering.
	 *
	 * @param array<string, mixed> $item Session row.
	 * @param string               $column_name Column.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'session_start':
			case 'last_activity':
				return esc_html( get_date_from_gmt( $item[ $column_name ], 'Y-m-d H:i:s' ) );
			case 'pageviews':
				return esc_html( (string) $item['total_pageviews'] );
			case 'ip':
				return esc_html( (string) $item['last_ip'] );
			case 'login_status':
				return ! empty( $item['is_logged_in'] ) ? esc_html__( 'Logged in', 'jcp-session-tracker' ) : esc_html__( 'Guest', 'jcp-session-tracker' );
			case 'device':
				return esc_html( (string) $item['device_summary'] );
			case 'duration':
				return esc_html( $this->format_duration( $item ) );
			case 'visited_pages':
				return $this->render_visited_pages_column( $item );
			default:
				return isset( $item[ $column_name ] ) ? esc_html( (string) $item[ $column_name ] ) : '';
		}
	}

	/**
	 * Session ID column with row action.
	 *
	 * @param array<string, mixed> $item Session row.
	 * @return string
	 */
	protected function column_session_id( $item ) {
		$url = add_query_arg(
			array(
				'page'       => 'jcpst-session-detail',
				'session_id' => rawurlencode( $item['session_id'] ),
			),
			admin_url( 'users.php' )
		);

		$actions = array(
			'view' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $url ),
				esc_html__( 'View Details', 'jcp-session-tracker' )
			),
		);

		return sprintf(
			'<code>%1$s</code>%2$s',
			esc_html( substr( $item['session_id'], 0, 20 ) . '...' ),
			$this->row_actions( $actions )
		);
	}

	/**
	 * User column.
	 *
	 * @param array<string, mixed> $item Session row.
	 * @return string
	 */
	protected function column_user( $item ) {
		if ( empty( $item['user_id'] ) ) {
			return esc_html__( 'Guest', 'jcp-session-tracker' );
		}

		$user = get_userdata( (int) $item['user_id'] );
		if ( ! $user ) {
			return esc_html__( 'Deleted user', 'jcp-session-tracker' );
		}

		$url = get_edit_user_link( $user->ID );
		return sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html( $user->display_name )
		);
	}

	/**
	 * Prepare rows.
	 *
	 * @return void
	 */
	public function prepare_items() {
		$per_page        = 20;
		$current_page    = max( 1, $this->get_pagenum() );
		$offset          = ( $current_page - 1 ) * $per_page;
		$query          = $this->get_query_parts_from_request();
		$total_items    = $this->count_items( $query );
		$items          = $this->get_items( $query, $per_page, $offset );

		$this->items = $this->hydrate_items_with_visited_pages( $items );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total_items / $per_page ),
			)
		);
	}

	/**
	 * Format session duration.
	 *
	 * @param array<string, mixed> $item Session row.
	 * @return string
	 */
	private function format_duration( $item ) {
		$start = strtotime( $item['session_start'] . ' UTC' );
		$end   = $this->get_effective_session_end_timestamp( $item );
		$diff  = max( 0, $end - $start );

		return $this->format_elapsed_seconds( $diff );
	}

	/**
	 * Export all rows for the current request filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_export_items() {
		return $this->hydrate_items_with_visited_pages( $this->get_items( $this->get_query_parts_from_request() ) );
	}

	/**
	 * Build query parts from current request.
	 *
	 * @return array<string, mixed>
	 */
	private function get_query_parts_from_request() {
		global $wpdb;

		$settings        = JCPST_Settings::get();
		$hide_noise      = $this->is_hide_noise_enabled( $settings );
		$orderby_request = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'session_start';
		$order           = isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC';
		$orderby         = isset( $this->order_by_map[ $orderby_request ] ) ? $this->order_by_map[ $orderby_request ] : 'session_start';
		$order           = 'ASC' === $order ? 'ASC' : 'DESC';
		$where           = array( '1=1' );
		$params          = array();

		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		if ( '' !== $search ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(session_id LIKE %s OR landing_page LIKE %s OR exit_page LIKE %s OR first_ip LIKE %s OR last_ip LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( wp_unslash( $_GET['user_id'] ) ) : 0;
		if ( $user_id ) {
			$where[]  = 'user_id = %d';
			$params[] = $user_id;
		}

		$session_id = isset( $_GET['session_id_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['session_id_filter'] ) ) : '';
		if ( '' !== $session_id ) {
			$where[]  = 'session_id LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $session_id ) . '%';
		}

		$ip = isset( $_GET['ip_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['ip_filter'] ) ) : '';
		if ( '' !== $ip ) {
			$where[]  = '(first_ip LIKE %s OR last_ip LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( $ip ) . '%';
			$params[] = '%' . $wpdb->esc_like( $ip ) . '%';
		}

		$status = isset( $_GET['logged_in_status'] ) ? sanitize_text_field( wp_unslash( $_GET['logged_in_status'] ) ) : '';
		if ( 'yes' === $status || 'no' === $status ) {
			$where[]  = 'is_logged_in = %d';
			$params[] = 'yes' === $status ? 1 : 0;
		}

		$min_pageviews = isset( $_GET['min_pageviews'] ) ? absint( wp_unslash( $_GET['min_pageviews'] ) ) : 0;
		if ( $min_pageviews ) {
			$where[]  = 'total_pageviews >= %d';
			$params[] = $min_pageviews;
		}

		$landing = isset( $_GET['landing_page_filter'] ) ? sanitize_text_field( wp_unslash( $_GET['landing_page_filter'] ) ) : '';
		if ( '' !== $landing ) {
			$where[]  = '(landing_page LIKE %s OR exit_page LIKE %s)';
			$params[] = '%' . $wpdb->esc_like( $landing ) . '%';
			$params[] = '%' . $wpdb->esc_like( $landing ) . '%';
		}

		$date_from = isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
			$where[]  = 'session_start >= %s';
			$params[] = gmdate( 'Y-m-d 00:00:00', strtotime( $date_from . ' UTC' ) );
		}

		$date_to = isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '';
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
			$where[]  = 'session_start <= %s';
			$params[] = gmdate( 'Y-m-d 23:59:59', strtotime( $date_to . ' UTC' ) );
		}

		if ( $hide_noise ) {
			$noise = $this->get_noise_filter_sql();
			$where[] = 'NOT (' . $noise['sql'] . ')';
			$params  = array_merge( $params, $noise['params'] );
		}

		return array(
			'where_sql' => implode( ' AND ', $where ),
			'params'    => $params,
			'orderby'   => $orderby,
			'order'     => $order,
		);
	}

	/**
	 * Determine whether the current request wants noise hidden.
	 *
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return bool
	 */
	private function is_hide_noise_enabled( $settings ) {
		if ( isset( $_GET['hide_noise'] ) ) {
			return '1' === sanitize_text_field( wp_unslash( $_GET['hide_noise'] ) );
		}

		return ! empty( $settings['hide_noise_sessions'] );
	}

	/**
	 * Build SQL used to hide likely scanner/noise sessions.
	 *
	 * @return array<string, mixed>
	 */
	private function get_noise_filter_sql() {
		$patterns = array(
			'%.zip%',
			'%.tar%',
			'%.tar.gz%',
			'%.gz%',
			'%.sql%',
			'%.bak%',
			'%.7z%',
			'%.rar%',
			'%backup%',
			'%wp-db%',
			'%wp-export%',
			'%wp-full%',
			'%phpmyadmin%',
			'%adminer%',
			'%.env%',
			'%dump%',
			'%appsettings.json%',
			'%appsettings.development.json%',
			'%config/default.json%',
			'%config.json%',
			'%phpinfo.php%',
			'%server-status%',
			'%web.config%',
			'%composer.json%',
			'%package.json%',
			'%/robots.txt%',
		);

		$sql    = array();
		$params = array();

		foreach ( $patterns as $pattern ) {
			$sql[]    = '(landing_page LIKE %s OR exit_page LIKE %s)';
			$params[] = $pattern;
			$params[] = $pattern;
		}

		$scanner_sql = implode( ' OR ', $sql );

		return array(
			'sql'    => "(user_id IS NULL AND total_pageviews = 1 AND (device_summary LIKE %s OR {$scanner_sql}))",
			'params' => array_merge( array( '%Unknown Browser%' ), $params ),
		);
	}

	/**
	 * Count rows for a query.
	 *
	 * @param array<string, mixed> $query Query parts.
	 * @return int
	 */
	private function count_items( $query ) {
		global $wpdb;

		$table       = $wpdb->prefix . 'jcpst_sessions';
		$count_sql   = "SELECT COUNT(*) FROM {$table} WHERE {$query['where_sql']}";
		$count_query = ! empty( $query['params'] ) ? $wpdb->prepare( $count_sql, $query['params'] ) : $count_sql;

		return (int) $wpdb->get_var( $count_query );
	}

	/**
	 * Fetch rows for a query.
	 *
	 * @param array<string, mixed> $query Query parts.
	 * @param int|null             $limit Optional limit.
	 * @param int                  $offset Optional offset.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_items( $query, $limit = null, $offset = 0 ) {
		global $wpdb;

		$table   = $wpdb->prefix . 'jcpst_sessions';
		$sql     = "SELECT * FROM {$table} WHERE {$query['where_sql']} ORDER BY {$query['orderby']} {$query['order']}";
		$params  = $query['params'];

		if ( null !== $limit ) {
			$sql      .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $limit;
			$params[] = (int) $offset;
		}

		$prepared = ! empty( $params ) ? $wpdb->prepare( $sql, $params ) : $sql;
		return $wpdb->get_results( $prepared, ARRAY_A );
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

	/**
	 * Attach visited-pages data to session rows.
	 *
	 * @param array<int, array<string, mixed>> $items Session rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function hydrate_items_with_visited_pages( $items ) {
		global $wpdb;

		if ( empty( $items ) ) {
			return $items;
		}

		$session_ids = array_values(
			array_filter(
				array_map(
					static function ( $item ) {
						return isset( $item['session_id'] ) ? (string) $item['session_id'] : '';
					},
					$items
				)
			)
		);

		if ( empty( $session_ids ) ) {
			return $items;
		}

		$pageviews_table = $wpdb->prefix . 'jcpst_pageviews';
		$placeholders    = implode( ',', array_fill( 0, count( $session_ids ), '%s' ) );
		$pageviews       = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT session_id, page_url, visited_at
				FROM {$pageviews_table}
				WHERE session_id IN ({$placeholders})
				ORDER BY visited_at ASC",
				$session_ids
			),
			ARRAY_A
		);

		$visited_map = array();
		foreach ( $pageviews as $pageview ) {
			$session_id = (string) $pageview['session_id'];
			if ( ! isset( $visited_map[ $session_id ] ) ) {
				$visited_map[ $session_id ] = array();
			}

			$visited_map[ $session_id ][] = array(
				'visited_at' => $pageview['visited_at'],
				'page_url'   => $pageview['page_url'],
			);
		}

		foreach ( $items as &$item ) {
			$session_id                  = (string) $item['session_id'];
			$item['visited_pages']       = isset( $visited_map[ $session_id ] ) ? $visited_map[ $session_id ] : array();
			$item['visited_pages_json']  = wp_json_encode( $item['visited_pages'] );
		}
		unset( $item );

		return $items;
	}

	/**
	 * Render visited pages for the sessions list.
	 *
	 * @param array<string, mixed> $item Session row.
	 * @return string
	 */
	private function render_visited_pages_column( $item ) {
		$pages = isset( $item['visited_pages'] ) && is_array( $item['visited_pages'] ) ? $item['visited_pages'] : array();

		if ( empty( $pages ) ) {
			return esc_html__( 'No pageviews recorded', 'jcp-session-tracker' );
		}

		$list_items = array();
		$index      = 0;
		foreach ( $pages as $page ) {
			++$index;
			$list_items[] = '<li>' . esc_html( isset( $page['page_url'] ) ? (string) $page['page_url'] : '' ) . '</li>';
		}

		$json = wp_json_encode( $pages, JSON_PRETTY_PRINT );

		return sprintf(
			'<details><summary>%1$s</summary><ol style="margin:8px 0 8px 18px;">%2$s</ol><details><summary>%3$s</summary><code style="display:block;white-space:pre-wrap;">%4$s</code></details></details>',
			esc_html( sprintf( _n( '%s page', '%s pages', count( $pages ), 'jcp-session-tracker' ), number_format_i18n( count( $pages ) ) ) ),
			implode( '', $list_items ),
			esc_html__( 'View JSON', 'jcp-session-tracker' ),
			esc_html( $json )
		);
	}

	/**
	 * Calculate the active session end point.
	 *
	 * @param array<string, mixed> $item Session row.
	 * @return int
	 */
	private function get_effective_session_end_timestamp( $item ) {
		if ( ! empty( $item['session_end'] ) ) {
			return strtotime( $item['session_end'] . ' UTC' );
		}

		$settings      = JCPST_Settings::get();
		$last_activity = strtotime( $item['last_activity'] . ' UTC' );
		$timeout       = max( 60, absint( $settings['inactivity_timeout'] ) * MINUTE_IN_SECONDS );

		return ( time() - $last_activity ) < $timeout ? time() : $last_activity;
	}
}
