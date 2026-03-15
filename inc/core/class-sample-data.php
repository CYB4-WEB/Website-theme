<?php
/**
 * Sample Data Generator.
 *
 * Creates demo manga posts, chapters, and taxonomy terms for a fresh
 * installation so the theme never shows blank pages.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Sample_Data
 */
class Starter_Sample_Data {

	/**
	 * Option key that tracks whether sample data was inserted.
	 *
	 * @var string
	 */
	const INSTALLED_KEY = 'starter_sample_data_installed';

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Sample_Data|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Sample_Data
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — hook on admin init and AJAX.
	 */
	private function __construct() {
		add_action( 'wp_ajax_starter_insert_sample_data',  array( $this, 'ajax_insert' ) );
		add_action( 'wp_ajax_starter_remove_sample_data',  array( $this, 'ajax_remove' ) );
		add_action( 'admin_notices',                        array( $this, 'admin_notice' ) );
	}

	/* ──────────────────────────────────────────────────────────────
	 * Public API
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Check whether sample data is currently installed.
	 *
	 * @return bool
	 */
	public static function is_installed() {
		return (bool) get_option( self::INSTALLED_KEY, false );
	}

	/**
	 * Insert sample data (idempotent — skips if already installed).
	 *
	 * @return array { inserted: int, skipped: int, errors: string[] }
	 */
	public function insert() {
		if ( self::is_installed() ) {
			return array( 'inserted' => 0, 'skipped' => 1, 'errors' => array() );
		}

		$inserted = 0;
		$errors   = array();

		/* ── Taxonomy terms ─────────────────────────────────────── */
		$genre_names = array(
			'Action', 'Adventure', 'Comedy', 'Drama', 'Fantasy',
			'Horror', 'Mystery', 'Romance', 'Sci-Fi', 'Slice of Life',
			'Sports', 'Supernatural', 'Thriller',
		);
		$genre_ids = array();
		foreach ( $genre_names as $name ) {
			$term = term_exists( $name, 'genre' );
			if ( ! $term ) {
				$term = wp_insert_term( $name, 'genre' );
			}
			if ( ! is_wp_error( $term ) ) {
				$genre_ids[ $name ] = is_array( $term ) ? $term['term_id'] : $term;
			}
		}

		/* ── Sample manga list ──────────────────────────────────── */
		$samples = array(
			array(
				'title'        => 'The Rising Swordsman',
				'description'  => 'A legendary swordsman returns from another world with memories of a past life. Armed with skills no mortal should possess, he must navigate politics, betrayal, and ancient prophecy.',
				'author'       => 'Kenji Tanaka',
				'artist'       => 'Hana Mori',
				'genres'       => array( 'Action', 'Fantasy', 'Adventure' ),
				'status'       => 'Ongoing',
				'type'         => 'manga',
				'year'         => '2022',
				'featured'     => '1',
				'chapters'     => 12,
			),
			array(
				'title'        => 'Stellar Academy',
				'description'  => 'An academy floats among the stars, training the galaxy\'s most gifted students. One ordinary girl discovers she carries the power of a dying sun inside her.',
				'author'       => 'Rin Asami',
				'artist'       => 'Sota Kudo',
				'genres'       => array( 'Sci-Fi', 'Romance', 'Drama' ),
				'status'       => 'Ongoing',
				'type'         => 'manga',
				'year'         => '2023',
				'chapters'     => 8,
			),
			array(
				'title'        => 'Shadow Protocol',
				'description'  => 'An elite spy organization operating in the shadows of a near-future megacity. Agent 7 uncovers a conspiracy that goes all the way to the top.',
				'author'       => 'Yuki Nishida',
				'artist'       => 'Dai Fujimoto',
				'genres'       => array( 'Thriller', 'Action', 'Mystery' ),
				'status'       => 'Completed',
				'type'         => 'manga',
				'year'         => '2021',
				'chapters'     => 25,
			),
			array(
				'title'        => 'Echoes of Spring',
				'description'  => 'A heartwarming slice-of-life story about four friends navigating high school, dreams, and first loves in a small coastal town.',
				'author'       => 'Mia Watanabe',
				'artist'       => 'Mia Watanabe',
				'genres'       => array( 'Slice of Life', 'Romance', 'Comedy' ),
				'status'       => 'Ongoing',
				'type'         => 'manga',
				'year'         => '2023',
				'chapters'     => 6,
			),
			array(
				'title'        => 'Daemon\'s Hymn',
				'description'  => 'In a world where daemons and humans once lived in harmony, a lone exorcist must protect both races from a threat older than civilization itself.',
				'author'       => 'Tarou Kimura',
				'artist'       => 'Saki Inoue',
				'genres'       => array( 'Supernatural', 'Horror', 'Fantasy' ),
				'status'       => 'Hiatus',
				'type'         => 'manga',
				'year'         => '2020',
				'chapters'     => 30,
			),
			array(
				'title'        => 'The Iron Throne Chronicles',
				'description'  => 'A sweeping fantasy epic spanning three kingdoms, told through the eyes of a blacksmith\'s apprentice who forges weapons for both sides of a war.',
				'author'       => 'Haruto Abe',
				'artist'       => 'Nao Shimizu',
				'genres'       => array( 'Fantasy', 'Drama', 'Adventure' ),
				'status'       => 'Ongoing',
				'type'         => 'novel',
				'year'         => '2022',
				'chapters'     => 40,
			),
		);

		foreach ( $samples as $data ) {
			$post_id = wp_insert_post( array(
				'post_title'   => $data['title'],
				'post_content' => $data['description'],
				'post_excerpt' => wp_trim_words( $data['description'], 20, '…' ),
				'post_type'    => 'wp-manga',
				'post_status'  => 'publish',
				'post_author'  => get_current_user_id() ?: 1,
			), true );

			if ( is_wp_error( $post_id ) ) {
				$errors[] = $post_id->get_error_message();
				continue;
			}

			/* Meta fields */
			update_post_meta( $post_id, '_author',       $data['author'] );
			update_post_meta( $post_id, '_artist',       $data['artist'] );
			update_post_meta( $post_id, '_status',       $data['status'] );
			update_post_meta( $post_id, '_release_year', $data['year'] );
			update_post_meta( $post_id, '_content_type', $data['type'] );
			update_post_meta( $post_id, '_views',        wp_rand( 500, 50000 ) );
			if ( ! empty( $data['featured'] ) ) {
				update_post_meta( $post_id, '_featured', '1' );
			}

			/* Assign genres */
			$term_ids = array();
			foreach ( $data['genres'] as $g ) {
				if ( isset( $genre_ids[ $g ] ) ) {
					$term_ids[] = (int) $genre_ids[ $g ];
				}
			}
			if ( $term_ids ) {
				wp_set_object_terms( $post_id, $term_ids, 'genre' );
			}

			/* Insert sample chapters into custom table */
			$this->insert_sample_chapters( $post_id, $data['chapters'] );

			$inserted++;
		}

		if ( $inserted > 0 ) {
			update_option( self::INSTALLED_KEY, true );
		}

		return compact( 'inserted', 'errors' ) + array( 'skipped' => 0 );
	}

