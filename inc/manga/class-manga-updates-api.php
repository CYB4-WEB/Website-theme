<?php
/**
 * MangaUpdates API Integration.
 *
 * Fetches manga metadata from MangaUpdates.com API v1 to auto-populate
 * manga posts. Provides admin meta box, front-end upload integration,
 * search, and bulk import capabilities.
 *
 * @package starter Theme
 * @subpackage Manga
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_MangaUpdates_API
 *
 * Integrates with the MangaUpdates.com API v1 to fetch and import manga data.
 *
 * @since 1.0.0
 */
class Starter_MangaUpdates_API {

	/**
	 * MangaUpdates API v1 base URL.
	 *
	 * @var string
	 */
	const API_BASE = 'https://api.mangaupdates.com/v1/';

	/**
	 * Post meta key for the MangaUpdates series ID.
	 *
	 * @var string
	 */
	const META_MU_ID = '_starter_mangaupdates_id';

	/**
	 * Post meta key for the original MangaUpdates cover image URL.
	 *
	 * @var string
	 */
	const META_MU_COVER = '_starter_mangaupdates_cover_url';

	/**
	 * Manga post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'wp-manga';

	/**
	 * Bulk import admin page slug.
	 *
	 * @var string
	 */
	const IMPORT_PAGE_SLUG = 'starter-mangaupdates-import';

	/**
	 * Transient cache duration in seconds (1 hour).
	 *
	 * @var int
	 */
	const CACHE_TTL = 3600;

	/**
	 * User-Agent string sent with API requests.
	 *
	 * @var string
	 */
	const USER_AGENT = 'ProjectAlpha-WordPress-Theme/1.0 (MangaUpdates Integration)';

	/**
	 * Status mapping from MangaUpdates to internal values.
	 *
	 * @var array
	 */
	private static $status_map = array(
		'Complete'  => 'completed',
		'Ongoing'   => 'ongoing',
		'Cancelled' => 'cancelled',
		'Hiatus'    => 'hiatus',
		'Discontinued' => 'cancelled',
	);

	/**
	 * Singleton instance.
	 *
	 * @var Starter_MangaUpdates_API|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Starter_MangaUpdates_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Register hooks.
	 */
	private function __construct() {
		// Admin meta box.
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

		// Admin menu for bulk import.
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );

		// Enqueue scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_scripts' ) );

		// AJAX endpoints (logged-in users).
		add_action( 'wp_ajax_starter_fetch_mangaupdates', array( $this, 'ajax_fetch' ) );
		add_action( 'wp_ajax_starter_apply_mangaupdates', array( $this, 'ajax_apply' ) );
		add_action( 'wp_ajax_starter_bulk_import_mangaupdates', array( $this, 'ajax_bulk_import' ) );
		add_action( 'wp_ajax_starter_search_mangaupdates', array( $this, 'ajax_search' ) );

