<?php
/**
 * Chapter content protector.
 *
 * Encrypts chapter image URLs, provides canvas-based rendering data,
 * disables right-click / drag on reader pages, and rate-limits image
 * requests to deter scraping.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Chapter_Protector
 *
 * Protects manga / novel / video chapter images from being scraped or
 * hotlinked by encrypting URLs, serving them through a proxy, and
 * rendering on an HTML5 canvas instead of plain <img> tags.
 */
class Starter_Chapter_Protector {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Chapter_Protector|null
	 */
	private static $instance = null;

	/**
	 * HMAC secret key loaded from the environment.
	 *
	 * @var string
	 */
	private $secret_key = '';

	/**
	 * Token validity window in seconds (5 minutes).
	 *
	 * @var int
	 */
	const TOKEN_TTL = 300;

	/**
	 * Maximum image requests per minute per IP.
	 *
	 * @var int
	 */
	const RATE_LIMIT = 100;

	/**
	 * Rate-limit window in seconds.
	 *
	 * @var int
	 */
	const RATE_WINDOW = 60;

	/**
	 * Transient prefix for rate-limiting.
	 *
	 * @var string
	 */
	const RATE_PREFIX = 'starter_rl_';

	/**
	 * Common scraper / bot user-agent fragments.
	 *
	 * @var string[]
	 */
	private $bot_signatures = array(
		'python-requests',
		'scrapy',
		'curl/',
		'wget/',
		'httpclient',
		'java/',
		'libwww-perl',
		'go-http-client',
		'php/',
		'mechanize',
		'aiohttp',
		'httpx',
		'node-fetch',
		'axios',
	);

	/**
	 * Supported image MIME types.
	 *
	 * @var string[]
	 */
	private $allowed_mimes = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_Chapter_Protector
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
		$this->secret_key = Starter_Env_Loader::get( 'ENCRYPTION_KEY', wp_salt( 'auth' ) );
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/*------------------------------------------------------------------
	 * Initialization
	 *-----------------------------------------------------------------*/

	/**
	 * Register all WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->is_enabled() ) {
			return;
		}

		// AJAX endpoints (logged-in and guest).
		add_action( 'wp_ajax_starter_get_chapter_images', array( $this, 'ajax_get_chapter_images' ) );
		add_action( 'wp_ajax_nopriv_starter_get_chapter_images', array( $this, 'ajax_get_chapter_images' ) );

		// Enqueue front-end protection scripts.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_protection_assets' ) );

		// Inject inline CSS spinner for loading placeholders.
		add_action( 'wp_head', array( $this, 'render_loading_css' ) );
	}

	/*------------------------------------------------------------------
	 * Settings helpers
	 *-----------------------------------------------------------------*/

	/**
	 * Whether the chapter protector is enabled.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		return (bool) get_theme_mod( 'starter_chapter_protector_enabled', true );
	}

	/**
	 * Whether right-click blocking is enabled.
	 *
	 * @return bool
	 */
	public function is_right_click_blocked() {
		return (bool) get_theme_mod( 'starter_block_right_click', true );
	}

	/**
	 * Whether canvas rendering mode is enabled.
	 *
	 * @return bool
	 */
	public function is_canvas_mode() {
		return (bool) get_theme_mod( 'starter_canvas_mode', true );
	}

