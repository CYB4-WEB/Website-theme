<?php
/**
 * Manga Bookmark System.
 *
 * Manages user bookmarks for manga with AJAX add/remove, shortcode display,
 * and guest support via cookies.
 *
 * @package starter-theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Bookmark
 *
 * Handles manga bookmarks stored in user meta for logged-in users
 * and cookies for guests.
 *
 * @since 1.0.0
 */
class Starter_Manga_Bookmark {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_Bookmark|null
	 */
	private static $instance = null;

	/**
	 * User meta key for bookmarks.
	 *
	 * @var string
	 */
	const META_KEY = '_starter_bookmarks';

	/**
	 * Post meta key for bookmark count.
	 *
	 * @var string
	 */
	const COUNT_META_KEY = '_starter_bookmark_count';

	/**
	 * Cookie name for guest bookmarks.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'starter_guest_bookmarks';

	/**
	 * Maximum guest bookmarks.
	 *
	 * @var int
	 */
	const GUEST_LIMIT = 50;

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'starter_bookmark_nonce';

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_Bookmark
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
		$this->register_hooks();
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks() {
		add_action( 'init', array( $this, 'register_ajax_handlers' ) );
		add_shortcode( 'starter_bookmarks', array( $this, 'render_bookmarks_shortcode' ) );
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public function register_ajax_handlers() {
		add_action( 'wp_ajax_starter_add_bookmark', array( $this, 'ajax_add_bookmark' ) );
		add_action( 'wp_ajax_nopriv_starter_add_bookmark', array( $this, 'ajax_add_bookmark' ) );
		add_action( 'wp_ajax_starter_remove_bookmark', array( $this, 'ajax_remove_bookmark' ) );
		add_action( 'wp_ajax_nopriv_starter_remove_bookmark', array( $this, 'ajax_remove_bookmark' ) );
	}

	/**
	 * AJAX handler: Add a bookmark.
	 *
	 * @return void
	 */
	public function ajax_add_bookmark() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$manga_id = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;

		if ( ! $manga_id || 'wp-manga' !== get_post_type( $manga_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid manga.', 'starter' ) ) );
		}

		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;

		$bookmark = array(
			'manga_id'       => $manga_id,
			'last_chapter_id' => $chapter_id,
			'date_added'     => current_time( 'mysql' ),
		);

		if ( is_user_logged_in() ) {
			$result = $this->add_user_bookmark( get_current_user_id(), $bookmark );
		} else {
			$result = $this->add_guest_bookmark( $bookmark );
		}

		if ( $result ) {
			$this->update_bookmark_count( $manga_id, 1 );
			wp_send_json_success( array(
				'message' => __( 'Bookmark added.', 'starter' ),
				'count'   => $this->get_bookmark_count( $manga_id ),
			) );
		}

		wp_send_json_error( array( 'message' => __( 'Already bookmarked.', 'starter' ) ) );
	}

	/**
	 * AJAX handler: Remove a bookmark.
	 *
	 * @return void
	 */
	public function ajax_remove_bookmark() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$manga_id = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;

		if ( ! $manga_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid manga.', 'starter' ) ) );
		}

		if ( is_user_logged_in() ) {
			$result = $this->remove_user_bookmark( get_current_user_id(), $manga_id );
		} else {
			$result = $this->remove_guest_bookmark( $manga_id );
		}

		if ( $result ) {
			$this->update_bookmark_count( $manga_id, -1 );
			wp_send_json_success( array(
				'message' => __( 'Bookmark removed.', 'starter' ),
				'count'   => $this->get_bookmark_count( $manga_id ),
			) );
		}

		wp_send_json_error( array( 'message' => __( 'Bookmark not found.', 'starter' ) ) );
	}

	/**
	 * Add bookmark for a logged-in user.
	 *
	 * @param int   $user_id  User ID.
	 * @param array $bookmark Bookmark data.
	 * @return bool True if added, false if already exists.
	 */
	private function add_user_bookmark( $user_id, $bookmark ) {
		$bookmarks = $this->get_user_bookmarks( $user_id );

		foreach ( $bookmarks as $existing ) {
			if ( (int) $existing['manga_id'] === (int) $bookmark['manga_id'] ) {
				return false;
			}
		}

		$bookmarks[] = $bookmark;
		update_user_meta( $user_id, self::META_KEY, $bookmarks );

		return true;
	}

	/**
	 * Remove bookmark for a logged-in user.
	 *
	 * @param int $user_id  User ID.
	 * @param int $manga_id Manga post ID.
	 * @return bool True if removed.
	 */
	private function remove_user_bookmark( $user_id, $manga_id ) {
		$bookmarks = $this->get_user_bookmarks( $user_id );
		$found     = false;

		$bookmarks = array_filter( $bookmarks, function ( $b ) use ( $manga_id, &$found ) {
			if ( (int) $b['manga_id'] === (int) $manga_id ) {
				$found = true;
				return false;
			}
			return true;
		} );

		if ( $found ) {
			update_user_meta( $user_id, self::META_KEY, array_values( $bookmarks ) );
		}

		return $found;
	}

