<?php
/**
 * Image URL encryption and proxy.
 *
 * Encrypts real image URLs for transit, provides a proxy endpoint that
 * validates tokens before streaming the actual image, and registers
 * clean rewrite rules so that encrypted URLs never reveal the real path.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Image_Encryption
 *
 * Handles:
 *  - URL encryption / decryption via HMAC-SHA256 tokens.
 *  - A PHP proxy that validates the token, fetches the real image, and
 *    streams it with no-cache headers.
 *  - Clean rewrite rules: /starter-img/{encrypted_token}/{image_hash}.
 *  - Token auto-refresh via AJAX.
 *  - Anti-hotlink Referer checking.
 */
class Starter_Image_Encryption {

	/**
	 * Singleton instance.
	 *
	 * @var Starter_Image_Encryption|null
	 */
	private static $instance = null;

	/**
	 * HMAC secret key.
	 *
	 * @var string
	 */
	private $secret_key = '';

	/**
	 * Token validity in seconds (5 minutes).
	 *
	 * @var int
	 */
	const TOKEN_TTL = 300;

	/**
	 * Seconds before expiry when the JS client should refresh.
	 *
	 * @var int
	 */
	const REFRESH_THRESHOLD = 60;

	/**
	 * Rewrite endpoint base.
	 *
	 * @var string
	 */
	const ENDPOINT = 'starter-img';

	/**
	 * Query variable for the encrypted token.
	 *
	 * @var string
	 */
	const QV_TOKEN = 'starter_img_token';

	/**
	 * Query variable for the image hash.
	 *
	 * @var string
	 */
	const QV_HASH = 'starter_img_hash';

	/**
	 * Supported MIME types keyed by extension.
	 *
	 * @var string[]
	 */
	private $mime_map = array(
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png'  => 'image/png',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
	);

	/**
	 * Get the singleton instance.
	 *
	 * @return Starter_Image_Encryption
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
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function init() {
		// Rewrite rules.
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'register_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_image_request' ) );

		// AJAX token refresh.
		add_action( 'wp_ajax_starter_refresh_image_token', array( $this, 'ajax_refresh_token' ) );
		add_action( 'wp_ajax_nopriv_starter_refresh_image_token', array( $this, 'ajax_refresh_token' ) );

		// Flush rules on theme activation.
		add_action( 'after_switch_theme', array( $this, 'flush_rewrite_rules' ) );
	}

	/*------------------------------------------------------------------
	 * Rewrite rules
	 *-----------------------------------------------------------------*/

	/**
	 * Add rewrite rules for the image proxy endpoint.
	 *
	 * URL format: /starter-img/{encrypted_token}/{image_hash}
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^' . self::ENDPOINT . '/([a-zA-Z0-9_-]+)/([a-f0-9]+)/?$',
			'index.php?' . self::QV_TOKEN . '=$matches[1]&' . self::QV_HASH . '=$matches[2]',
			'top'
		);
	}

	/**
	 * Register custom query variables.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function register_query_vars( $vars ) {
		$vars[] = self::QV_TOKEN;
		$vars[] = self::QV_HASH;
		return $vars;
	}

	/**
	 * Flush rewrite rules (called on theme activation).
	 *
	 * @return void
	 */
	public function flush_rewrite_rules() {
		$this->add_rewrite_rules();
		flush_rewrite_rules();
	}

	/*------------------------------------------------------------------
	 * URL encryption / decryption
	 *-----------------------------------------------------------------*/

