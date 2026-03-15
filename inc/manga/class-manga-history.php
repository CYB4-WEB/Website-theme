<?php
/**
 * Manga Reading History.
 *
 * Tracks reading progress for logged-in users (user meta) and guests (cookies).
 * Provides shortcode display, continue-reading, and AJAX progress updates.
 *
 * @package starter-theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_History
 *
 * Manages reading history and progress tracking.
 *
 * @since 1.0.0
 */
class Starter_Manga_History {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_History|null
	 */
	private static $instance = null;

	/**
	 * User meta key for reading history.
	 *
	 * @var string
	 */
	const META_KEY = '_starter_reading_history';

	/**
	 * Cookie name for guest history.
	 *
	 * @var string
	 */
	const COOKIE_NAME = 'starter_reading_history';

	/**
	 * Nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'starter_history_nonce';

	/**
	 * Maximum entries per user.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 100;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_History
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
		add_shortcode( 'starter_reading_history', array( $this, 'render_history_shortcode' ) );
	}

	/**
	 * Register AJAX handlers.
	 *
	 * @return void
	 */
	public function register_ajax_handlers() {
		add_action( 'wp_ajax_starter_update_reading_progress', array( $this, 'ajax_update_progress' ) );
		add_action( 'wp_ajax_nopriv_starter_update_reading_progress', array( $this, 'ajax_update_progress' ) );
		add_action( 'wp_ajax_starter_clear_history', array( $this, 'ajax_clear_history' ) );
		add_action( 'wp_ajax_nopriv_starter_clear_history', array( $this, 'ajax_clear_history' ) );
	}

	/**
	 * AJAX handler: Update reading progress.
	 *
	 * Called on page turn / scroll events.
	 *
	 * @return void
	 */
	public function ajax_update_progress() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$manga_id     = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;
		$chapter_id   = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;
		$page_number  = isset( $_POST['page_number'] ) ? absint( $_POST['page_number'] ) : 0;
		$chapter_type = isset( $_POST['chapter_type'] ) ? sanitize_key( $_POST['chapter_type'] ) : 'manga';

