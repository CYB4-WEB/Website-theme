<?php
/**
 * Manga Reader Display Logic.
 *
 * Handles the reading page, layouts, AJAX loading, navigation,
 * reading direction, theming, adult content gates, and URL rewriting.
 *
 * @package starter Theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Manga_Reader
 *
 * Manages the manga/novel/video reader front-end experience.
 *
 * @since 1.0.0
 */
class Starter_Manga_Reader {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Manga_Reader|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_Manga_Reader
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
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_chapter_template' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_reader_assets' ) );

		// AJAX endpoints.
		add_action( 'wp_ajax_starter_load_chapter_data', array( $this, 'ajax_load_chapter_data' ) );
		add_action( 'wp_ajax_nopriv_starter_load_chapter_data', array( $this, 'ajax_load_chapter_data' ) );
		add_action( 'wp_ajax_starter_preload_chapter', array( $this, 'ajax_preload_chapter' ) );
		add_action( 'wp_ajax_nopriv_starter_preload_chapter', array( $this, 'ajax_preload_chapter' ) );
		add_action( 'wp_ajax_starter_confirm_adult', array( $this, 'ajax_confirm_adult' ) );
		add_action( 'wp_ajax_nopriv_starter_confirm_adult', array( $this, 'ajax_confirm_adult' ) );
	}

	/**
	 * Add URL rewrite rules for chapter reading.
	 *
	 * Pattern: /manga/{slug}/chapter-{num}/
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^manga/([^/]+)/chapter-([^/]+)/?$',
			'index.php?post_type=wp-manga&name=$matches[1]&starter_chapter=$matches[2]',
			'top'
		);

		// Single page within a chapter: /manga/{slug}/chapter-{num}/page-{page}/
		add_rewrite_rule(
			'^manga/([^/]+)/chapter-([^/]+)/page-([0-9]+)/?$',
			'index.php?post_type=wp-manga&name=$matches[1]&starter_chapter=$matches[2]&starter_page=$matches[3]',
			'top'
		);
	}

	/**
	 * Register custom query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'starter_chapter';
		$vars[] = 'starter_page';
		return $vars;
	}

	/**
	 * Handle the chapter reading template.
	 *
	 * @return void
	 */
	public function handle_chapter_template() {
		$chapter_num = get_query_var( 'starter_chapter' );

		if ( empty( $chapter_num ) ) {
			return;
		}

		// We are in chapter reading mode.
		global $post;

		if ( ! $post || 'wp-manga' !== $post->post_type ) {
			return;
		}

		$manga_id = $post->ID;
		$page_num = get_query_var( 'starter_page', 1 );

		// Find the chapter in the database.
		$chapter_manager = Starter_Manga_Chapter::get_instance();
		$chapter         = $this->find_chapter_by_number( $manga_id, $chapter_num );

		if ( ! $chapter || 'publish' !== $chapter->chapter_status ) {
			// Check if user has permission to view non-published chapters.
			if ( ! $chapter || ! current_user_can( 'edit_posts' ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				return;
			}
		}

		// Check chapter permission (role-based access).
		if ( ! $this->check_chapter_permission( $chapter ) ) {
			wp_die(
				esc_html__( 'You do not have permission to view this chapter.', 'starter' ),
				esc_html__( 'Access Denied', 'starter' ),
				array( 'response' => 403 )
			);
		}

		// Store chapter data for template use.
		set_query_var( 'starter_current_chapter', $chapter );
		set_query_var( 'starter_current_page', absint( $page_num ) );
		set_query_var( 'starter_manga_id', $manga_id );

		// Try to load the reader template.
		$template = locate_template( 'templates/manga/reader.php' );
		if ( $template ) {
			include $template;
			exit;
		}

		// Fallback: render inline.
		$this->render_reader( $manga_id, $chapter, absint( $page_num ) );
		exit;
	}

	/**
	 * Find a chapter by its chapter number for a given manga.
	 *
	 * @param int    $manga_id    Manga post ID.
	 * @param string $chapter_num Chapter number.
	 * @return object|null Chapter object or null.
	 */
	private function find_chapter_by_number( $manga_id, $chapter_num ) {
		global $wpdb;

		$chapter_table = Starter_Manga_Chapter::get_instance()->get_table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$chapter_table} WHERE manga_id = %d AND chapter_number = %s LIMIT 1",
				absint( $manga_id ),
				sanitize_text_field( $chapter_num )
			)
		);
	}

	/**
	 * Check if the current user has permission to view a chapter.
	 *
	 * @param object $chapter Chapter object.
	 * @return bool True if permitted.
	 */
	private function check_chapter_permission( $chapter ) {
		if ( empty( $chapter->chapter_permission ) ) {
			return true;
		}

		$allowed_roles = maybe_unserialize( $chapter->chapter_permission );

		if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
			return true;
		}

		if ( ! is_user_logged_in() ) {
			return false;
		}

		$user  = wp_get_current_user();
		$roles = $user->roles;

		return ! empty( array_intersect( $roles, $allowed_roles ) );
	}

	/**
	 * Enqueue reader assets on chapter pages.
	 *
	 * @return void
	 */
	public function enqueue_reader_assets() {
		$chapter_num = get_query_var( 'starter_chapter' );

		if ( empty( $chapter_num ) ) {
			return;
		}

		wp_enqueue_style(
			'starter-manga-reader',
			get_template_directory_uri() . '/assets/css/manga-reader.css',
			array(),
			wp_get_theme()->get( 'Version' )
		);

		wp_enqueue_script(
			'starter-manga-reader',
			get_template_directory_uri() . '/assets/js/manga-reader.js',
			array( 'jquery' ),
			wp_get_theme()->get( 'Version' ),
			true
		);

		global $post;
		$manga_id    = $post ? $post->ID : 0;
		$chapter     = $this->find_chapter_by_number( $manga_id, $chapter_num );
		$chapter_mgr = Starter_Manga_Chapter::get_instance();

		$reader_settings = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'starter_manga_nonce' ),
			'mangaId'        => $manga_id,
			'chapterId'      => $chapter ? (int) $chapter->id : 0,
			'currentPage'    => max( 1, absint( get_query_var( 'starter_page', 1 ) ) ),
			'layout'         => get_option( 'starter_reader_default_layout', 'single' ),
			'direction'      => get_option( 'starter_reader_default_direction', 'ltr' ),
			'darkMode'       => false,
			'familySafeMode' => false,
			'prevChapter'    => null,
			'nextChapter'    => null,
			'i18n'           => array(
				'loading'        => esc_html__( 'Loading...', 'starter' ),
				'error'          => esc_html__( 'Failed to load chapter data.', 'starter' ),
				'noPages'        => esc_html__( 'No pages available.', 'starter' ),
				'adultWarning'   => esc_html__( 'This content is for adults only (18+). Are you sure you want to continue?', 'starter' ),
				'confirm'        => esc_html__( 'Yes, I am 18+', 'starter' ),
				'cancel'         => esc_html__( 'No, take me back', 'starter' ),
				'prevChapter'    => esc_html__( 'Previous Chapter', 'starter' ),
				'nextChapter'    => esc_html__( 'Next Chapter', 'starter' ),
				'page'           => esc_html__( 'Page', 'starter' ),
				'of'             => esc_html__( 'of', 'starter' ),
			),
		);

		if ( $chapter ) {
			$prev = $chapter_mgr->get_prev_chapter( $chapter->id, $manga_id );
			$next = $chapter_mgr->get_next_chapter( $chapter->id, $manga_id );

			if ( $prev ) {
				$reader_settings['prevChapter'] = array(
					'id'     => (int) $prev->id,
					'number' => $prev->chapter_number,
					'url'    => $chapter_mgr->get_chapter_url( $manga_id, $prev ),
				);
			}

			if ( $next ) {
				$reader_settings['nextChapter'] = array(
					'id'     => (int) $next->id,
					'number' => $next->chapter_number,
					'url'    => $chapter_mgr->get_chapter_url( $manga_id, $next ),
				);
			}
		}

		wp_localize_script( 'starter-manga-reader', 'starterReader', $reader_settings );
	}

	/**
	 * Render the reader output (fallback when no template exists).
	 *
	 * @param int    $manga_id Manga post ID.
	 * @param object $chapter  Chapter object.
	 * @param int    $page_num Current page number.
	 * @return void
	 */
	private function render_reader( $manga_id, $chapter, $page_num ) {
		$is_adult  = '1' === get_post_meta( $manga_id, '_starter_manga_adult_content', true );
		$confirmed = isset( $_COOKIE['starter_adult_confirmed'] ) && '1' === $_COOKIE['starter_adult_confirmed'];

		get_header();
		?>
		<div id="starter-manga-reader"
			class="starter-reader"
			data-manga-id="<?php echo esc_attr( $manga_id ); ?>"
			data-chapter-id="<?php echo esc_attr( $chapter->id ); ?>"
			data-chapter-type="<?php echo esc_attr( $chapter->chapter_type ); ?>"
			data-page="<?php echo esc_attr( $page_num ); ?>">

			<?php if ( $is_adult && ! $confirmed ) : ?>
				<div id="starter-adult-gate" class="starter-adult-gate">
					<div class="starter-adult-gate__inner">
						<h2><?php esc_html_e( 'Adult Content Warning', 'starter' ); ?></h2>
						<p><?php esc_html_e( 'This content is intended for adults only (18+). By continuing, you confirm that you are at least 18 years old.', 'starter' ); ?></p>
						<div class="starter-adult-gate__actions">
							<button type="button" class="starter-btn starter-btn--confirm" id="starter-adult-confirm">
								<?php esc_html_e( 'Yes, I am 18+', 'starter' ); ?>
							</button>
							<a href="<?php echo esc_url( get_permalink( $manga_id ) ); ?>" class="starter-btn starter-btn--cancel">
								<?php esc_html_e( 'No, take me back', 'starter' ); ?>
							</a>
						</div>
					</div>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $chapter->chapter_warning ) ) : ?>
				<div class="starter-reader__warning">
					<?php echo wp_kses_post( $chapter->chapter_warning ); ?>
				</div>
			<?php endif; ?>

			<!-- Reader toolbar -->
			<div class="starter-reader__toolbar">
				<div class="starter-reader__toolbar-left">
					<a href="<?php echo esc_url( get_permalink( $manga_id ) ); ?>" class="starter-reader__back" title="<?php esc_attr_e( 'Back to manga', 'starter' ); ?>">
						&larr; <?php echo esc_html( get_the_title( $manga_id ) ); ?>
					</a>
				</div>

				<div class="starter-reader__toolbar-center">
					<?php $this->render_chapter_navigation( $manga_id, $chapter ); ?>
				</div>

				<div class="starter-reader__toolbar-right">
					<button type="button" class="starter-reader__layout-toggle" data-layout="single" title="<?php esc_attr_e( 'Single Page', 'starter' ); ?>">
						<?php esc_html_e( 'Single', 'starter' ); ?>
					</button>
					<button type="button" class="starter-reader__layout-toggle" data-layout="all" title="<?php esc_attr_e( 'All Pages', 'starter' ); ?>">
						<?php esc_html_e( 'All', 'starter' ); ?>
					</button>
					<button type="button" class="starter-reader__direction-toggle" data-direction="ltr" title="<?php esc_attr_e( 'Reading Direction', 'starter' ); ?>">
						<?php esc_html_e( 'LTR', 'starter' ); ?>
					</button>
					<button type="button" id="starter-reader-theme-toggle" class="starter-reader__theme-toggle" title="<?php esc_attr_e( 'Toggle Dark/Light Mode', 'starter' ); ?>">
						<?php esc_html_e( 'Dark', 'starter' ); ?>
					</button>
					<?php if ( $is_adult ) : ?>
						<button type="button" id="starter-family-safe-toggle" class="starter-reader__family-safe" title="<?php esc_attr_e( 'Family Safe Mode', 'starter' ); ?>">
							<?php esc_html_e( 'Safe Mode', 'starter' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</div>

			<!-- Reader content area -->
			<div id="starter-reader-content" class="starter-reader__content">
				<div class="starter-reader__loading">
					<span><?php esc_html_e( 'Loading chapter...', 'starter' ); ?></span>
				</div>
			</div>

			<!-- Bottom navigation -->
			<div class="starter-reader__bottom-nav">
				<?php $this->render_chapter_navigation( $manga_id, $chapter ); ?>
			</div>
		</div>
		<?php
		get_footer();
	}

	/**
	 * Render chapter navigation controls (prev/next + select).
	 *
	 * @param int    $manga_id Manga post ID.
	 * @param object $chapter  Current chapter object.
	 * @return void
	 */
	private function render_chapter_navigation( $manga_id, $chapter ) {
		$chapter_mgr = Starter_Manga_Chapter::get_instance();
		$prev        = $chapter_mgr->get_prev_chapter( $chapter->id, $manga_id );
		$next        = $chapter_mgr->get_next_chapter( $chapter->id, $manga_id );
		$all         = $chapter_mgr->get_chapters_by_manga( $manga_id, array( 'order' => 'ASC' ) );
		?>
		<div class="starter-reader__nav">
			<?php if ( $prev ) : ?>
				<a href="<?php echo esc_url( $chapter_mgr->get_chapter_url( $manga_id, $prev ) ); ?>" class="starter-reader__nav-prev">
					&larr; <?php esc_html_e( 'Prev', 'starter' ); ?>
				</a>
			<?php else : ?>
				<span class="starter-reader__nav-prev starter-reader__nav--disabled">&larr; <?php esc_html_e( 'Prev', 'starter' ); ?></span>
			<?php endif; ?>

			<select class="starter-reader__chapter-select" data-manga-id="<?php echo esc_attr( $manga_id ); ?>">
				<?php foreach ( $all as $ch ) : ?>
					<option value="<?php echo esc_attr( $chapter_mgr->get_chapter_url( $manga_id, $ch ) ); ?>"
						<?php selected( (int) $ch->id, (int) $chapter->id ); ?>>
						<?php
						$label = sprintf(
							/* translators: %s: chapter number */
							esc_html__( 'Chapter %s', 'starter' ),
							$ch->chapter_number
						);
						if ( ! empty( $ch->chapter_name ) ) {
							$label .= ' - ' . $ch->chapter_name;
						}
						echo esc_html( $label );
						?>
					</option>
				<?php endforeach; ?>
			</select>

			<?php if ( $next ) : ?>
				<a href="<?php echo esc_url( $chapter_mgr->get_chapter_url( $manga_id, $next ) ); ?>" class="starter-reader__nav-next">
					<?php esc_html_e( 'Next', 'starter' ); ?> &rarr;
				</a>
			<?php else : ?>
				<span class="starter-reader__nav-next starter-reader__nav--disabled"><?php esc_html_e( 'Next', 'starter' ); ?> &rarr;</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * AJAX endpoint: Load chapter data (images/text/video).
	 *
	 * @return void
	 */
	public function ajax_load_chapter_data() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;

		if ( ! $chapter_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid chapter ID.', 'starter' ) ) );
		}

		$chapter_mgr = Starter_Manga_Chapter::get_instance();
		$chapter     = $chapter_mgr->get_chapter( $chapter_id );

		if ( ! $chapter ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Chapter not found.', 'starter' ) ) );
		}

		// Check permission.
		if ( ! $this->check_chapter_permission( $chapter ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Access denied.', 'starter' ) ) );
		}

		$data = json_decode( $chapter->chapter_data, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		$response = array(
			'id'          => (int) $chapter->id,
			'type'        => $chapter->chapter_type,
			'number'      => $chapter->chapter_number,
			'name'        => $chapter->chapter_name,
			'warning'     => $chapter->chapter_warning,
			'totalPages'  => 0,
			'content'     => array(),
		);

		switch ( $chapter->chapter_type ) {
			case 'image':
				$response['content']    = array_map( 'esc_url', $data );
				$response['totalPages'] = count( $data );
				break;

			case 'text':
				// Text chapters: data is an array of text blocks or a single string.
				if ( isset( $data['content'] ) ) {
					$response['content'] = wp_kses_post( $data['content'] );
				} else {
					$response['content'] = array_map( 'wp_kses_post', $data );
				}
				$response['totalPages'] = 1;
				break;

			case 'video':
				$response['content']    = array_map( 'esc_url', $data );
				$response['totalPages'] = count( $data );
				break;
		}

		wp_send_json_success( $response );
	}

	/**
	 * AJAX endpoint: Preload next chapter images.
	 *
	 * @return void
	 */
	public function ajax_preload_chapter() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;
		$manga_id   = isset( $_POST['manga_id'] ) ? absint( $_POST['manga_id'] ) : 0;

		if ( ! $chapter_id || ! $manga_id ) {
			wp_send_json_error( array( 'message' => esc_html__( 'Invalid parameters.', 'starter' ) ) );
		}

		$chapter_mgr = Starter_Manga_Chapter::get_instance();
		$next        = $chapter_mgr->get_next_chapter( $chapter_id, $manga_id );

		if ( ! $next ) {
			wp_send_json_success( array( 'images' => array() ) );
			return;
		}

		$data = json_decode( $next->chapter_data, true );
		if ( ! is_array( $data ) ) {
			$data = array();
		}

		// Only preload first few images.
		$preload_count = apply_filters( 'starter_reader_preload_count', 3 );
		$images        = array_slice( $data, 0, $preload_count );

		wp_send_json_success( array(
			'images'    => array_map( 'esc_url', $images ),
			'chapterId' => (int) $next->id,
		) );
	}

	/**
	 * AJAX endpoint: Confirm adult content viewing.
	 *
	 * @return void
	 */
	public function ajax_confirm_adult() {
		check_ajax_referer( 'starter_manga_nonce', 'nonce' );

		// Set a cookie for 24 hours.
		setcookie( 'starter_adult_confirmed', '1', time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );

		wp_send_json_success( array( 'confirmed' => true ) );
	}

	/**
	 * Flush rewrite rules for reader URLs. Call on theme activation.
	 *
	 * @return void
	 */
	public static function flush_rewrite_rules() {
		$instance = self::get_instance();
		$instance->add_rewrite_rules();
		flush_rewrite_rules();
	}
}