	/**
	 * Get all bookmarks for a user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_user_bookmarks( $user_id ) {
		$bookmarks = get_user_meta( $user_id, self::META_KEY, true );

		if ( ! is_array( $bookmarks ) ) {
			return array();
		}

		return $bookmarks;
	}

	/**
	 * Add bookmark for a guest (cookie-based).
	 *
	 * @param array $bookmark Bookmark data.
	 * @return bool True if added, false if already exists.
	 */
	private function add_guest_bookmark( $bookmark ) {
		$bookmarks = $this->get_guest_bookmarks();

		foreach ( $bookmarks as $existing ) {
			if ( (int) $existing['manga_id'] === (int) $bookmark['manga_id'] ) {
				return false;
			}
		}

		$bookmarks[] = $bookmark;

		// Enforce guest limit.
		if ( count( $bookmarks ) > self::GUEST_LIMIT ) {
			$bookmarks = array_slice( $bookmarks, -self::GUEST_LIMIT );
		}

		$this->set_guest_cookie( $bookmarks );

		return true;
	}

	/**
	 * Remove a guest bookmark.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return bool True if removed.
	 */
	private function remove_guest_bookmark( $manga_id ) {
		$bookmarks = $this->get_guest_bookmarks();
		$found     = false;

		$bookmarks = array_filter( $bookmarks, function ( $b ) use ( $manga_id, &$found ) {
			if ( (int) $b['manga_id'] === (int) $manga_id ) {
				$found = true;
				return false;
			}
			return true;
		} );

		if ( $found ) {
			$this->set_guest_cookie( array_values( $bookmarks ) );
		}

		return $found;
	}

