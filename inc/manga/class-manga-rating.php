<?php
/**
 * Manga Rating System.
 *
 * Provides a 5-star rating system with guest and user support,
 * custom database table, caching, and admin override.
 *
 * @package starter Theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Rating
 *
 * Manages manga ratings including storage, calculation, and AJAX submission.
 *
 * @since 1.0.0
 */
class Starter_Manga_Rating {

	/**
	 * Table name without prefix.
	 *
	 * @var string
	 */
	const TABLE_NAME = 'manga_ratings';

	/**
	 * Meta key for cached average rating.
	 *
	 * @var string
	 */
	const META_AVG_RATING = '_starter_manga_average_rating';

	/**
	 * Meta key for rating count.
	 *
	 * @var string
	 */
	const META_RATING_COUNT = '_starter_manga_rating_count';

	/**
	 * Meta key for admin rating override.
	 *
	 * @var string
	 */
	const META_RATING_OVERRIDE = '_starter_manga_rating_override';

	/**
	 * Rate limit: max ratings per IP per hour.
	 *
	 * @var int
	 */
	const RATE_LIMIT = 10;

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_Rating|null
	 */
	private static $instance = null;

	/**
	 * Full table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_Rating
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . self::TABLE_NAME;

		add_action( 'wp_ajax_starter_submit_rating', array( $this, 'ajax_submit_rating' ) );
		add_action( 'wp_ajax_nopriv_starter_submit_rating', array( $this, 'ajax_submit_rating' ) );
		add_action( 'wp_ajax_starter_get_rating', array( $this, 'ajax_get_rating' ) );
		add_action( 'wp_ajax_nopriv_starter_get_rating', array( $this, 'ajax_get_rating' ) );

		// Admin meta box for rating override.
		add_action( 'add_meta_boxes', array( $this, 'add_rating_meta_box' ) );
		add_action( 'save_post_wp-manga', array( $this, 'save_rating_override' ), 10, 2 );
	}

	/**
	 * Create the ratings table using dbDelta.
	 *
	 * @return void
	 */
	public static function create_table() {
		global $wpdb;

		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			manga_id bigint(20) unsigned NOT NULL DEFAULT 0,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			ip_address varchar(45) NOT NULL DEFAULT '',
			rating tinyint(1) unsigned NOT NULL DEFAULT 0,
			date datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY manga_id (manga_id),
			KEY user_id (user_id),
			KEY ip_address (ip_address),
			UNIQUE KEY user_manga (user_id, manga_id, ip_address)
		) $charset_collate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * AJAX handler: Submit a rating.
	 *
	 * @return void
	 */
	public function ajax_submit_rating() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$manga_id = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;
		$rating   = isset( $_POST['rating'] ) ? absint( $_POST['rating'] ) : 0;

		if ( ! $manga_id || $rating < 1 || $rating > 5 ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid rating.', 'starter' ) ) );
		}

		// Verify the manga exists.
		if ( ! get_post( $manga_id ) || 'wp-manga' !== get_post_type( $manga_id ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Manga not found.', 'starter' ) ) );
		}

		$ip_address = $this->get_client_ip();
		$user_id    = get_current_user_id();

		// Rate limiting per IP.
		if ( ! $this->check_rate_limit( $ip_address ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Too many ratings. Please try again later.', 'starter' ) ) );
		}

		// Check for existing rating.
		$existing = $this->get_user_rating( $manga_id, $user_id, $ip_address );

		if ( $existing ) {
			// Update existing rating.
			$this->update_rating( $existing->id, $rating );
		} else {
			// Insert new rating.
			$this->insert_rating( $manga_id, $user_id, $ip_address, $rating );
		}

		// Recalculate and cache average.
		$average = $this->calculate_average( $manga_id );

		wp_send_json_success( array(
			'average'    => round( $average['average'], 2 ),
			'count'      => $average['count'],
			'userRating' => $rating,
			'message'    => esc_html__( 'Rating submitted successfully.', 'starter' ),
		) );
	}

	/**
	 * AJAX handler: Get the rating for a manga.
	 *
	 * @return void
	 */
	public function ajax_get_rating() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$manga_id = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;

		if ( ! $manga_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid manga ID.', 'starter' ) ) );
		}

		// Check for admin override.
		$override = get_post_meta( $manga_id, self::META_RATING_OVERRIDE, true );
		if ( '' !== $override && false !== $override ) {
			$avg   = (float) $override;
			$count = (int) get_post_meta( $manga_id, self::META_RATING_COUNT, true );
		} else {
			$avg   = (float) get_post_meta( $manga_id, self::META_AVG_RATING, true );
			$count = (int) get_post_meta( $manga_id, self::META_RATING_COUNT, true );
		}

		// Get current user's rating.
		$user_rating = 0;
		$user_id     = get_current_user_id();
		$ip_address  = $this->get_client_ip();
		$existing    = $this->get_user_rating( $manga_id, $user_id, $ip_address );

		if ( $existing ) {
			$user_rating = (int) $existing->rating;
		}

		wp_send_json_success( array(
			'average'    => round( $avg, 2 ),
			'count'      => $count,
			'userRating' => $user_rating,
		) );
	}