	/**
	 * Register Customizer settings for the protector.
	 *
	 * Intended to be called from the theme's Customizer setup.
	 *
	 * @param WP_Customize_Manager $wp_customize Customizer manager instance.
	 * @return void
	 */
	public function register_customizer_settings( $wp_customize ) {
		$wp_customize->add_section( 'starter_protection', array(
			'title'    => esc_html__( 'Content Protection', 'starter-theme' ),
			'priority' => 200,
		) );

		// Enable / disable protector.
		$wp_customize->add_setting( 'starter_chapter_protector_enabled', array(
			'default'           => true,
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
		) );
		$wp_customize->add_control( 'starter_chapter_protector_enabled', array(
			'label'   => esc_html__( 'Enable Chapter Protector', 'starter-theme' ),
			'section' => 'starter_protection',
			'type'    => 'checkbox',
		) );

		// Right-click block.
		$wp_customize->add_setting( 'starter_block_right_click', array(
			'default'           => true,
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
		) );
		$wp_customize->add_control( 'starter_block_right_click', array(
			'label'   => esc_html__( 'Disable Right-Click on Reader', 'starter-theme' ),
			'section' => 'starter_protection',
			'type'    => 'checkbox',
		) );

		// Canvas mode.
		$wp_customize->add_setting( 'starter_canvas_mode', array(
			'default'           => true,
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
		) );
		$wp_customize->add_control( 'starter_canvas_mode', array(
			'label'   => esc_html__( 'Enable Canvas Rendering', 'starter-theme' ),
			'section' => 'starter_protection',
			'type'    => 'checkbox',
		) );
	}

	/**
	 * Sanitize a checkbox value for the Customizer.
	 *
	 * @param mixed $value Raw value.
	 * @return bool
	 */
	public function sanitize_checkbox( $value ) {
		return (bool) $value;
	}

	/*------------------------------------------------------------------
	 * Token generation & validation
	 *-----------------------------------------------------------------*/

	/**
	 * Generate a time-based HMAC token.
	 *
	 * The token incorporates the chapter ID and a timestamp rounded to
	 * the nearest TOKEN_TTL window so that it remains valid for up to
	 * 5 minutes.
	 *
	 * @param int $chapter_id Chapter post ID.
	 * @return array { token: string, timestamp: int }
	 */
	public function generate_token( $chapter_id ) {
		$timestamp = time();
		$window    = (int) floor( $timestamp / self::TOKEN_TTL );
		$data      = $chapter_id . '|' . $window;
		$token     = hash_hmac( 'sha256', $data, $this->secret_key );

		return array(
			'token'     => $token,
			'timestamp' => $timestamp,
		);
	}

	/**
	 * Validate a time-based HMAC token.
	 *
	 * Checks both the current and previous time windows to avoid
	 * rejecting tokens that were issued just before a window boundary.
	 *
	 * @param int    $chapter_id Chapter post ID.
	 * @param string $token      HMAC token to validate.
	 * @return bool
	 */
	public function validate_token( $chapter_id, $token ) {
		$current_window  = (int) floor( time() / self::TOKEN_TTL );
		$previous_window = $current_window - 1;

		$valid_current  = hash_hmac( 'sha256', $chapter_id . '|' . $current_window, $this->secret_key );
		$valid_previous = hash_hmac( 'sha256', $chapter_id . '|' . $previous_window, $this->secret_key );

		return hash_equals( $valid_current, $token ) || hash_equals( $valid_previous, $token );
	}

	/*------------------------------------------------------------------
	 * Image URL encryption
	 *-----------------------------------------------------------------*/

	/**
	 * Encrypt a single image URL for transit.
	 *
	 * The URL is encrypted with AES-256-CBC using a per-page-load IV
	 * so that the cipher-text differs on every request.
	 *
	 * @param string $url        Plain image URL.
	 * @param string $session_iv Initialisation vector for this page load.
	 * @return string Base-64 encoded encrypted payload.
	 */
	public function encrypt_image_url( $url, $session_iv ) {
		$key = hash( 'sha256', $this->secret_key, true );

		$encrypted = openssl_encrypt(
			$url,
			'aes-256-cbc',
			$key,
			0,
			$session_iv
		);

		return $encrypted;
	}

	/**
	 * Decrypt an image URL.
	 *
	 * @param string $encrypted  Base-64 encoded cipher-text.
	 * @param string $session_iv Initialisation vector used during encryption.
	 * @return string|false Plain URL on success, false on failure.
	 */
	public function decrypt_image_url( $encrypted, $session_iv ) {
		$key = hash( 'sha256', $this->secret_key, true );

		return openssl_decrypt(
			$encrypted,
			'aes-256-cbc',
			$key,
			0,
			$session_iv
		);
	}