		// Front-end upload form hook.
		add_action( 'starter_upload_form_after_fields', array( $this, 'render_frontend_field' ) );
	}

	// -------------------------------------------------------------------------
	// Admin UI
	// -------------------------------------------------------------------------

	/**
	 * Register the MangaUpdates meta box on the manga edit screen.
	 *
	 * @return void
	 */
	public function add_meta_box() {
		add_meta_box(
			'starter-mangaupdates',
			esc_html__( 'MangaUpdates Integration', 'starter' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Render the MangaUpdates meta box contents.
	 *
	 * @param WP_Post $post Current post object.
	 * @return void
	 */
	public function render_meta_box( $post ) {
		$mu_id = get_post_meta( $post->ID, self::META_MU_ID, true );
		wp_nonce_field( 'starter_mangaupdates_meta', 'starter_mangaupdates_nonce' );
		?>
		<div id="starter-mu-wrap" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
			<p>
				<label for="starter-mu-input">
					<?php esc_html_e( 'MangaUpdates URL or ID:', 'starter' ); ?>
				</label>
				<input type="text" id="starter-mu-input"
					class="widefat starter-mu-input"
					value="<?php echo esc_attr( $mu_id ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. axmopy6 or full URL', 'starter' ); ?>" />
			</p>
			<p class="starter-mu-actions">
				<button type="button" class="button button-primary starter-mu-fetch">
					<?php esc_html_e( 'Fetch Data', 'starter' ); ?>
				</button>
				<?php if ( $mu_id ) : ?>
					<button type="button" class="button starter-mu-refresh">
						<?php esc_html_e( 'Refresh from MangaUpdates', 'starter' ); ?>
					</button>
				<?php endif; ?>
				<button type="button" class="button starter-mu-search-btn">
					<?php esc_html_e( 'Search MangaUpdates', 'starter' ); ?>
				</button>
				<span class="spinner starter-mu-spinner"></span>
			</p>
			<div class="starter-mu-error" style="display:none;"></div>
			<div class="starter-mu-preview" style="display:none;"></div>
		</div>
		<?php
	}

	/**
	 * Register the bulk import admin page under the Manga menu.
	 *
	 * @return void
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			esc_html__( 'Import from MangaUpdates', 'starter' ),
			esc_html__( 'Import from MangaUpdates', 'starter' ),
			'manage_options',
			self::IMPORT_PAGE_SLUG,
			array( $this, 'render_import_page' )
		);
	}

	/**
	 * Render the bulk import admin page.
	 *
	 * @return void
	 */
	public function render_import_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'starter' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import from MangaUpdates', 'starter' ); ?></h1>
			<p><?php esc_html_e( 'Paste MangaUpdates URLs or IDs below, one per line. Each will be fetched and created as a manga post.', 'starter' ); ?></p>

			<div id="starter-mu-bulk-wrap">
				<textarea id="starter-mu-bulk-input" rows="10" class="large-text"
					placeholder="<?php esc_attr_e( "https://www.mangaupdates.com/series/axmopy6/\nhttps://www.mangaupdates.com/series/abc123/\nxyz789", 'starter' ); ?>"></textarea>
				<p>
					<label>
						<input type="checkbox" id="starter-mu-bulk-skip-dupes" checked />
						<?php esc_html_e( 'Skip duplicates (series already imported)', 'starter' ); ?>
					</label>
				</p>
				<p>
					<button type="button" class="button button-primary" id="starter-mu-bulk-start">
						<?php esc_html_e( 'Start Import', 'starter' ); ?>
					</button>
					<span class="spinner" id="starter-mu-bulk-spinner"></span>
				</p>
				<div id="starter-mu-bulk-progress" style="display:none;">
					<div class="starter-mu-progress-bar-wrap">
						<div class="starter-mu-progress-bar" style="width:0%;"></div>
					</div>
					<p class="starter-mu-progress-text"></p>
				</div>
				<div id="starter-mu-bulk-log"></div>
			</div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Script Enqueuing
	// -------------------------------------------------------------------------

	/**
	 * Enqueue admin scripts on manga edit screens and the import page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_scripts( $hook_suffix ) {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$is_manga_edit  = ( 'post' === $screen->base && self::POST_TYPE === $screen->post_type );
		$is_manga_new   = ( 'post-new' === $screen->base && self::POST_TYPE === $screen->post_type );
		$is_import_page = ( self::POST_TYPE . '_page_' . self::IMPORT_PAGE_SLUG === $screen->id );

		if ( ! $is_manga_edit && ! $is_manga_new && ! $is_import_page ) {
			return;
		}

		wp_enqueue_script(
			'starter-mangaupdates',
			get_template_directory_uri() . '/assets/js/mangaupdates.js',
			array(),
			wp_get_theme()->get( 'Version' ),
			true
		);

		wp_localize_script( 'starter-mangaupdates', 'starterData', $this->get_script_data() );

		wp_enqueue_style(
			'starter-mangaupdates',
			false
		);

		// Inline admin styles for the meta box and import page.
		wp_add_inline_style( 'starter-mangaupdates', $this->get_inline_styles() );
	}

	/**
	 * Enqueue front-end scripts on pages with the manga upload form.
	 *
	 * @return void
	 */
	public function enqueue_frontend_scripts() {
		if ( ! $this->is_upload_page() ) {
			return;
		}

		wp_enqueue_script(
			'starter-mangaupdates',
			get_template_directory_uri() . '/assets/js/mangaupdates.js',
			array(),
			wp_get_theme()->get( 'Version' ),
			true
		);

		wp_localize_script( 'starter-mangaupdates', 'starterData', $this->get_script_data() );
	}

	/**
	 * Build the localized script data array.
	 *
	 * @return array
	 */
	private function get_script_data() {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'starter_mangaupdates' ),
			'i18n'    => array(
				'fetchError'    => esc_html__( 'Failed to fetch data from MangaUpdates.', 'starter' ),
				'applyError'    => esc_html__( 'Failed to apply data.', 'starter' ),
				'notFound'      => esc_html__( 'Series not found on MangaUpdates.', 'starter' ),
				'networkError'  => esc_html__( 'Network error. Please try again.', 'starter' ),
				'fetchBtn'      => esc_html__( 'Fetch Data', 'starter' ),
				'fetching'      => esc_html__( 'Fetching...', 'starter' ),
				'applyBtn'      => esc_html__( 'Apply Selected', 'starter' ),
				'applying'      => esc_html__( 'Applying...', 'starter' ),
				'searchBtn'     => esc_html__( 'Search MangaUpdates', 'starter' ),
				'searching'     => esc_html__( 'Searching...', 'starter' ),
				'noResults'     => esc_html__( 'No results found.', 'starter' ),
				'importDone'    => esc_html__( 'Import complete.', 'starter' ),
				'importSkipped' => esc_html__( 'Skipped (duplicate):', 'starter' ),
				'importFailed'  => esc_html__( 'Failed:', 'starter' ),
				'importCreated' => esc_html__( 'Created:', 'starter' ),
				'selectAll'     => esc_html__( 'Select All', 'starter' ),
				'deselectAll'   => esc_html__( 'Deselect All', 'starter' ),
				'title'         => esc_html__( 'Title', 'starter' ),
				'altNames'      => esc_html__( 'Alternative Names', 'starter' ),
				'description'   => esc_html__( 'Description', 'starter' ),
				'type'          => esc_html__( 'Type', 'starter' ),
				'genres'        => esc_html__( 'Genres', 'starter' ),
				'authors'       => esc_html__( 'Authors', 'starter' ),
				'artists'       => esc_html__( 'Artists', 'starter' ),
				'publisher'     => esc_html__( 'Publisher', 'starter' ),
				'year'          => esc_html__( 'Year', 'starter' ),
				'status'        => esc_html__( 'Status', 'starter' ),
				'cover'         => esc_html__( 'Cover Image', 'starter' ),
				'tags'          => esc_html__( 'Tags', 'starter' ),
				'rating'        => esc_html__( 'Rating', 'starter' ),
			),
		);
	}

	/**
	 * Inline CSS for admin meta box and import page.
	 *
	 * @return string
	 */
	private function get_inline_styles() {
		return '
			.starter-mu-spinner { float: none; vertical-align: middle; }
			.starter-mu-error { color: #d63638; padding: 8px; background: #fcf0f1; border-left: 4px solid #d63638; margin: 8px 0; }
			.starter-mu-preview { margin-top: 12px; }
			.starter-mu-preview table { width: 100%; border-collapse: collapse; font-size: 12px; }
			.starter-mu-preview th,
			.starter-mu-preview td { padding: 6px 8px; border-bottom: 1px solid #ddd; text-align: left; vertical-align: top; }
			.starter-mu-preview th { width: 30%; font-weight: 600; }
			.starter-mu-preview td { word-break: break-word; }
			.starter-mu-preview .starter-mu-field-cb { margin-right: 6px; }
			.starter-mu-preview img { max-width: 120px; height: auto; display: block; margin-top: 4px; }
			.starter-mu-apply-wrap { margin-top: 10px; text-align: right; }
			.starter-mu-toggle-all { cursor: pointer; color: #2271b1; text-decoration: underline; font-size: 12px; }
			.starter-mu-search-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.6); z-index: 100100; display: flex; align-items: center; justify-content: center; }
			.starter-mu-search-dialog { background: #fff; border-radius: 4px; padding: 20px; width: 520px; max-width: 90vw; max-height: 80vh; overflow-y: auto; box-shadow: 0 4px 20px rgba(0,0,0,.3); }
			.starter-mu-search-dialog h3 { margin-top: 0; }
			.starter-mu-search-dialog input[type="text"] { width: 100%; padding: 8px; margin-bottom: 10px; }
			.starter-mu-search-results { list-style: none; margin: 0; padding: 0; max-height: 400px; overflow-y: auto; }
			.starter-mu-search-results li { padding: 10px; border-bottom: 1px solid #eee; cursor: pointer; display: flex; align-items: center; gap: 10px; }
			.starter-mu-search-results li:hover { background: #f0f6fc; }
			.starter-mu-search-results img { width: 40px; height: 56px; object-fit: cover; flex-shrink: 0; }
			.starter-mu-search-results .starter-mu-sr-info { flex: 1; }
			.starter-mu-search-results .starter-mu-sr-title { font-weight: 600; }
			.starter-mu-search-results .starter-mu-sr-year { color: #666; font-size: 12px; }
			.starter-mu-progress-bar-wrap { background: #ddd; border-radius: 4px; overflow: hidden; height: 20px; margin: 10px 0; }
			.starter-mu-progress-bar { background: #2271b1; height: 100%; transition: width .3s ease; }
			#starter-mu-bulk-log .starter-mu-log-entry { padding: 4px 0; border-bottom: 1px solid #f0f0f0; font-size: 13px; }
			.starter-mu-log-entry.success { color: #00a32a; }
			.starter-mu-log-entry.skipped { color: #dba617; }
			.starter-mu-log-entry.error { color: #d63638; }
		';
	}

	/**
	 * Check whether the current page is the front-end manga upload page.
	 *
	 * @return bool
	 */
	private function is_upload_page() {
		/**
		 * Filter to identify the front-end manga upload page.
		 *
		 * @param bool $is_upload Whether the current page is an upload page.
		 */
		return (bool) apply_filters( 'starter_is_manga_upload_page', false );
	}

	// -------------------------------------------------------------------------
	// Front-end Upload Form Integration
	// -------------------------------------------------------------------------

	/**
	 * Render the MangaUpdates field in the front-end upload form.
	 *
	 * @return void
	 */
	public function render_frontend_field() {
		if ( ! is_user_logged_in() ) {
			return;
		}
		?>
		<div class="starter-mu-frontend-wrap starter-form-group" data-post-id="0">
			<label for="starter-mu-fe-input">
				<?php esc_html_e( 'MangaUpdates URL or ID', 'starter' ); ?>
			</label>
			<div class="starter-mu-fe-row">
				<input type="text" id="starter-mu-fe-input"
					class="starter-mu-input"
					name="mangaupdates_id"
					placeholder="<?php esc_attr_e( 'Paste URL or ID to auto-fill fields', 'starter' ); ?>" />
				<button type="button" class="starter-btn starter-mu-fetch">
					<?php esc_html_e( 'Fetch', 'starter' ); ?>
				</button>
				<button type="button" class="starter-btn starter-btn-outline starter-mu-search-btn">
					<?php esc_html_e( 'Search', 'starter' ); ?>
				</button>
			</div>
			<span class="starter-mu-spinner" style="display:none;"></span>
			<div class="starter-mu-error" style="display:none;"></div>
			<div class="starter-mu-preview" style="display:none;"></div>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// API Communication
	// -------------------------------------------------------------------------

	/**
	 * Parse a MangaUpdates URL or plain ID into a series ID string.
	 *
	 * Accepts formats:
	 * - https://www.mangaupdates.com/series/axmopy6/
	 * - https://www.mangaupdates.com/series/axmopy6/title-slug
	 * - https://mangaupdates.com/series/axmopy6
	 * - axmopy6
	 *
	 * @param string $input URL or ID string.
	 * @return string|WP_Error The series ID or error.
	 */
	public function parse_series_id( $input ) {
		$input = trim( $input );

		if ( empty( $input ) ) {
			return new WP_Error( 'empty_input', __( 'Please enter a MangaUpdates URL or ID.', 'starter' ) );
		}

		// If it looks like a URL, extract the ID from the path.
		if ( false !== strpos( $input, 'mangaupdates.com' ) ) {
			$path = wp_parse_url( $input, PHP_URL_PATH );

			if ( $path && preg_match( '#/series/([a-zA-Z0-9]+)#', $path, $matches ) ) {
				return sanitize_text_field( $matches[1] );
			}

			return new WP_Error( 'invalid_url', __( 'Could not extract series ID from the URL.', 'starter' ) );
		}

		// Treat as a plain ID; allow alphanumeric characters only.
		if ( preg_match( '/^[a-zA-Z0-9]+$/', $input ) ) {
			return sanitize_text_field( $input );
		}

		return new WP_Error( 'invalid_id', __( 'Invalid MangaUpdates ID format.', 'starter' ) );
	}

	/**
	 * Fetch series data from the MangaUpdates API.
	 *
	 * @param string $series_id The series ID.
	 * @param bool   $use_cache Whether to use transient cache.
	 * @return array|WP_Error Mapped data array or error.
	 */
	public function fetch_series( $series_id, $use_cache = true ) {
		$cache_key = 'starter_mu_series_' . $series_id;

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$url = self::API_BASE . 'series/' . rawurlencode( $series_id );

		$response = wp_remote_get( $url, array(
			'timeout'    => 15,
			'user-agent' => self::USER_AGENT,
			'headers'    => array(
				'Accept' => 'application/json',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'api_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'MangaUpdates API request failed: %s', 'starter' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 404 === $code ) {
			return new WP_Error( 'not_found', __( 'Series not found on MangaUpdates.', 'starter' ) );
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'MangaUpdates API returned HTTP %d.', 'starter' ),
					$code
				)
			);
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid JSON response from MangaUpdates.', 'starter' ) );
		}

		$mapped = $this->map_api_response( $data );

		if ( $use_cache ) {
			set_transient( $cache_key, $mapped, self::CACHE_TTL );
		}

		return $mapped;
	}

	/**
	 * Search for series on MangaUpdates by title.
	 *
	 * @param string $query Search query.
	 * @return array|WP_Error Array of result items or error.
	 */
	public function search_series( $query ) {
		$query = sanitize_text_field( $query );

		if ( empty( $query ) ) {
			return new WP_Error( 'empty_query', __( 'Please enter a search term.', 'starter' ) );
		}

		$url = self::API_BASE . 'series/search';

		$response = wp_remote_post( $url, array(
			'timeout'    => 15,
			'user-agent' => self::USER_AGENT,
			'headers'    => array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			'body' => wp_json_encode( array( 'search' => $query ) ),
		) );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'search_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'MangaUpdates search failed: %s', 'starter' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'search_api_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'MangaUpdates search returned HTTP %d.', 'starter' ),
					$code
				)
			);
		}

		$data = json_decode( $body, true );

		if ( ! is_array( $data ) ) {
			return new WP_Error( 'invalid_search_response', __( 'Invalid search response from MangaUpdates.', 'starter' ) );
		}

		$results = array();
		$items   = $data['results'] ?? array();

		foreach ( $items as $item ) {
			$record = $item['record'] ?? $item;

			$results[] = array(
				'series_id' => sanitize_text_field( $record['series_id'] ?? '' ),
				'title'     => sanitize_text_field( $record['title'] ?? '' ),
				'year'      => sanitize_text_field( $record['year'] ?? '' ),
				'type'      => sanitize_text_field( $record['type'] ?? '' ),
				'cover'     => esc_url_raw( $record['image']['url']['thumb'] ?? $record['image']['url']['original'] ?? '' ),
			);
		}

		return $results;
	}

	// -------------------------------------------------------------------------
	// Field Mapping
	// -------------------------------------------------------------------------

	/**
	 * Map a raw MangaUpdates API response to the internal data structure.
	 *
	 * @param array $data Raw API response data.
	 * @return array Mapped and sanitized data.
	 */
	private function map_api_response( $data ) {
		return array(
			'title'           => sanitize_text_field( $data['title'] ?? '' ),
			'alt_names'       => $this->extract_names( $data['associated'] ?? array() ),
			'description'     => wp_kses_post( $data['description'] ?? '' ),
			'type'            => sanitize_text_field( $data['type'] ?? '' ),
			'genres'          => $this->extract_genres( $data['genres'] ?? array() ),
			'authors'         => $this->extract_people( $data['authors'] ?? array() ),
			'artists'         => $this->extract_people( $data['artists'] ?? array() ),
			'publisher'       => $this->extract_publisher( $data['publishers'] ?? array() ),
			'year'            => absint( $data['year'] ?? 0 ),
			'status'          => $this->map_status( $data['status'] ?? '' ),
			'cover_url'       => esc_url_raw( $data['image']['url']['original'] ?? '' ),
			'tags'            => $this->extract_categories( $data['categories'] ?? array() ),
			'rating'          => floatval( $data['bayesian_rating'] ?? 0 ),
			'mangaupdates_id' => sanitize_text_field( $data['series_id'] ?? '' ),
		);
	}

	/**
	 * Extract alternative names from the associated array.
	 *
	 * @param array $associated Array of associated name entries.
	 * @return array List of name strings.
	 */
	private function extract_names( $associated ) {
		$names = array();

		foreach ( $associated as $entry ) {
			$name = '';

			if ( is_array( $entry ) && isset( $entry['title'] ) ) {
				$name = $entry['title'];
			} elseif ( is_string( $entry ) ) {
				$name = $entry;
			}

			$name = sanitize_text_field( $name );

			if ( ! empty( $name ) ) {
				$names[] = $name;
			}
		}

		return $names;
	}

	/**
	 * Extract genre names from the genres array.
	 *
	 * @param array $genres Array of genre entries.
	 * @return array List of genre name strings.
	 */
	private function extract_genres( $genres ) {
		$result = array();

		foreach ( $genres as $genre ) {
			$name = '';

			if ( is_array( $genre ) && isset( $genre['genre'] ) ) {
				$name = $genre['genre'];
			} elseif ( is_string( $genre ) ) {
				$name = $genre;
			}

			$name = sanitize_text_field( $name );

			if ( ! empty( $name ) ) {
				$result[] = $name;
			}
		}

		return $result;
	}

	/**
	 * Extract people names (authors/artists) from an array.
	 *
	 * @param array $people Array of people entries.
	 * @return array List of name strings.
	 */
	private function extract_people( $people ) {
		$result = array();

		foreach ( $people as $person ) {
			$name = '';

			if ( is_array( $person ) && isset( $person['name'] ) ) {
				$name = $person['name'];
			} elseif ( is_string( $person ) ) {
				$name = $person;
			}

			$name = sanitize_text_field( $name );

			if ( ! empty( $name ) ) {
				$result[] = $name;
			}
		}

		return $result;
	}

	/**
	 * Extract the original publisher from the publishers array.
	 *
	 * Filters for entries flagged as type "Original" or falls back to the first.
	 *
	 * @param array $publishers Array of publisher entries.
	 * @return string Publisher name.
	 */
	private function extract_publisher( $publishers ) {
		if ( empty( $publishers ) ) {
			return '';
		}

		// Look for the original publisher first.
		foreach ( $publishers as $pub ) {
			if ( ! is_array( $pub ) ) {
				continue;
			}

			$type = strtolower( $pub['type'] ?? '' );

			if ( 'original' === $type ) {
				$name = $pub['publisher_name'] ?? $pub['name'] ?? '';
				return sanitize_text_field( $name );
			}
		}

		// Fallback: return the first publisher.
		$first = $publishers[0];

		if ( is_array( $first ) ) {
			$name = $first['publisher_name'] ?? $first['name'] ?? '';
			return sanitize_text_field( $name );
		}

		return is_string( $first ) ? sanitize_text_field( $first ) : '';
	}

	/**
	 * Extract tag names from the categories array.
	 *
	 * @param array $categories Array of category entries.
	 * @return array List of tag name strings.
	 */
	private function extract_categories( $categories ) {
		$result = array();

		foreach ( $categories as $cat ) {
			$name = '';

			if ( is_array( $cat ) && isset( $cat['category'] ) ) {
				$name = $cat['category'];
			} elseif ( is_string( $cat ) ) {
				$name = $cat;
			}

			$name = sanitize_text_field( $name );

			if ( ! empty( $name ) ) {
				$result[] = $name;
			}
		}

		return $result;
	}

	/**
	 * Map a MangaUpdates status string to an internal status slug.
	 *
	 * @param string $status Raw status from the API.
	 * @return string Internal status slug.
	 */
	private function map_status( $status ) {
		$status = sanitize_text_field( $status );

		foreach ( self::$status_map as $mu_status => $internal ) {
			if ( false !== stripos( $status, $mu_status ) ) {
				return $internal;
			}
		}

		return sanitize_key( strtolower( $status ) );
	}

	// -------------------------------------------------------------------------
	// AJAX Handlers
	// -------------------------------------------------------------------------

	/**
	 * AJAX: Fetch a single series from MangaUpdates.
	 *
	 * Action: starter_fetch_mangaupdates
	 *
	 * @return void
	 */
	public function ajax_fetch() {
		check_ajax_referer( 'starter_mangaupdates', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'starter' ) ), 403 );
		}

		$input = isset( $_POST['input'] ) ? sanitize_text_field( wp_unslash( $_POST['input'] ) ) : '';

		$series_id = $this->parse_series_id( $input );

		if ( is_wp_error( $series_id ) ) {
			wp_send_json_error( array( 'message' => $series_id->get_error_message() ) );
		}

		$use_cache = ! isset( $_POST['refresh'] ) || ! $_POST['refresh'];
		$data      = $this->fetch_series( $series_id, $use_cache );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array( 'message' => $data->get_error_message() ) );
		}

		wp_send_json_success( $data );
	}

	/**
	 * AJAX: Apply fetched MangaUpdates data to a manga post.
	 *
	 * Action: starter_apply_mangaupdates
	 *
	 * @return void
	 */
	public function ajax_apply() {
		check_ajax_referer( 'starter_mangaupdates', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;

		// For new posts from the front-end, post_id may be 0 (data returned for JS to fill form).
		if ( $post_id > 0 && ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'starter' ) ), 403 );
		}

		$fields_json = isset( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : '{}';
		$fields      = json_decode( $fields_json, true );

		if ( ! is_array( $fields ) || empty( $fields ) ) {
			wp_send_json_error( array( 'message' => __( 'No fields provided.', 'starter' ) ) );
		}

		$selected = isset( $_POST['selected'] ) ? array_map( 'sanitize_key', (array) $_POST['selected'] ) : array();
		$data_raw = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '{}';
		$data     = json_decode( $data_raw, true );

		if ( ! is_array( $data ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data payload.', 'starter' ) ) );
		}

		// If no post yet, just return the filtered data for JS to populate the form.
		if ( 0 === $post_id ) {
			$filtered = $this->filter_data_by_selection( $data, $selected );
			wp_send_json_success( array(
				'applied' => $filtered,
				'post_id' => 0,
			) );
		}

		$result = $this->apply_data_to_post( $post_id, $data, $selected );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success( array(
			'applied' => $result,
			'post_id' => $post_id,
		) );
	}

	/**
	 * AJAX: Search MangaUpdates by title.
	 *
	 * Action: starter_search_mangaupdates
	 *
	 * @return void
	 */
	public function ajax_search() {
		check_ajax_referer( 'starter_mangaupdates', 'nonce' );

		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'starter' ) ), 403 );
		}

		$query = isset( $_POST['query'] ) ? sanitize_text_field( wp_unslash( $_POST['query'] ) ) : '';

		$results = $this->search_series( $query );

		if ( is_wp_error( $results ) ) {
			wp_send_json_error( array( 'message' => $results->get_error_message() ) );
		}

		wp_send_json_success( array( 'results' => $results ) );
	}

	/**
	 * AJAX: Bulk import series from MangaUpdates (admin only).
	 *
	 * Action: starter_bulk_import_mangaupdates
	 *
	 * Processes a single item per request. The JS client calls this
	 * repeatedly for each URL/ID to allow progress tracking.
	 *
	 * @return void
	 */
	public function ajax_bulk_import() {
		check_ajax_referer( 'starter_mangaupdates', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Admin permission required.', 'starter' ) ), 403 );
		}

		$input      = isset( $_POST['input'] ) ? sanitize_text_field( wp_unslash( $_POST['input'] ) ) : '';
		$skip_dupes = isset( $_POST['skip_dupes'] ) && $_POST['skip_dupes'];

		$series_id = $this->parse_series_id( $input );

		if ( is_wp_error( $series_id ) ) {
			wp_send_json_error( array(
				'message' => $series_id->get_error_message(),
				'input'   => $input,
				'status'  => 'error',
			) );
		}

		// Check for duplicate.
		if ( $skip_dupes && $this->series_exists( $series_id ) ) {
			wp_send_json_success( array(
				'input'   => $input,
				'status'  => 'skipped',
				'message' => __( 'Already imported.', 'starter' ),
			) );
		}

		$data = $this->fetch_series( $series_id, false );

		if ( is_wp_error( $data ) ) {
			wp_send_json_error( array(
				'message' => $data->get_error_message(),
				'input'   => $input,
				'status'  => 'error',
			) );
		}

		$post_id = $this->create_manga_post( $data );

		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array(
				'message' => $post_id->get_error_message(),
				'input'   => $input,
				'status'  => 'error',
			) );
		}

		wp_send_json_success( array(
			'input'   => $input,
			'status'  => 'created',
			'post_id' => $post_id,
			'title'   => $data['title'],
			'edit_url' => get_edit_post_link( $post_id, 'raw' ),
		) );
	}

	// -------------------------------------------------------------------------
	// Data Application
	// -------------------------------------------------------------------------

	/**
	 * Filter data array to only include selected fields.
	 *
	 * @param array $data     Full mapped data array.
	 * @param array $selected Array of field keys to include.
	 * @return array Filtered data.
	 */
	private function filter_data_by_selection( $data, $selected ) {
		if ( empty( $selected ) ) {
			return $data;
		}

		$filtered = array();

		foreach ( $selected as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				$filtered[ $key ] = $data[ $key ];
			}
		}

		return $filtered;
	}

	/**
	 * Apply fetched data to an existing manga post.
	 *
	 * @param int   $post_id  The post ID.
	 * @param array $data     Mapped data from MangaUpdates.
	 * @param array $selected Field keys to apply (empty = all).
	 * @return array|WP_Error Applied fields or error.
	 */
	private function apply_data_to_post( $post_id, $data, $selected = array() ) {
		$data = $this->filter_data_by_selection( $data, $selected );

		$post_update = array( 'ID' => $post_id );
		$applied     = array();

		// Title.
		if ( ! empty( $data['title'] ) && isset( $data['title'] ) ) {
			$post_update['post_title'] = $data['title'];
			$applied[]                 = 'title';
		}

		// Description.
		if ( ! empty( $data['description'] ) && isset( $data['description'] ) ) {
			$post_update['post_content'] = $data['description'];
			$applied[]                   = 'description';
		}

		// Update the post if we have changes.
		if ( count( $post_update ) > 1 ) {
			$result = wp_update_post( $post_update, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Meta fields.
		$meta_prefix = '_starter_manga_';

		if ( isset( $data['alt_names'] ) ) {
			update_post_meta( $post_id, $meta_prefix . 'alt_names', $data['alt_names'] );
			$applied[] = 'alt_names';
		}

		if ( isset( $data['type'] ) ) {
			update_post_meta( $post_id, $meta_prefix . 'type', $data['type'] );
			$applied[] = 'type';
		}

		if ( isset( $data['authors'] ) ) {
			update_post_meta( $post_id, $meta_prefix . 'authors', $data['authors'] );
			$applied[] = 'authors';
		}

		if ( isset( $data['artists'] ) ) {
			update_post_meta( $post_id, $meta_prefix . 'artists', $data['artists'] );
			$applied[] = 'artists';
		}

		if ( isset( $data['publisher'] ) ) {
			update_post_meta( $post_id, $meta_prefix . 'publisher', $data['publisher'] );
			$applied[] = 'publisher';
		}

		if ( isset( $data['year'] ) ) {
			update_post_meta( $post_id, $meta_prefix . 'year', $data['year'] );
			$applied[] = 'year';
		}

		if ( isset( $data['status'] ) ) {
			update_post_meta( $post_id, $meta_prefix . 'status', $data['status'] );
			$applied[] = 'status';
		}

		if ( isset( $data['rating'] ) ) {
			update_post_meta( $post_id, '_starter_manga_average_rating', $data['rating'] );
			$applied[] = 'rating';
		}

		// MangaUpdates ID.
		if ( ! empty( $data['mangaupdates_id'] ) ) {
			update_post_meta( $post_id, self::META_MU_ID, $data['mangaupdates_id'] );
		}

		// Genres taxonomy.
		if ( ! empty( $data['genres'] ) ) {
			wp_set_object_terms( $post_id, $data['genres'], 'wp-manga-genre', false );
			$applied[] = 'genres';
		}

		// Tags taxonomy.
		if ( ! empty( $data['tags'] ) ) {
			wp_set_object_terms( $post_id, $data['tags'], 'wp-manga-tag', false );
			$applied[] = 'tags';
		}

		// Cover image.
		if ( ! empty( $data['cover_url'] ) ) {
			$thumb_result = $this->sideload_cover_image( $post_id, $data['cover_url'] );

			if ( ! is_wp_error( $thumb_result ) ) {
				$applied[] = 'cover';
			}
		}

		return $applied;
	}

	/**
	 * Create a new manga post from MangaUpdates data.
	 *
	 * @param array $data Mapped data from the API.
	 * @return int|WP_Error New post ID or error.
	 */
	private function create_manga_post( $data ) {
		$post_args = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $data['title'] ?: __( 'Untitled Manga', 'starter' ),
			'post_content' => $data['description'] ?? '',
			'post_status'  => 'draft',
			'meta_input'   => array(
				self::META_MU_ID                  => $data['mangaupdates_id'],
				'_starter_manga_alt_names'        => $data['alt_names'] ?? array(),
				'_starter_manga_type'             => $data['type'] ?? '',
				'_starter_manga_authors'          => $data['authors'] ?? array(),
				'_starter_manga_artists'          => $data['artists'] ?? array(),
				'_starter_manga_publisher'        => $data['publisher'] ?? '',
				'_starter_manga_year'             => $data['year'] ?? 0,
				'_starter_manga_status'           => $data['status'] ?? '',
				'_starter_manga_average_rating'   => $data['rating'] ?? 0,
			),
		);

		$post_id = wp_insert_post( $post_args, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set taxonomies.
		if ( ! empty( $data['genres'] ) ) {
			wp_set_object_terms( $post_id, $data['genres'], 'wp-manga-genre', false );
		}

		if ( ! empty( $data['tags'] ) ) {
			wp_set_object_terms( $post_id, $data['tags'], 'wp-manga-tag', false );
		}

		// Sideload cover image.
		if ( ! empty( $data['cover_url'] ) ) {
			$this->sideload_cover_image( $post_id, $data['cover_url'] );
		}

		return $post_id;
	}

	// -------------------------------------------------------------------------
	// Cover Image Handling
	// -------------------------------------------------------------------------

	/**
	 * Download a remote image and set it as the post thumbnail.
	 *
	 * Uses wp_upload_bits() and wp_insert_attachment() for reliable handling.
	 *
	 * @param int    $post_id   The post to attach the image to.
	 * @param string $image_url Remote image URL.
	 * @return int|WP_Error Attachment ID or error.
	 */
	private function sideload_cover_image( $post_id, $image_url ) {
		if ( empty( $image_url ) ) {
			return new WP_Error( 'no_image', __( 'No image URL provided.', 'starter' ) );
		}

		// Store the original MU image URL for reference.
		update_post_meta( $post_id, self::META_MU_COVER, $image_url );

		// Download the image.
		$response = wp_remote_get( $image_url, array(
			'timeout'    => 30,
			'user-agent' => self::USER_AGENT,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code ) {
			return new WP_Error(
				'image_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Image download failed with HTTP %d.', 'starter' ),
					$code
				)
			);
		}

		$image_data   = wp_remote_retrieve_body( $response );
		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		// Determine file extension.
		$extension = 'jpg';

		if ( false !== strpos( $content_type, 'png' ) ) {
			$extension = 'png';
		} elseif ( false !== strpos( $content_type, 'gif' ) ) {
			$extension = 'gif';
		} elseif ( false !== strpos( $content_type, 'webp' ) ) {
			$extension = 'webp';
		}

		$filename = sprintf( 'mangaupdates-cover-%d-%s.%s', $post_id, wp_generate_password( 6, false ), $extension );

		// Upload the file.
		$upload = wp_upload_bits( $filename, null, $image_data );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'upload_error', $upload['error'] );
		}

		// Prepare attachment data.
		$filetype   = wp_check_filetype( $upload['file'] );
		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attach_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );

		if ( is_wp_error( $attach_id ) ) {
			return $attach_id;
		}

		// Generate attachment metadata (thumbnails, etc.).
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		wp_update_attachment_metadata( $attach_id, $metadata );

		// Set as featured image.
		set_post_thumbnail( $post_id, $attach_id );

		return $attach_id;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Check whether a manga post with the given MangaUpdates ID already exists.
	 *
	 * @param string $series_id MangaUpdates series ID.
	 * @return bool True if a post with that ID exists.
	 */
	private function series_exists( $series_id ) {
		$query = new WP_Query( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::META_MU_ID,
					'value' => $series_id,
				),
			),
			'no_found_rows'  => true,
		) );

		return $query->have_posts();
	}

	/**
	 * Fetch manga data by a MangaUpdates URL or series ID string.
	 *
	 * Convenience wrapper used by the frontend upload shortcode and
	 * admin import page.
	 *
	 * @param string $url MangaUpdates URL or series ID.
	 * @return array|\WP_Error Normalised manga data array or WP_Error on failure.
	 */
	public function fetch_by_url( $url ) {
		$series_id = $this->parse_series_id( $url );

		if ( ! $series_id ) {
			return new \WP_Error(
				'invalid_url',
				__( 'Could not extract a series ID from the URL. Use a URL like: https://www.mangaupdates.com/series/axmopy6', 'starter-theme' )
			);
		}

		return $this->fetch_series( $series_id );
	}

	/**
	 * Prevent cloning of the singleton.
	 */
	private function __clone() {}

	/**
	 * Prevent unserializing of the singleton.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}
}