	/**
	 * Remove all sample data posts.
	 *
	 * @return int Number of posts deleted.
	 */
	public function remove() {
		$posts = get_posts( array(
			'post_type'   => 'wp-manga',
			'numberposts' => -1,
			'meta_query'  => array(
				array(
					'key'   => '_is_sample_data',
					'value' => '1',
				),
			),
		) );

		$deleted = 0;
		foreach ( $posts as $post ) {
			wp_delete_post( $post->ID, true );
			$deleted++;
		}

		delete_option( self::INSTALLED_KEY );
		return $deleted;
	}

	/* ──────────────────────────────────────────────────────────────
	 * Private helpers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * Insert placeholder chapters for a manga.
	 *
	 * @param int $manga_id    Post ID of the parent manga.
	 * @param int $num_chapters Number of chapters to create.
	 */
	private function insert_sample_chapters( $manga_id, $num_chapters ) {
		global $wpdb;

		$table = $wpdb->prefix . 'starter_chapters';

		/* Skip if table doesn't exist yet */
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return;
		}

		for ( $i = 1; $i <= $num_chapters; $i++ ) {
			$chapter_title = 'Chapter ' . $i;
			$wpdb->insert(
				$table,
				array(
					'manga_id'       => $manga_id,
					'chapter_number' => $i,
					'chapter_title'  => $chapter_title,
					'chapter_slug'   => sanitize_title( $chapter_title ),
					'chapter_type'   => 'image',
					'chapter_status' => 'publish',
					'is_premium'     => 0,
					'coin_price'     => 0,
					'publish_date'   => date( 'Y-m-d H:i:s', strtotime( '-' . ( $num_chapters - $i ) . ' days' ) ),
				),
				array( '%d', '%f', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
			);
		}
	}

	/* ──────────────────────────────────────────────────────────────
	 * AJAX handlers
	 * ─────────────────────────────────────────────────────────── */

	/**
	 * AJAX: Insert sample data.
	 */
	public function ajax_insert() {
		check_ajax_referer( 'starter_sample_data_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'starter-theme' ) ) );
		}

		$result = $this->insert();
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: Remove sample data.
	 */
	public function ajax_remove() {
		check_ajax_referer( 'starter_sample_data_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'starter-theme' ) ) );
		}

		$deleted = $this->remove();
		wp_send_json_success( array( 'deleted' => $deleted ) );
	}

	/**
	 * Admin notice with quick-install button.
	 */
	public function admin_notice() {
		if ( self::is_installed() ) {
			return;
		}

		/* Only show on the dashboard or theme pages. */
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'dashboard', 'themes', 'appearance_page_starter-theme-options' ), true ) ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<strong><?php esc_html_e( 'Project Alpha', 'starter-theme' ); ?></strong> —
				<?php esc_html_e( 'Install sample manga data to preview the theme.', 'starter-theme' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
				   id="starter-install-sample"
				   data-nonce="<?php echo esc_attr( wp_create_nonce( 'starter_sample_data_nonce' ) ); ?>"
				   style="margin-left:10px;" class="button button-primary">
					<?php esc_html_e( 'Install Sample Data', 'starter-theme' ); ?>
				</a>
			</p>
		</div>
		<script>
		(function($){
			$('#starter-install-sample').on('click', function(e){
				e.preventDefault();
				var btn = $(this);
				btn.text('Installing…').prop('disabled', true);
				$.post(ajaxurl, {
					action: 'starter_insert_sample_data',
					nonce:  btn.data('nonce')
				}, function(res){
					if(res.success){
						btn.text('Done! Refresh to see changes.').css('background','#46b450');
						setTimeout(function(){ location.reload(); }, 1500);
					} else {
						btn.text('Error — check console').css('background','#dc3232').prop('disabled', false);
						console.error(res.data);
					}
				});
			});
		})(jQuery);
		</script>
		<?php
	}
}

/* Auto-instantiate. */
Starter_Sample_Data::get_instance();
