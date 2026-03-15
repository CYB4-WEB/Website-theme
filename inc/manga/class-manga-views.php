<?php
/**
 * Manga View Counting.
 *
 * Tracks manga/chapter views with bot detection, IP dedup, custom table
 * logging, transient caching, and admin column display.
 *
 * @package starter-theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Views
 *
 * Manages view counting with bot detection, daily logging in a custom table,
 * and aggregated popular-manga queries.
 *
 * @since 1.0.0
 */
class Starter_Manga_Views {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_Views|null
	 */
	private static $instance = null;

	/**
	 * Views log table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_NAME = 'starter_views_log';

	/**
	 * Total views post meta key.
	 *
	 * @var string
	 */
	const META_TOTAL = '_starter_views_total';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'starter_views_nonce';

	/**
	 * Transient cache TTL in seconds (15 minutes).
	 *
	 * @var int
	 */
	const CACHE_TTL = 900;

	/**
	 * IP dedup window in seconds (1 hour).
	 *
	 * @var int
	 */
	const DEDUP_WINDOW = 3600;

	/**
	 * Common bot user-agent patterns.
	 *
	 * @var array
	 */
	private $bot_patterns = array(
		'googlebot',
		'bingbot',
		'slurp',
		'duckduckbot',
		'baiduspider',
		'yandexbot',
		'sogou',
		'exabot',
		'facebot',
		'facebookexternalhit',
		'ia_archiver',
		'semrushbot',
		'ahrefsbot',
		'dotbot',
		'mj12bot',
		'petalbot',
		'applebot',
		'twitterbot',
		'linkedinbot',
		'bytespider',
		'gptbot',
		'claudebot',
		'anthropic',
		'crawler',
		'spider',
		'bot/',
		'headlesschrome',
		'phantomjs',
	);

