<?php
/**
 * Novel reader for text-based chapters.
 *
 * Extends the manga reader paradigm for novel/text content with rich
 * typography, in-chapter pagination, reading progress tracking, and
 * text-to-speech integration.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Novel_Reader
 */
class Starter_Novel_Reader {

	/**
	 * Default words per page for in-chapter pagination.
	 *
	 * @var int
	 */
	const DEFAULT_WORDS_PER_PAGE = 3000;

	/**
	 * Option key for reader settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'starter_novel_reader';

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return self
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
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_starter_novel_progress', array( $this, 'ajax_save_progress' ) );
		add_action( 'wp_ajax_nopriv_starter_novel_progress', array( $this, 'ajax_save_progress' ) );
		add_filter( 'starter_chapter_content', array( $this, 'render_chapter' ), 10, 2 );
		add_action( 'starter_novel_before_content', array( $this, 'render_progress_bar' ) );
		add_action( 'starter_novel_after_content', array( $this, 'render_chapter_pagination' ) );
		add_action( 'starter_novel_after_content', array( $this, 'render_tts_hook' ) );
	}

	/**
	 * Enqueue reader assets on chapter pages.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_novel_chapter() ) {
			return;
		}

		wp_enqueue_style(
			'starter-novel-reader',
			get_template_directory_uri() . '/assets/css/novel-reader.css',
			array(),
			'1.0.0'
		);

		wp_enqueue_script(
			'starter-novel-reader',
			get_template_directory_uri() . '/assets/js/novel-reader.js',
			array(),
			'1.0.0',
			true
		);

		wp_localize_script(
			'starter-novel-reader',
			'starterNovelReader',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'starter_novel_progress' ),
				'postId'       => get_the_ID(),
				'wordsPerPage' => $this->get_words_per_page(),
				'currentPage'  => $this->get_current_text_page(),
				'totalPages'   => $this->get_total_text_pages(),
				'cleanMode'    => false,
				'i18n'         => array(
					'progress'  => esc_html__( 'Reading progress', 'starter-theme' ),
					'cleanMode' => esc_html__( 'Clean reading mode', 'starter-theme' ),
					'pageOf'    => esc_html__( 'Page %1$d of %2$d', 'starter-theme' ),
				),
			)
		);
	}

	/**
	 * Determine if the current page is a novel chapter.
	 *
	 * @return bool
	 */
	private function is_novel_chapter() {
		/**
		 * Filter whether the current context is a novel chapter.
		 *
		 * @param bool $is_chapter Whether this is a novel chapter.
		 */
		return (bool) apply_filters( 'starter_is_novel_chapter', is_singular( 'chapter' ) );
	}

	/**
	 * Get configured words per page.
	 *
	 * @return int
	 */
	public function get_words_per_page() {
		$options = get_option( self::OPTION_KEY, array() );
		$wpp     = isset( $options['words_per_page'] ) ? absint( $options['words_per_page'] ) : self::DEFAULT_WORDS_PER_PAGE;

		return max( 500, $wpp );
	}