	/**
	 * Generate a time-based encrypted token for an image URL.
	 *
	 * Token = HMAC-SHA256( URL | timestamp | secret )
	 *
	 * @param string $url       The real image URL.
	 * @param int    $timestamp Unix timestamp (defaults to now).
	 * @return array {
	 *     token:      string  URL-safe base-64 HMAC token,
	 *     hash:       string  Hex hash of the URL for the clean-URL segment,
	 *     expires_at: int     Unix timestamp when the token expires,
	 * }
	 */
	public function encrypt_url( $url, $timestamp = 0 ) {
		if ( 0 === $timestamp ) {
			$timestamp = time();
		}

		$payload = $url . '|' . $timestamp;
		$hmac    = hash_hmac( 'sha256', $payload, $this->secret_key );

		// Combine timestamp and HMAC into a single URL-safe token.
		$token_raw = $timestamp . '|' . $hmac;
		$token     = $this->base64url_encode( $token_raw );

		// Image hash for the URL path segment.
		$image_hash = hash_hmac( 'sha256', $url, $this->secret_key );
		$image_hash = substr( $image_hash, 0, 32 );

		// Store the URL-to-hash mapping in a transient so the proxy can
		// resolve the hash back to the real URL.
		$cache_key = 'starter_imghash_' . $image_hash;
		set_transient( $cache_key, $url, self::TOKEN_TTL + 60 );

		return array(
			'token'      => $token,
			'hash'       => $image_hash,
			'expires_at' => $timestamp + self::TOKEN_TTL,
		);
	}

	/**
	 * Build the full proxied URL for an encrypted image.
	 *
	 * @param string $url The real image URL to encrypt.
	 * @return string Public-facing proxied URL.
	 */
	public function get_encrypted_url( $url ) {
		$data = $this->encrypt_url( $url );
		return home_url( self::ENDPOINT . '/' . $data['token'] . '/' . $data['hash'] );
	}

	/**
	 * Validate an encrypted token against an image hash.
	 *
	 * @param string $token_b64   URL-safe base-64 token.
	 * @param string $image_hash  Hex image hash.
	 * @return string|false The real image URL on success, false on failure.
	 */
	public function validate_and_resolve( $token_b64, $image_hash ) {
		$token_raw = $this->base64url_decode( $token_b64 );
		if ( false === $token_raw || false === strpos( $token_raw, '|' ) ) {
			return false;
		}

		list( $timestamp, $hmac ) = explode( '|', $token_raw, 2 );
		$timestamp = (int) $timestamp;

		// Check expiry.
		if ( ( time() - $timestamp ) > self::TOKEN_TTL ) {
			return false;
		}

		// Resolve the real URL from the image hash.
		$cache_key = 'starter_imghash_' . $image_hash;
		$real_url  = get_transient( $cache_key );

		if ( false === $real_url ) {
			return false;
		}

		// Recompute HMAC and verify.
		$expected = hash_hmac( 'sha256', $real_url . '|' . $timestamp, $this->secret_key );
		if ( ! hash_equals( $expected, $hmac ) ) {
			return false;
		}

		// Verify the image hash matches.
		$expected_hash = substr(
			hash_hmac( 'sha256', $real_url, $this->secret_key ),
			0,
			32
		);
		if ( ! hash_equals( $expected_hash, $image_hash ) ) {
			return false;
		}

		return $real_url;
	}

	/*------------------------------------------------------------------
	 * Image proxy
	 *-----------------------------------------------------------------*/