		if ( ! $manga_id || ! $chapter_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'starter' ) ) );
		}

		if ( 'wp-manga' !== get_post_type( $manga_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid manga.', 'starter' ) ) );
		}

		$entry = array(
			'manga_id'     => $manga_id,
			'chapter_id'   => $chapter_id,
			'page_number'  => $page_number,
			'timestamp'    => current_time( 'timestamp', true ),
			'chapter_type' => $chapter_type,
		);

		if ( is_user_logged_in() ) {
			$this->update_user_history( get_current_user_id(), $entry );
		} else {
			$this->update_guest_history( $entry );
		}

		// Update bookmark's last chapter if bookmarked.
		if ( class_exists( 'Starter_Manga_Bookmark' ) ) {
			$bookmarks = Starter_Manga_Bookmark::get_instance();
			if ( $bookmarks->is_bookmarked( $manga_id ) ) {
				$bookmarks->update_last_chapter( $manga_id, $chapter_id );
			}
		}

		wp_send_json_success( array( 'message' => __( 'Progress updated.', 'starter' ) ) );
	}

	/**
	 * AJAX handler: Clear reading history.
	 *
	 * @return void
	 */
	public function ajax_clear_history() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( is_user_logged_in() ) {
			delete_user_meta( get_current_user_id(), self::META_KEY );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.cookies_setcookie
			setcookie( self::COOKIE_NAME, '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
		}

		wp_send_json_success( array( 'message' => __( 'History cleared.', 'starter' ) ) );
	}

	/**
	 * Update reading history for a logged-in user.
	 *
	 * If an entry for the same manga+chapter exists, it is updated.
	 * Otherwise a new entry is prepended.
	 *
	 * @param int   $user_id User ID.
	 * @param array $entry   History entry data.
	 * @return void
	 */
	private function update_user_history( $user_id, $entry ) {
		$history = $this->get_user_history( $user_id );
		$history = $this->merge_entry( $history, $entry );
		$history = $this->prune_entries( $history );

		update_user_meta( $user_id, self::META_KEY, $history );
	}

	/**
	 * Update reading history for a guest (cookie).
	 *
	 * @param array $entry History entry data.
	 * @return void
	 */
	private function update_guest_history( $entry ) {
		$history = $this->get_guest_history();
		$history = $this->merge_entry( $history, $entry );
		$history = $this->prune_entries( $history );

		$value = base64_encode( wp_json_encode( $history ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.cookies_setcookie
		setcookie( self::COOKIE_NAME, $value, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), false );
	}

	/**
	 * Merge an entry into the history array.
	 *
	 * Updates existing entry for the same manga+chapter or prepends new.
	 *
	 * @param array $history Existing history.
	 * @param array $entry   New entry.
	 * @return array Updated history.
	 */
	private function merge_entry( $history, $entry ) {
		$found = false;

		foreach ( $history as $idx => &$existing ) {
			if (
				(int) $existing['manga_id'] === (int) $entry['manga_id'] &&
				(int) $existing['chapter_id'] === (int) $entry['chapter_id']
			) {
				$existing['page_number'] = $entry['page_number'];
				$existing['timestamp']   = $entry['timestamp'];
				$found = true;

				// Move to front.
				$updated = $existing;
				unset( $history[ $idx ] );
				array_unshift( $history, $updated );
				break;
			}
		}
		unset( $existing );

		if ( ! $found ) {
			array_unshift( $history, $entry );
		}

		return array_values( $history );
	}

	/**
	 * Prune history entries to the maximum limit.
	 *
	 * @param array $history History entries.
	 * @return array Pruned entries.
	 */
	private function prune_entries( $history ) {
		if ( count( $history ) > self::MAX_ENTRIES ) {
			$history = array_slice( $history, 0, self::MAX_ENTRIES );
		}
		return $history;
	}

	/**
	 * Get reading history for a logged-in user.
	 *
	 * @param int $user_id User ID.
	 * @return array
	 */
	public function get_user_history( $user_id ) {
		$history = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Get guest reading history from cookie.
	 *
	 * @return array
	 */
	public function get_guest_history() {
		if ( ! isset( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return array();
		}

		$raw  = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$data = json_decode( base64_decode( $raw ), true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$clean = array();
		foreach ( $data as $entry ) {
			$clean[] = array(
				'manga_id'     => isset( $entry['manga_id'] ) ? absint( $entry['manga_id'] ) : 0,
				'chapter_id'   => isset( $entry['chapter_id'] ) ? absint( $entry['chapter_id'] ) : 0,
				'page_number'  => isset( $entry['page_number'] ) ? absint( $entry['page_number'] ) : 0,
				'timestamp'    => isset( $entry['timestamp'] ) ? absint( $entry['timestamp'] ) : 0,
				'chapter_type' => isset( $entry['chapter_type'] ) ? sanitize_key( $entry['chapter_type'] ) : 'manga',
			);
		}

		return array_filter( $clean, function ( $e ) {
			return $e['manga_id'] > 0;
		} );
	}

	/**
	 * Get current user/guest history.
	 *
	 * @return array
	 */
	public function get_current_history() {
		if ( is_user_logged_in() ) {
			return $this->get_user_history( get_current_user_id() );
		}
		return $this->get_guest_history();
	}

	/**
	 * Get the last-read entry for a specific manga.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return array|null Entry data or null.
	 */
	public function get_last_read( $manga_id ) {
		$history = $this->get_current_history();

		foreach ( $history as $entry ) {
			if ( (int) $entry['manga_id'] === (int) $manga_id ) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * Get the continue-reading URL for a manga.
	 *
	 * @param int $manga_id Manga post ID.
	 * @return string|false URL or false if no history.
	 */
	public function get_continue_url( $manga_id ) {
		$last = $this->get_last_read( $manga_id );

		if ( ! $last ) {
			return false;
		}

		$permalink = get_permalink( $manga_id );
		$url = add_query_arg( array(
			'chapter' => absint( $last['chapter_id'] ),
			'page'    => absint( $last['page_number'] ),
		), $permalink );

		return $url;
	}

	/**
	 * Check if a manga was recently read (for "Last Read" badge).
	 *
	 * @param int $manga_id Manga post ID.
	 * @return bool
	 */
	public function was_recently_read( $manga_id ) {
		$last = $this->get_last_read( $manga_id );
		return null !== $last;
	}

	/**
	 * Calculate reading progress as a percentage.
	 *
	 * @param array $entry        History entry.
	 * @param int   $total_pages  Total pages in the chapter.
	 * @return int Progress percentage (0-100).
	 */
	public function calculate_progress( $entry, $total_pages ) {
		if ( $total_pages <= 0 ) {
			return 0;
		}
		$progress = (int) round( ( $entry['page_number'] / $total_pages ) * 100 );
		return min( 100, max( 0, $progress ) );
	}

	/**
	 * Render the [starter_reading_history] shortcode.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function render_history_shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'count' => 20,
		), $atts, 'starter_reading_history' );

		$count   = absint( $atts['count'] );
		$history = $this->get_current_history();

		if ( $count > 0 ) {
			$history = array_slice( $history, 0, $count );
		}

		ob_start();
		?>
		<div class="starter-history-wrap">
			<div class="starter-history-header">
				<h2><?php esc_html_e( 'Reading History', 'starter' ); ?></h2>
				<?php if ( ! empty( $history ) ) : ?>
					<button type="button"
						class="starter-clear-history-btn"
						data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
						<?php esc_html_e( 'Clear History', 'starter' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<?php if ( empty( $history ) ) : ?>
				<p class="starter-no-history"><?php esc_html_e( 'No reading history yet.', 'starter' ); ?></p>
			<?php else : ?>
				<div class="starter-history-list">
					<?php foreach ( $history as $entry ) :
						$manga_id   = absint( $entry['manga_id'] );
						$chapter_id = absint( $entry['chapter_id'] );
						$post       = get_post( $manga_id );

						if ( ! $post || 'publish' !== $post->post_status ) {
							continue;
						}

						$title      = get_the_title( $manga_id );
						$thumbnail  = get_the_post_thumbnail_url( $manga_id, 'thumbnail' );
						$permalink  = get_permalink( $manga_id );
						$timestamp  = absint( $entry['timestamp'] );
						$page_num   = absint( $entry['page_number'] );

						// Build continue URL.
						$continue_url = add_query_arg( array(
							'chapter' => $chapter_id,
							'page'    => $page_num,
						), $permalink );

						// Get total pages for progress bar (via chapter manager if available).
						$total_pages = 0;
						if ( class_exists( 'Starter_Manga_Chapter' ) ) {
							$chapter_manager = Starter_Manga_Chapter::get_instance();
							if ( method_exists( $chapter_manager, 'get_chapter' ) ) {
								$chapter_data = $chapter_manager->get_chapter( $chapter_id );
								if ( $chapter_data && isset( $chapter_data->total_pages ) ) {
									$total_pages = absint( $chapter_data->total_pages );
								}
							}
						}

						$progress = $total_pages > 0 ? $this->calculate_progress( $entry, $total_pages ) : 0;
						?>
						<div class="starter-history-item" data-manga-id="<?php echo esc_attr( $manga_id ); ?>">
							<div class="starter-history-thumb">
								<?php if ( $thumbnail ) : ?>
									<a href="<?php echo esc_url( $permalink ); ?>">
										<img src="<?php echo esc_url( $thumbnail ); ?>" alt="<?php echo esc_attr( $title ); ?>" loading="lazy" />
									</a>
								<?php endif; ?>
								<span class="starter-last-read-badge"><?php esc_html_e( 'Last Read', 'starter' ); ?></span>
							</div>
							<div class="starter-history-info">
								<h4 class="starter-history-title">
									<a href="<?php echo esc_url( $permalink ); ?>"><?php echo esc_html( $title ); ?></a>
								</h4>
								<span class="starter-history-chapter">
									<?php
									printf(
										/* translators: %1$d: chapter ID, %2$d: page number */
										esc_html__( 'Chapter %1$d - Page %2$d', 'starter' ),
										$chapter_id,
										$page_num
									);
									?>
								</span>
								<span class="starter-history-time">
									<?php
									/* translators: %s: human-readable time difference */
									printf( esc_html__( '%s ago', 'starter' ), human_time_diff( $timestamp, current_time( 'timestamp', true ) ) );
									?>
								</span>
								<?php if ( $total_pages > 0 ) : ?>
									<div class="starter-progress-bar">
										<div class="starter-progress-fill" style="width: <?php echo esc_attr( $progress ); ?>%;">
											<span class="starter-progress-text"><?php echo esc_html( $progress ); ?>%</span>
										</div>
									</div>
								<?php endif; ?>
								<a href="<?php echo esc_url( $continue_url ); ?>" class="starter-continue-btn">
									<?php esc_html_e( 'Continue Reading', 'starter' ); ?>
								</a>
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