	/**
	 * Full table name with prefix.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_Views
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . self::TABLE_NAME;
		$this->register_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'after_switch_theme', array( $this, 'create_table' ) );
		add_action( 'init', array( $this, 'register_ajax_handlers' ) );
		add_filter( 'manage_wp-manga_posts_columns', array( $this, 'add_views_column' ) );
		add_action( 'manage_wp-manga_posts_custom_column', array( $this, 'render_views_column' ), 10, 2 );
		add_filter( 'manage_edit-wp-manga_sortable_columns', array( $this, 'sortable_views_column' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_views' ) );
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public function register_ajax_handlers() {
		add_action( 'wp_ajax_starter_increment_view', array( $this, 'ajax_increment_view' ) );
		add_action( 'wp_ajax_nopriv_starter_increment_view', array( $this, 'ajax_increment_view' ) );
	}

	/**
	 * Create the views log table using dbDelta.
	 *
	 * @return void
	 */
	public function create_table() {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$this->table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			manga_id bigint(20) unsigned NOT NULL DEFAULT 0,
			chapter_id bigint(20) unsigned NOT NULL DEFAULT 0,
			date date NOT NULL,
			views bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY manga_date (manga_id, date),
			KEY date_views (date, views),
			KEY chapter_id (chapter_id)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * AJAX handler: Increment view count (fire-and-forget from JS).
	 *
	 * @return void
	 */
	public function ajax_increment_view() {
		// Nonce verification for logged-in; for nopriv we still check a lightweight nonce.
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$manga_id   = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;
		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;

		if ( ! $manga_id || 'wp-manga' !== get_post_type( $manga_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid manga.', 'starter' ) ) );
		}

		// Bot detection.
		if ( $this->is_bot() ) {
			wp_send_json_success( array( 'message' => __( 'OK', 'starter' ) ) );
		}

		// IP-based deduplication.
		if ( $this->is_duplicate_view( $manga_id ) ) {
			wp_send_json_success( array( 'message' => __( 'OK', 'starter' ) ) );
		}

		$this->record_view( $manga_id, $chapter_id );

		wp_send_json_success( array( 'message' => __( 'View recorded.', 'starter' ) ) );
	}

	/**
	 * Check if the current request is from a bot.
	 *
	 * @return bool
	 */
	private function is_bot() {
		if ( ! isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			return true;
		}

		$ua = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) );

		foreach ( $this->bot_patterns as $pattern ) {
			if ( false !== strpos( $ua, $pattern ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check for duplicate view using transients (1 view per IP per manga per hour).
	 *
	 * @param int $manga_id Manga post ID.
	 * @return bool True if duplicate.
	 */
	private function is_duplicate_view( $manga_id ) {
		$ip  = $this->get_client_ip();
		$key = 'starter_view_' . md5( $ip . '_' . $manga_id );

		if ( false !== get_transient( $key ) ) {
			return true;
		}

		set_transient( $key, 1, self::DEDUP_WINDOW );

		return false;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// Handle comma-separated IPs (X-Forwarded-For).
				if ( false !== strpos( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Record a view in the custom table and update post meta.
	 *
	 * @param int $manga_id   Manga post ID.
	 * @param int $chapter_id Chapter ID (0 for manga-level view).
	 * @return void
	 */
	private function record_view( $manga_id, $chapter_id = 0 ) {
		global $wpdb;

		$today = current_time( 'Y-m-d' );

		// Upsert daily log row.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table} WHERE manga_id = %d AND chapter_id = %d AND date = %s",
				$manga_id,
				$chapter_id,
				$today
			)
		);

		if ( $existing ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$this->table} SET views = views + 1 WHERE id = %d",
					$existing
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert(
				$this->table,
				array(
					'manga_id'   => $manga_id,
					'chapter_id' => $chapter_id,
					'date'       => $today,
					'views'      => 1,
				),
				array( '%d', '%d', '%s', '%d' )
			);
		}

		// Increment total views in post meta.
		$total = absint( get_post_meta( $manga_id, self::META_TOTAL, true ) );
		update_post_meta( $manga_id, self::META_TOTAL, $total + 1 );

		// Invalidate popular-manga transients.
		delete_transient( 'starter_popular_daily' );
		delete_transient( 'starter_popular_weekly' );
		delete_transient( 'starter_popular_monthly' );
	}

	/**
	 * Get total views for a manga.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return int
	 */
	public function get_total_views( $manga_id ) {
		return absint( get_post_meta( $manga_id, self::META_TOTAL, true ) );
	}

	/**
	 * Get popular manga for a given period.
	 *
	 * @param string $period Period: daily, weekly, monthly, all_time.
	 * @param int    $limit  Number of results.
	 * @return array Array of objects with manga_id and total_views.
	 */
	public function get_popular_manga( $period = 'weekly', $limit = 10 ) {
		$limit = absint( $limit );
		if ( $limit < 1 ) {
			$limit = 10;
		}

		$transient_key = 'starter_popular_' . sanitize_key( $period ) . '_' . $limit;
		$cached        = get_transient( $transient_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$where_date = '';
		$today      = current_time( 'Y-m-d' );

		switch ( $period ) {
			case 'daily':
				$where_date = $wpdb->prepare( 'AND date = %s', $today );
				break;
			case 'weekly':
				$week_ago   = gmdate( 'Y-m-d', strtotime( $today . ' -7 days' ) );
				$where_date = $wpdb->prepare( 'AND date >= %s', $week_ago );
				break;
			case 'monthly':
				$month_ago  = gmdate( 'Y-m-d', strtotime( $today . ' -30 days' ) );
				$where_date = $wpdb->prepare( 'AND date >= %s', $month_ago );
				break;
			case 'all_time':
			default:
				// No date filter.
				break;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT manga_id, SUM(views) AS total_views
				FROM {$this->table}
				WHERE 1=1 {$where_date}
				GROUP BY manga_id
				ORDER BY total_views DESC
				LIMIT %d",
				$limit
			)
		);

		if ( ! is_array( $results ) ) {
			$results = array();
		}

		set_transient( $transient_key, $results, self::CACHE_TTL );

		return $results;
	}

	/**
	 * Get view stats for a manga (daily breakdown).
	 *
	 * @param int $manga_id Manga post ID.
	 * @param int $days     Number of days to retrieve.
	 * @return array
	 */
	public function get_manga_stats( $manga_id, $days = 30 ) {
		global $wpdb;

		$start_date = gmdate( 'Y-m-d', strtotime( '-' . absint( $days ) . ' days' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT date, SUM(views) AS daily_views
				FROM {$this->table}
				WHERE manga_id = %d AND date >= %s
				GROUP BY date
				ORDER BY date ASC",
				$manga_id,
				$start_date
			)
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Add views column to admin manga list.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_views_column( $columns ) {
		$columns['starter_views'] = __( 'Views', 'starter' );
		return $columns;
	}

	/**
	 * Render the views column content.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 * @return void
	 */
	public function render_views_column( $column, $post_id ) {
		if ( 'starter_views' === $column ) {
			echo esc_html( number_format_i18n( $this->get_total_views( $post_id ) ) );
		}
	}

	/**
	 * Make the views column sortable.
	 *
	 * @param array $columns Sortable columns.
	 * @return array
	 */
	public function sortable_views_column( $columns ) {
		$columns['starter_views'] = 'starter_views';
		return $columns;
	}

	/**
	 * Handle sorting by views in admin.
	 *
	 * @param WP_Query $query Query object.
	 * @return void
	 */
	public function sort_by_views( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		if ( 'starter_views' !== $query->get( 'orderby' ) ) {
			return;
		}

		$query->set( 'meta_key', self::META_TOTAL );
		$query->set( 'orderby', 'meta_value_num' );
	}
}