	/**
	 * Insert a new rating.
	 *
	 * @param int    $manga_id   Manga post ID.
	 * @param int    $user_id    User ID (0 for guests).
	 * @param string $ip_address Client IP.
	 * @param int    $rating     Rating value (1-5).
	 * @return int|false Insert ID or false.
	 */
	private function insert_rating( $manga_id, $user_id, $ip_address, $rating ) {
		global $wpdb;

		$result = $wpdb->insert(
			$this->table,
			array(
				'manga_id'   => $manga_id,
				'user_id'    => $user_id,
				'ip_address' => $ip_address,
				'rating'     => $rating,
				'date'       => current_time( 'mysql' ),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update an existing rating.
	 *
	 * @param int $rating_id Rating row ID.
	 * @param int $rating    New rating value (1-5).
	 * @return bool True on success.
	 */
	private function update_rating( $rating_id, $rating ) {
		global $wpdb;

		$result = $wpdb->update(
			$this->table,
			array(
				'rating' => $rating,
				'date'   => current_time( 'mysql' ),
			),
			array( 'id' => $rating_id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Get a user's existing rating for a manga.
	 *
	 * @param int    $manga_id   Manga post ID.
	 * @param int    $user_id    User ID.
	 * @param string $ip_address Client IP.
	 * @return object|null Rating row or null.
	 */
	private function get_user_rating( $manga_id, $user_id, $ip_address ) {
		global $wpdb;

		if ( $user_id > 0 ) {
			// Logged-in user: match by user_id.
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM {$this->table} WHERE manga_id = %d AND user_id = %d LIMIT 1",
					$manga_id,
					$user_id
				)
			);
		}

		// Guest: match by IP.
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$this->table} WHERE manga_id = %d AND user_id = 0 AND ip_address = %s LIMIT 1",
				$manga_id,
				$ip_address
			)
		);
	}

	/**
	 * Calculate and cache the average rating for a manga.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return array Average and count.
	 */
	public function calculate_average( $manga_id ) {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT AVG(rating) as average, COUNT(*) as count FROM {$this->table} WHERE manga_id = %d",
				absint( $manga_id )
			)
		);

		$average = $row ? (float) $row->average : 0;
		$count   = $row ? (int) $row->count : 0;

		// Cache in post meta.
		update_post_meta( $manga_id, self::META_AVG_RATING, $average );
		update_post_meta( $manga_id, self::META_RATING_COUNT, $count );

		return array(
			'average' => $average,
			'count'   => $count,
		);
	}

	/**
	 * Check rate limit for an IP address.
	 *
	 * @param string $ip_address Client IP.
	 * @return bool True if within limit.
	 */
	private function check_rate_limit( $ip_address ) {
		$transient_key = 'starter_rate_' . md5( $ip_address );
		$count         = (int) get_transient( $transient_key );

		if ( $count >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $transient_key, $count + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Add admin meta box for rating override.
	 *
	 * @return void
	 */
	public function add_rating_meta_box() {
		add_meta_box(
			'starter_manga_rating_override',
			esc_html__( 'Rating Override', 'starter' ),
			array( $this, 'render_rating_meta_box' ),
			'wp-manga',
			'side',
			'low'
		);
	}

	/**
	 * Render the rating override meta box.
	 *
	 * @param WP_Post $post Current post.
	 * @return void
	 */
	public function render_rating_meta_box( $post ) {
		$override = get_post_meta( $post->ID, self::META_RATING_OVERRIDE, true );
		$average  = get_post_meta( $post->ID, self::META_AVG_RATING, true );
		$count    = get_post_meta( $post->ID, self::META_RATING_COUNT, true );

		wp_nonce_field( 'starter_rating_override_save', 'starter_rating_override_nonce' );
		?>
		<p>
			<strong><?php esc_html_e( 'Current Average:', 'starter' ); ?></strong>
			<?php echo esc_html( $average ? round( (float) $average, 2 ) : '0' ); ?>
			(<?php echo esc_html( $count ? (int) $count : '0' ); ?> <?php esc_html_e( 'votes', 'starter' ); ?>)
		</p>
		<p>
			<label for="starter_rating_override"><?php esc_html_e( 'Override Rating (1-5, leave empty for calculated):', 'starter' ); ?></label><br/>
			<input type="number" id="starter_rating_override" name="starter_rating_override"
				value="<?php echo esc_attr( $override ); ?>"
				min="0" max="5" step="0.1" style="width:100%;" />
		</p>
		<?php
	}

	/**
	 * Save rating override from admin.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save_rating_override( $post_id, $post ) {
		if ( ! isset( $_POST['starter_rating_override_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['starter_rating_override_nonce'] ) ), 'starter_rating_override_save' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['starter_rating_override'] ) ) {
			$override = sanitize_text_field( wp_unslash( $_POST['starter_rating_override'] ) );
			if ( '' === $override ) {
				delete_post_meta( $post_id, self::META_RATING_OVERRIDE );
			} else {
				$override = max( 0, min( 5, (float) $override ) );
				update_post_meta( $post_id, self::META_RATING_OVERRIDE, $override );
			}
		}
	}

	/**
	 * Get the display rating for a manga (considering override).
	 *
	 * @param int $manga_id Manga post ID.
	 * @return float Rating value.
	 */
	public static function get_display_rating( $manga_id ) {
		$override = get_post_meta( $manga_id, self::META_RATING_OVERRIDE, true );
		if ( '' !== $override && false !== $override ) {
			return round( (float) $override, 2 );
		}

		$average = get_post_meta( $manga_id, self::META_AVG_RATING, true );
		return $average ? round( (float) $average, 2 ) : 0;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string IP address.
	 */
	private function get_client_ip() {
		$ip = '';

		if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLIENT_IP'] ) );
		} elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$ips = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
			$ip  = trim( $ips[0] );
		} elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}
}