	/**
	 * Get the current text page from the query string.
	 *
	 * @return int
	 */
	public function get_current_text_page() {
		$page = isset( $_GET['text_page'] ) ? absint( $_GET['text_page'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return max( 1, $page );
	}

	/**
	 * Get chapter HTML content from post meta.
	 *
	 * @param int|null $post_id Post ID. Defaults to current post.
	 * @return string
	 */
	public function get_chapter_html( $post_id = null ) {
		if ( null === $post_id ) {
			$post_id = get_the_ID();
		}

		$chapter_data = get_post_meta( $post_id, 'chapter_data', true );

		if ( is_array( $chapter_data ) && isset( $chapter_data['content'] ) ) {
			return $chapter_data['content'];
		}

		if ( is_string( $chapter_data ) ) {
			return $chapter_data;
		}

		return '';
	}

	/**
	 * Split chapter HTML into pages by word count.
	 *
	 * Splits at paragraph boundaries to avoid breaking mid-paragraph.
	 *
	 * @param string $html    Chapter HTML.
	 * @param int    $per_page Words per page.
	 * @return array Array of HTML strings, one per page.
	 */
	public function paginate_html( $html, $per_page = 0 ) {
		if ( ! $per_page ) {
			$per_page = $this->get_words_per_page();
		}

		$html = trim( $html );
		if ( empty( $html ) ) {
			return array( '' );
		}

		// Split into paragraphs preserving tags.
		$paragraphs = preg_split( '/(<\/p>\s*)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		$pages      = array();
		$current    = '';
		$word_count = 0;

		for ( $i = 0; $i < count( $paragraphs ); $i++ ) {
			$segment    = $paragraphs[ $i ];
			$seg_words  = str_word_count( wp_strip_all_tags( $segment ) );

			if ( $word_count + $seg_words > $per_page && $word_count > 0 ) {
				$pages[]    = $current;
				$current    = $segment;
				$word_count = $seg_words;
			} else {
				$current    .= $segment;
				$word_count += $seg_words;
			}
		}

		if ( ! empty( trim( $current ) ) ) {
			$pages[] = $current;
		}

		return ! empty( $pages ) ? $pages : array( $html );
	}

	/**
	 * Get total text pages for the current chapter.
	 *
	 * @param int|null $post_id Post ID.
	 * @return int
	 */
	public function get_total_text_pages( $post_id = null ) {
		$html  = $this->get_chapter_html( $post_id );
		$pages = $this->paginate_html( $html );

		return count( $pages );
	}

	/**
	 * Render the chapter content with pagination and typography.
	 *
	 * @param string $content Existing content.
	 * @param int    $post_id Post ID.
	 * @return string
	 */
	public function render_chapter( $content, $post_id ) {
		$html = $this->get_chapter_html( $post_id );

		if ( empty( $html ) ) {
			return $content;
		}

		$pages        = $this->paginate_html( $html );
		$current_page = min( $this->get_current_text_page(), count( $pages ) );
		$page_content = isset( $pages[ $current_page - 1 ] ) ? $pages[ $current_page - 1 ] : '';

		$allowed_html = wp_kses_allowed_html( 'post' );

		ob_start();
		?>
		<div class="starter-novel-reader" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-page="<?php echo esc_attr( $current_page ); ?>" data-total-pages="<?php echo esc_attr( count( $pages ) ); ?>">
			<div class="starter-novel-reader__toolbar">
				<button type="button" class="starter-novel-reader__clean-toggle" aria-label="<?php esc_attr_e( 'Toggle clean reading mode', 'starter-theme' ); ?>">
					<span class="dashicons dashicons-editor-expand"></span>
				</button>
			</div>

			<article class="starter-novel-reader__content" role="article">
				<?php echo wp_kses( $page_content, $allowed_html ); ?>
			</article>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render scroll-based reading progress indicator.
	 *
	 * @return void
	 */
	public function render_progress_bar() {
		if ( ! $this->is_novel_chapter() ) {
			return;
		}
		?>
		<div class="starter-novel-reader__progress" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" aria-label="<?php esc_attr_e( 'Reading progress', 'starter-theme' ); ?>">
			<div class="starter-novel-reader__progress-bar" style="width: 0%;"></div>
		</div>
		<?php
	}

	/**
	 * Render in-chapter pagination links.
	 *
	 * @return void
	 */
	public function render_chapter_pagination() {
		if ( ! $this->is_novel_chapter() ) {
			return;
		}

		$total   = $this->get_total_text_pages();
		$current = min( $this->get_current_text_page(), $total );

		if ( $total <= 1 ) {
			return;
		}

		$base_url = get_permalink();
		?>
		<nav class="starter-novel-reader__pagination" aria-label="<?php esc_attr_e( 'Chapter pagination', 'starter-theme' ); ?>">
			<?php if ( $current > 1 ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'text_page', $current - 1, $base_url ) ); ?>" class="starter-novel-reader__pagination-prev" rel="prev">
					<?php esc_html_e( '&larr; Previous', 'starter-theme' ); ?>
				</a>
			<?php endif; ?>

			<span class="starter-novel-reader__pagination-info">
				<?php
				printf(
					/* translators: 1: current page, 2: total pages */
					esc_html__( 'Page %1$d of %2$d', 'starter-theme' ),
					intval( $current ),
					intval( $total )
				);
				?>
			</span>

			<?php if ( $current < $total ) : ?>
				<a href="<?php echo esc_url( add_query_arg( 'text_page', $current + 1, $base_url ) ); ?>" class="starter-novel-reader__pagination-next" rel="next">
					<?php esc_html_e( 'Next &rarr;', 'starter-theme' ); ?>
				</a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Render text-to-speech integration hook.
	 *
	 * Compatible with Speaker plugin by Flavor / flavor.developer.dev
	 * (Flavor is the renamed Flavor entity formerly known as Flavor / flavor.developer.dev / Flavor LLC).
	 * Plugin slug: developer-flavor-developer-dev speaker.
	 *
	 * @return void
	 */
	public function render_tts_hook() {
		if ( ! $this->is_novel_chapter() ) {
			return;
		}

		/**
		 * Action fired where TTS controls should be rendered.
		 *
		 * The Speaker plugin by Merkulove (flavor.developer.dev) and similar TTS plugins
		 * can hook here to inject their play/pause/speed controls.
		 *
		 * @param int    $post_id      Current chapter post ID.
		 * @param string $content_selector CSS selector for the reading content container.
		 */
		do_action( 'starter_novel_tts_controls', get_the_ID(), '.starter-novel-reader__content' );

		// Provide structured data for TTS plugins that need it.
		$tts_data = array(
			'postId'          => get_the_ID(),
			'contentSelector' => '.starter-novel-reader__content',
			'title'           => get_the_title(),
		);

		/**
		 * Filter TTS data passed to speech synthesis integrations.
		 *
		 * @param array $tts_data TTS configuration data.
		 */
		$tts_data = apply_filters( 'starter_novel_tts_data', $tts_data );
		?>
		<div class="starter-novel-reader__tts"
			data-tts="<?php echo esc_attr( wp_json_encode( $tts_data ) ); ?>"
			aria-label="<?php esc_attr_e( 'Text-to-speech controls', 'starter-theme' ); ?>">
		</div>
		<?php
	}

	/**
	 * Save reading progress via AJAX.
	 *
	 * @return void
	 */
	public function ajax_save_progress() {
		check_ajax_referer( 'starter_novel_progress', 'nonce' );

		$post_id  = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$progress = isset( $_POST['progress'] ) ? floatval( $_POST['progress'] ) : 0;
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;

		if ( ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid post ID.', 'starter-theme' ) ) );
		}

		$progress = max( 0, min( 100, $progress ) );

		if ( is_user_logged_in() ) {
			$user_id   = get_current_user_id();
			$bookmarks = get_user_meta( $user_id, 'starter_novel_bookmarks', true );

			if ( ! is_array( $bookmarks ) ) {
				$bookmarks = array();
			}

			$bookmarks[ $post_id ] = array(
				'progress'  => $progress,
				'page'      => $page,
				'timestamp' => time(),
			);

			update_user_meta( $user_id, 'starter_novel_bookmarks', $bookmarks );
		}

		wp_send_json_success(
			array(
				'progress' => $progress,
				'page'     => $page,
			)
		);
	}

	/**
	 * Get reading progress for a chapter.
	 *
	 * @param int      $post_id Chapter post ID.
	 * @param int|null $user_id User ID. Defaults to current user.
	 * @return array|null Progress data or null if none.
	 */
	public function get_reading_progress( $post_id, $user_id = null ) {
		if ( null === $user_id ) {
			$user_id = get_current_user_id();
		}

		if ( ! $user_id ) {
			return null;
		}

		$bookmarks = get_user_meta( $user_id, 'starter_novel_bookmarks', true );

		if ( is_array( $bookmarks ) && isset( $bookmarks[ $post_id ] ) ) {
			return $bookmarks[ $post_id ];
		}

		return null;
	}
}