	/**
	 * Handle an incoming image proxy request.
	 *
	 * Validates the token and expiry, checks the Referer for anti-hotlink
	 * protection, fetches the real image, and streams it directly with
	 * appropriate no-cache headers.
	 *
	 * @return void
	 */
	public function handle_image_request() {
		$token = get_query_var( self::QV_TOKEN );
		$hash  = get_query_var( self::QV_HASH );

		if ( empty( $token ) || empty( $hash ) ) {
			return; // Not our request.
		}

		// Anti-hotlink: verify Referer matches site domain.
		if ( ! $this->check_referer() ) {
			status_header( 403 );
			echo esc_html__( 'Hotlinking not allowed.', 'starter-theme' );
			exit;
		}

		// Validate token and resolve real URL.
		$real_url = $this->validate_and_resolve( $token, $hash );

		if ( false === $real_url ) {
			status_header( 403 );
			echo esc_html__( 'Invalid or expired image token.', 'starter-theme' );
			exit;
		}

		// Determine content type from extension.
		$extension    = strtolower( pathinfo( wp_parse_url( $real_url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$content_type = isset( $this->mime_map[ $extension ] ) ? $this->mime_map[ $extension ] : 'application/octet-stream';

		// Fetch the image.
		$response = wp_remote_get( $real_url, array(
			'timeout'   => 30,
			'sslverify' => true,
		) );

		if ( is_wp_error( $response ) ) {
			status_header( 502 );
			echo esc_html__( 'Failed to fetch image.', 'starter-theme' );
			exit;
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status ) {
			status_header( $status );
			echo esc_html__( 'Image not available.', 'starter-theme' );
			exit;
		}

		$body = wp_remote_retrieve_body( $response );

		// Detect actual content type from response headers if available.
		$remote_type = wp_remote_retrieve_header( $response, 'content-type' );
		if ( ! empty( $remote_type ) && 0 === strpos( $remote_type, 'image/' ) ) {
			$content_type = $remote_type;
		}

		// Stream the image with no-cache headers.
		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'Cache-Control: no-cache, no-store, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary image data.
		echo $body;
		exit;
	}

	/**
	 * Verify that the Referer header matches the site domain.
	 *
	 * @return bool
	 */
	private function check_referer() {
		// Allow requests with no Referer (direct browser nav, privacy extensions).
		if ( empty( $_SERVER['HTTP_REFERER'] ) ) {
			return true;
		}

		$referer_host = wp_parse_url(
			sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ),
			PHP_URL_HOST
		);

		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

		if ( empty( $referer_host ) || empty( $site_host ) ) {
			return true;
		}

		return ( strtolower( $referer_host ) === strtolower( $site_host ) );
	}

	/*------------------------------------------------------------------
	 * AJAX token refresh
	 *-----------------------------------------------------------------*/

	/**
	 * AJAX handler to refresh an image token before it expires.
	 *
	 * Expects POST with: image_url (encrypted), current_token.
	 * Returns a new token and expiry time.
	 *
	 * @return void
	 */
	public function ajax_refresh_token() {
		check_ajax_referer( 'starter_image_encryption', 'nonce' );

		$image_hash = isset( $_POST['image_hash'] ) ? sanitize_text_field( wp_unslash( $_POST['image_hash'] ) ) : '';

		if ( empty( $image_hash ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Missing image hash.', 'starter-theme' ) ),
				400
			);
		}

		// Resolve the real URL from the hash.
		$cache_key = 'starter_imghash_' . $image_hash;
		$real_url  = get_transient( $cache_key );

		if ( false === $real_url ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'Image hash not found or expired.', 'starter-theme' ) ),
				404
			);
		}

		// Generate a new encrypted URL.
		$new_data = $this->encrypt_url( $real_url );

		wp_send_json_success( array(
			'token'      => $new_data['token'],
			'hash'       => $new_data['hash'],
			'url'        => home_url( self::ENDPOINT . '/' . $new_data['token'] . '/' . $new_data['hash'] ),
			'expires_at' => $new_data['expires_at'],
			'refresh_in' => self::TOKEN_TTL - self::REFRESH_THRESHOLD,
		) );
	}

	/*------------------------------------------------------------------
	 * Utility helpers
	 *-----------------------------------------------------------------*/

	/**
	 * URL-safe base-64 encode.
	 *
	 * @param string $data Raw data.
	 * @return string URL-safe base-64 string.
	 */
	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * URL-safe base-64 decode.
	 *
	 * @param string $data URL-safe base-64 string.
	 * @return string|false Raw data or false on failure.
	 */
	private function base64url_decode( $data ) {
		$padded = str_pad( strtr( $data, '-_', '+/' ), strlen( $data ) % 4, '=', STR_PAD_RIGHT );
		return base64_decode( $padded, true );
	}

	/**
	 * Enqueue the token-refresh JavaScript on reader pages.
	 *
	 * Should be called by the theme or by Starter_Chapter_Protector.
	 *
	 * @return void
	 */
	public function enqueue_refresh_script() {
		wp_enqueue_script(
			'starter-image-token-refresh',
			STARTER_THEME_URI . '/assets/js/image-token-refresh.js',
			array( 'jquery' ),
			STARTER_THEME_VERSION,
			true
		);

		wp_localize_script( 'starter-image-token-refresh', 'starterImageEnc', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'nonce'            => wp_create_nonce( 'starter_image_encryption' ),
			'refreshThreshold' => self::REFRESH_THRESHOLD,
			'tokenTTL'         => self::TOKEN_TTL,
		) );
	}
}