	/**
	 * Generate encrypted image data for a chapter.
	 *
	 * Each call produces a fresh IV so that cipher-text changes on every
	 * page load. The IV is returned alongside the encrypted URLs so the
	 * JavaScript renderer can decode them.
	 *
	 * @param int      $chapter_id Chapter post ID.
	 * @param string[] $image_urls Array of plain image URLs.
	 * @return array {
	 *     iv:     string   Base-64 encoded IV,
	 *     images: string[] Array of encrypted URL strings,
	 *     key:    string   Session decryption key for the JS canvas renderer,
	 * }
	 */
	public function generate_encrypted_chapter_data( $chapter_id, array $image_urls ) {
		$session_iv  = openssl_random_pseudo_bytes( 16 );
		$iv_b64      = base64_encode( $session_iv );
		$session_key = hash_hmac( 'sha256', $chapter_id . '|' . $iv_b64, $this->secret_key );

		$encrypted_images = array();
		foreach ( $image_urls as $index => $url ) {
			$encrypted_images[] = array(
				'index' => $index,
				'data'  => $this->encrypt_image_url( $url, $session_iv ),
			);
		}

		return array(
			'iv'     => $iv_b64,
			'images' => $encrypted_images,
			'key'    => substr( $session_key, 0, 32 ),
		);
	}

	/*------------------------------------------------------------------
	 * Rate limiting
	 *-----------------------------------------------------------------*/

	/**
	 * Check and enforce rate limits for the current IP.
	 *
	 * @return bool True if the request is within limits, false if throttled.
	 */
	public function check_rate_limit() {
		$ip  = $this->get_client_ip();
		$key = self::RATE_PREFIX . md5( $ip );

		$current = get_transient( $key );

		if ( false === $current ) {
			set_transient( $key, 1, self::RATE_WINDOW );
			return true;
		}

		if ( (int) $current >= self::RATE_LIMIT ) {
			return false;
		}

		set_transient( $key, (int) $current + 1, self::RATE_WINDOW );
		return true;
	}

	/**
	 * Get the client IP address, respecting common proxy headers.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may contain multiple IPs; take the first.
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

	/*------------------------------------------------------------------
	 * Bot detection
	 *-----------------------------------------------------------------*/