	/**
	 * Get guest bookmarks from cookie.
	 *
	 * @return array
	 */
	public function get_guest_bookmarks() {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$raw = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$data = json_decode( base64_decode( $raw ), true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		// Sanitize each entry.
		$clean = array();
		foreach ( $data as $entry ) {
			$clean[] = array(
				'manga_id'       => isset( $entry['manga_id'] ) ? absint( $entry['manga_id'] ) : 0,
				'last_chapter_id' => isset( $entry['last_chapter_id'] ) ? absint( $entry['last_chapter_id'] ) : 0,
				'date_added'     => isset( $entry['date_added'] ) ? sanitize_text_field( $entry['date_added'] ) : '',
			);
		}

		return array_filter( $clean, function ( $b ) {
			return $b['manga_id'] > 0;
		} );
	}

	/**
	 * Set guest bookmarks cookie.
	 *
	 * @param array $bookmarks Bookmark data array.
	 * @return void
	 */
	private function set_guest_cookie( $bookmarks ) {
		$value = base64_encode( wp_json_encode( $bookmarks ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.cookies_setcookie
		setcookie( self::COOKIE_NAME, $value, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
	}

	/**
	 * Update the bookmark count stored in post meta.
	 *
	 * @param int $manga_id  Manga post ID.
	 * @param int $increment +1 or -1.
	 * @return void
	 */
	private function update_bookmark_count( $manga_id, $increment ) {
		$count = $this->get_bookmark_count( $manga_id );
		$count = max( 0, $count + (int) $increment );
		update_post_meta( $manga_id, self::COUNT_META_KEY, $count );
	}

	/**
	 * Get bookmark count for a manga.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return int
	 */
	public function get_bookmark_count( $manga_id ) {
		return absint( get_post_meta( $manga_id, self::COUNT_META_KEY, true ) );
	}

	/**
	 * Check if a manga is bookmarked by the current user/guest.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return bool
	 */
	public function is_bookmarked( $manga_id ) {
		if ( is_user_logged_in() ) {
			$bookmarks = $this->get_user_bookmarks( get_current_user_id() );
		} else {
			$bookmarks = $this->get_guest_bookmarks();
		}

		foreach ( $bookmarks as $b ) {
			if ( (int) $b['manga_id'] === (int) $manga_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Update the last-read chapter for a bookmark.
	 *
	 * @param int $manga_id   Manga post ID.
	 * @param int $chapter_id Chapter ID.
	 * @return void
	 */
	public function update_last_chapter( $manga_id, $chapter_id ) {
		if ( is_user_logged_in() ) {
			$user_id   = get_current_user_id();
			$bookmarks = $this->get_user_bookmarks( $user_id );

			foreach ( $bookmarks as &$b ) {
				if ( (int) $b['manga_id'] === (int) $manga_id ) {
					$b['last_chapter_id'] = absint( $chapter_id );
					break;
				}
			}
			unset( $b );

			update_user_meta( $user_id, self::META_KEY, $bookmarks );
		} else {
			$bookmarks = $this->get_guest_bookmarks();

			foreach ( $bookmarks as &$b ) {
				if ( (int) $b['manga_id'] === (int) $manga_id ) {
					$b['last_chapter_id'] = absint( $chapter_id );
					break;
				}
			}
			unset( $b );

			$this->set_guest_cookie( $bookmarks );
		}
	}

	/**
	 * Get current user/guest bookmarks.
	 *
	 * @return array
	 */
	public function get_current_bookmarks() {
		if ( is_user_logged_in() ) {
			return $this->get_user_bookmarks( get_current_user_id() );
		}
		return $this->get_guest_bookmarks();
	}

	/**
	 * Sort bookmarks by the given criteria.
	 *
	 * @param array  $bookmarks Array of bookmark data.
	 * @param string $order_by  Sort key: date_added, alphabetical, last_updated.
	 * @return array Sorted bookmarks.
	 */
	public function sort_bookmarks( $bookmarks, $order_by = 'date_added' ) {
		switch ( $order_by ) {
			case 'alphabetical':
				usort( $bookmarks, function ( $a, $b ) {
					$title_a = get_the_title( $a['manga_id'] );
					$title_b = get_the_title( $b['manga_id'] );
					return strcasecmp( $title_a, $title_b );
				} );
				break;

			case 'last_updated':
				usort( $bookmarks, function ( $a, $b ) {
					$mod_a = get_post_modified_time( 'U', true, $a['manga_id'] );
					$mod_b = get_post_modified_time( 'U', true, $b['manga_id'] );
					return $mod_b - $mod_a;
				} );
				break;

			case 'date_added':
			default:
				usort( $bookmarks, function ( $a, $b ) {
					return strtotime( $b['date_added'] ) - strtotime( $a['date_added'] );
				} );
				break;
		}

		return $bookmarks;
	}

	/**
	 * Render the [starter_bookmarks] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_bookmarks_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'order_by' => 'date_added',
			'columns'  => 4,
		), $atts, 'starter_bookmarks' );

		$order_by = sanitize_key( $atts['order_by'] );
		$columns  = absint( $atts['columns'] );

		if ( $columns < 1 || $columns > 6 ) {
			$columns = 4;
		}

		$bookmarks = $this->get_current_bookmarks();
		$bookmarks = $this->sort_bookmarks( $bookmarks, $order_by );

		ob_start();
		?>
		<div class="starter-bookmarks-wrap">
			<div class="starter-bookmarks-sort">
				<label for="starter-bookmark-sort"><?php esc_html_e( 'Sort by:', 'starter' ); ?></label>
				<select id="starter-bookmark-sort" class="starter-bookmark-sort-select" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
					<option value="date_added" <?php selected( $order_by, 'date_added' ); ?>><?php esc_html_e( 'Date Added', 'starter' ); ?></option>
					<option value="alphabetical" <?php selected( $order_by, 'alphabetical' ); ?>><?php esc_html_e( 'Alphabetical', 'starter' ); ?></option>
					<option value="last_updated" <?php selected( $order_by, 'last_updated' ); ?>><?php esc_html_e( 'Last Updated', 'starter' ); ?></option>
				</select>
			</div>

			<?php if ( empty( $bookmarks ) ) : ?>
				<p class="starter-no-bookmarks"><?php esc_html_e( 'You have no bookmarks yet.', 'starter' ); ?></p>
			<?php else : ?>
				<div class="starter-bookmarks-grid starter-grid-cols-<?php echo esc_attr( $columns ); ?>">
					<?php foreach ( $bookmarks as $bookmark ) :
						$manga_id   = absint( $bookmark['manga_id'] );
						$post       = get_post( $manga_id );

						if ( ! $post || 'publish' !== $post->post_status ) {
							continue;
						}

						$chapter_id = absint( $bookmark['last_chapter_id'] );
						$thumbnail  = get_the_post_thumbnail_url( $manga_id, 'medium' );
						$title      = get_the_title( $manga_id );
						$permalink  = get_permalink( $manga_id );
						?>
						<div class="starter-bookmark-card" data-manga-id="<?php echo esc_attr( $manga_id ); ?>">
							<div class="starter-bookmark-thumb">
								<?php if ( $thumbnail ) : ?>
									<a href="<?php echo esc_url( $permalink ); ?>">
										<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" />
									</a>
								<?php endif; ?>
							</div>
							<div class="starter-bookmark-info">
								<h3 class="starter-bookmark-title">
									<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
								</h3>
								<?php if ( $chapter_id ) : ?>
									<a href="<?php echo esc_url( add_query_arg( 'chapter', $chapter_id, $permalink ) ); ?>" class="starter-continue-reading-btn">
										<?php esc_html_e( 'Continue Reading', 'starter' ); ?>
									</a>
								<?php endif; ?>
								<button type="button"
									class="starter-remove-bookmark-btn"
									data-manga-id="<?php echo esc_attr( $manga_id ); ?>"
									data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
									<?php esc_html_e( 'Remove', 'starter' ); ?>
								</button>
							</div>
						</div>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}
}