	/**
	 * Determine whether the current request looks like a bot / scraper.
	 *
	 * @return bool True if a known scraper user-agent is detected.
	 */
	public function is_bot() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) )
			: '';

		if ( empty( $ua ) ) {
			return true; // No user-agent is suspicious.
		}

		foreach ( $this->bot_signatures as $sig ) {
			if ( false !== strpos( $ua, $sig ) ) {
				return true;
			}
		}

		return false;
	}

	/*------------------------------------------------------------------
	 * AJAX endpoint
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX handler: starter_get_chapter_images.
	 *
	 * Accepts chapter_id and token, validates the token, enforces rate
	 * limits and bot detection, then returns encrypted image data.
	 *
	 * @return void Sends JSON response and exits.
	 */
	public function ajax_get_chapter_images() {
		// Verify nonce.
		check_ajax_referer( 'starter_chapter_protector', 'nonce' );

		// Bot detection.
		if ( $this->is_bot() ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Access denied.', 'starter-theme' ) ),
				403
			);
		}

		// Rate limiting.
		if ( ! $this->check_rate_limit() ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Rate limit exceeded. Please wait.', 'starter-theme' ) ),
				429
			);
		}

		$chapter_id = isset( $_POST['chapter_id'] ) ? absint( $_POST['chapter_id'] ) : 0;
		$token      = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		if ( empty( $chapter_id ) || empty( $token ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Missing required parameters.', 'starter-theme' ) ),
				400
			);
		}

		// Validate HMAC token.
		if ( ! $this->validate_token( $chapter_id, $token ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Invalid or expired token.', 'starter-theme' ) ),
				403
			);
		}

		// Verify the chapter post exists and is published.
		$chapter = get_post( $chapter_id );
		if ( ! $chapter || 'publish' !== $chapter->post_status ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Chapter not found.', 'starter-theme' ) ),
				404
			);
		}

		// Retrieve chapter image URLs from post meta.
		$image_urls = get_post_meta( $chapter_id, '_starter_chapter_images', true );
		if ( ! is_array( $image_urls ) || empty( $image_urls ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'No images found for this chapter.', 'starter-theme' ) ),
				404
			);
		}

		// Filter to allowed image types.
		$image_urls = array_values( array_filter( $image_urls, array( $this, 'is_allowed_image' ) ) );

		// Encrypt.
		$encrypted_data = $this->generate_encrypted_chapter_data( $chapter_id, $image_urls );

		// Build response.
		$response = array(
			'chapter_id'  => $chapter_id,
			'count'       => count( $image_urls ),
			'iv'          => $encrypted_data['iv'],
			'key'         => $encrypted_data['key'],
			'images'      => $encrypted_data['images'],
			'canvas_mode' => $this->is_canvas_mode(),
		);

		wp_send_json_success( $response );
	}

	/**
	 * Check whether a URL points to an allowed image type.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	private function is_allowed_image( $url ) {
		$extension = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		return isset( $this->allowed_mimes[ $extension ] );
	}

	/*------------------------------------------------------------------
	 * Front-end assets
	 *-----------------------------------------------------------------*/

	/**
	 * Enqueue protection JavaScript on reader pages.
	 *
	 * @return void
	 */
	public function enqueue_protection_assets() {
		if ( ! $this->is_reader_page() ) {
			return;
		}

		wp_enqueue_script(
			'starter-chapter-protector',
			STARTER_THEME_URI . '/assets/js/chapter-protector.js',
			array( 'jquery' ),
			STARTER_THEME_VERSION,
			true
		);

		// Generate a fresh token for this page load.
		$chapter_id = $this->get_current_chapter_id();
		$token_data = $this->generate_token( $chapter_id );

		wp_localize_script( 'starter-chapter-protector', 'starterProtector', array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'starter_chapter_protector' ),
			'chapterId'       => $chapter_id,
			'token'           => $token_data['token'],
			'tokenTTL'        => self::TOKEN_TTL,
			'canvasMode'      => $this->is_canvas_mode(),
			'blockRightClick' => $this->is_right_click_blocked(),
			'i18n'            => array(
				'loading' => esc_html__( 'Loading page...', 'starter-theme' ),
				'error'   => esc_html__( 'Failed to load image.', 'starter-theme' ),
			),
		) );
	}

	/**
	 * Render inline CSS for the loading spinner placeholder.
	 *
	 * @return void
	 */
	public function render_loading_css() {
		if ( ! $this->is_reader_page() ) {
			return;
		}
		?>
		<style id="starter-protector-loading">
			.starter-chapter-page {
				position: relative;
				min-height: 200px;
				display: flex;
				align-items: center;
				justify-content: center;
				background: #f0f0f0;
				margin-bottom: 2px;
			}
			.starter-chapter-page canvas {
				display: block;
				max-width: 100%;
				height: auto;
			}
			.starter-page-spinner {
				width: 40px;
				height: 40px;
				border: 4px solid rgba(0, 0, 0, 0.1);
				border-top-color: #3498db;
				border-radius: 50%;
				animation: starter-spin 0.8s linear infinite;
			}
			@keyframes starter-spin {
				to { transform: rotate(360deg); }
			}
			.starter-reader-container {
				user-select: none;
				-webkit-user-select: none;
				-moz-user-select: none;
				-ms-user-select: none;
			}
			.starter-reader-container img {
				pointer-events: none;
				-webkit-user-drag: none;
				user-drag: none;
			}
		</style>
		<?php
	}

	/*------------------------------------------------------------------
	 * Utility helpers
	 *-----------------------------------------------------------------*/

	/**
	 * Determine whether the current page is a chapter reader page.
	 *
	 * @return bool
	 */
	private function is_reader_page() {
		return is_singular( array( 'chapter', 'manga_chapter', 'novel_chapter' ) );
	}

	/**
	 * Get the chapter post ID for the current page.
	 *
	 * @return int
	 */
	private function get_current_chapter_id() {
		return get_the_ID() ? (int) get_the_ID() : 0;
	}
}
