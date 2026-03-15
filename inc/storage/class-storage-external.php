<?php
/**
 * External URL storage handler.
 *
 * Handles images hosted on external services (Blogspot, Imgur, any URL).
 * Instead of uploading binary data this handler stores and manages URLs.
 * Optionally proxies images through the local domain to bypass hotlink
 * protection.
 *
 * @package starter-theme
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Starter_Storage_External
 */
class Starter_Storage_External implements Starter_Storage_Interface {

	/**
	 * Imgur API upload endpoint.
	 *
	 * @var string
	 */
	const IMGUR_UPLOAD_URL = 'https://api.imgur.com/3/image';

	/**
	 * Imgur client ID from .env.
	 *
	 * @var string
	 */
	private $imgur_client_id = '';

	/**
	 * Whether the image proxy is enabled.
	 *
	 * @var bool
	 */
	private $proxy_enabled = false;

	/**
	 * Transient cache TTL for reachability checks (seconds).
	 *
	 * @var int
	 */
	private $reachability_ttl = 3600;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->imgur_client_id = Starter_Env_Loader::get( 'STARTER_IMGUR_CLIENT_ID' );
		$this->proxy_enabled  = (bool) apply_filters( 'starter_external_proxy_enabled', false );

		if ( $this->proxy_enabled ) {
			add_action( 'init', array( $this, 'register_proxy_endpoint' ) );
			add_action( 'template_redirect', array( $this, 'handle_proxy_request' ) );
		}
	}

	/*--------------------------------------------------------------------------
	 * Starter_Storage_Interface methods.
	 *------------------------------------------------------------------------*/

	/**
	 * Upload / register a file.
	 *
	 * Accepts two workflows:
	 *   1. A local file path   -> uploads to Imgur (if configured), returns URL.
	 *   2. An external URL     -> validates and returns the URL as-is.
	 *
	 * The $destination parameter is used as the logical key when storing URLs
	 * in chapter data JSON.
	 *
	 * @param string $file_path   Local file path **or** an external URL.
	 * @param string $destination Logical storage key (e.g. manga-slug/ch-1/001).
	 * @return string|WP_Error    Public URL on success.
	 */
	public function upload( $file_path, $destination ) {
		// Workflow 2 — external URL passed directly.
		if ( filter_var( $file_path, FILTER_VALIDATE_URL ) ) {
			$url = esc_url_raw( $file_path );

			$reachable = $this->is_url_reachable( $url );
			if ( is_wp_error( $reachable ) ) {
				return $reachable;
			}

			$this->store_url( $destination, $url );
			return $url;
		}

		// Workflow 1 — local file, try Imgur upload.
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error(
				'starter_external_file_missing',
				__( 'Source file does not exist or is not readable.', 'starter-theme' )
			);
		}

		if ( ! empty( $this->imgur_client_id ) ) {
			$imgur_url = $this->imgur_upload( $file_path );
			if ( ! is_wp_error( $imgur_url ) ) {
				$this->store_url( $destination, $imgur_url );
				return $imgur_url;
			}
		}

		return new WP_Error(
			'starter_external_no_handler',
			__( 'No external upload handler is configured. Set STARTER_IMGUR_CLIENT_ID in .env or provide an external URL.', 'starter-theme' )
		);
	}

	/**
	 * Delete an external reference.
	 *
	 * Since we do not control external hosts we can only remove our local
	 * record of the URL.
	 *
	 * @param string $path Logical key.
	 * @return bool
	 */
	public function delete( $path ) {
		$path = $this->sanitize_key( $path );
		return delete_option( $this->option_key( $path ) );
	}

	/**
	 * Get the external URL stored under a given key.
	 *
	 * If the proxy is enabled the URL is rewritten through the local domain.
	 *
	 * @param string $path Logical key.
	 * @return string
	 */
	public function get_url( $path ) {
		$path = $this->sanitize_key( $path );
		$url  = get_option( $this->option_key( $path ), '' );

		if ( empty( $url ) ) {
			return '';
		}

		if ( $this->proxy_enabled ) {
			return $this->get_proxy_url( $url );
		}

		return $url;
	}

	/**
	 * Check whether a URL record exists for the given key.
	 *
	 * @param string $path Logical key.
	 * @return bool
	 */
	public function exists( $path ) {
		$path = $this->sanitize_key( $path );
		$url  = get_option( $this->option_key( $path ), '' );
		return ( '' !== $url );
	}

	/*--------------------------------------------------------------------------
	 * Imgur integration.
	 *------------------------------------------------------------------------*/

	/**
	 * Upload an image to Imgur using their anonymous upload API.
	 *
	 * @param string $file_path Absolute path to a local image file.
	 * @return string|WP_Error  Imgur URL on success.
	 */
	private function imgur_upload( $file_path ) {
		if ( empty( $this->imgur_client_id ) ) {
			return new WP_Error(
				'starter_imgur_no_client',
				__( 'Imgur client ID is not configured.', 'starter-theme' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$image_data = file_get_contents( $file_path );

		if ( false === $image_data ) {
			return new WP_Error( 'starter_imgur_read_fail', __( 'Could not read image file.', 'starter-theme' ) );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$base64 = base64_encode( $image_data );

		$response = wp_remote_post( self::IMGUR_UPLOAD_URL, array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Client-ID ' . $this->imgur_client_id,
			),
			'body'    => array(
				'image' => $base64,
				'type'  => 'base64',
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['data']['link'] ) ) {
			$message = isset( $body['data']['error'] ) ? $body['data']['error'] : __( 'Unknown Imgur error.', 'starter-theme' );
			return new WP_Error( 'starter_imgur_upload_fail', $message );
		}

		return esc_url_raw( $body['data']['link'] );
	}

	/*--------------------------------------------------------------------------
	 * Blogspot support.
	 *------------------------------------------------------------------------*/

	/**
	 * Normalise a Blogspot image URL for consistent storage.
	 *
	 * Blogspot URLs often contain size parameters (e.g. /s1600/).  This
	 * method strips those so we always store the highest-quality link and
	 * can add size modifiers at render time.
	 *
	 * @param string $url Blogspot image URL.
	 * @return string Normalised URL.
	 */
	public function normalise_blogspot_url( $url ) {
		// Pattern: /sNNNN/ or /wNNNN-hNNNN/ in the path.
		$url = preg_replace( '#/s\d+/#', '/s0/', $url );
		$url = preg_replace( '#/w\d+-h\d+/#', '/s0/', $url );
		return esc_url_raw( $url );
	}

	/**
	 * Get a Blogspot image at a specific width.
	 *
	 * @param string $url   Base Blogspot URL (should contain /s0/).
	 * @param int    $width Desired width in pixels.
	 * @return string Modified URL.
	 */
	public function get_blogspot_sized_url( $url, $width = 0 ) {
		if ( 0 === $width ) {
			return $url;
		}
		return preg_replace( '#/s0/#', '/w' . absint( $width ) . '/', $url );
	}

	/*--------------------------------------------------------------------------
	 * URL validation & reachability.
	 *------------------------------------------------------------------------*/

	/**
	 * Check whether a URL is reachable (returns a 2xx status).
	 *
	 * Results are cached with a transient to avoid repeated HEAD requests.
	 *
	 * @param string $url URL to check.
	 * @return true|WP_Error
	 */
	private function is_url_reachable( $url ) {
		$cache_key = 'starter_url_reach_' . md5( $url );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return ( 'ok' === $cached ) ? true : new WP_Error( 'starter_url_unreachable', $cached );
		}

		$response = wp_remote_head( $url, array(
			'timeout'     => 15,
			'redirection' => 3,
			'sslverify'   => false,
		) );

		if ( is_wp_error( $response ) ) {
			set_transient( $cache_key, $response->get_error_message(), $this->reachability_ttl );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 400 ) {
			$message = sprintf(
				/* translators: %d: HTTP status code */
				__( 'URL returned HTTP %d.', 'starter-theme' ),
				$code
			);
			set_transient( $cache_key, $message, $this->reachability_ttl );
			return new WP_Error( 'starter_url_unreachable', $message );
		}

		set_transient( $cache_key, 'ok', $this->reachability_ttl );
		return true;
	}

	/*--------------------------------------------------------------------------
	 * URL storage (via WP options).
	 *------------------------------------------------------------------------*/

	/**
	 * Persist a URL in the options table under a given logical key.
	 *
	 * @param string $key Logical storage key.
	 * @param string $url External URL.
	 * @return bool
	 */
	private function store_url( $key, $url ) {
		$key = $this->sanitize_key( $key );
		return update_option( $this->option_key( $key ), esc_url_raw( $url ), false );
	}

	/**
	 * Build the option name for a storage key.
	 *
	 * @param string $key Logical key.
	 * @return string
	 */
	private function option_key( $key ) {
		return 'starter_ext_url_' . md5( $key );
	}

	/*--------------------------------------------------------------------------
	 * Bulk URL import (paste-image-path workflow).
	 *------------------------------------------------------------------------*/

	/**
	 * Import a list of image URLs for a chapter.
	 *
	 * Accepts a newline- or comma-separated list of URLs and stores them
	 * sequentially under manga-slug/chapter-num/page-NNN.
	 *
	 * @param string $manga_slug  Manga slug.
	 * @param int    $chapter_num Chapter number.
	 * @param string $url_list    Newline- or comma-separated URL list.
	 * @return array Array of results: [ 'page-001' => url|WP_Error, ... ].
	 */
	public function import_url_list( $manga_slug, $chapter_num, $url_list ) {
		$manga_slug  = sanitize_title( $manga_slug );
		$chapter_num = absint( $chapter_num );

		// Split by newlines or commas.
		$urls = preg_split( '/[\r\n,]+/', $url_list, -1, PREG_SPLIT_NO_EMPTY );
		$urls = array_map( 'trim', $urls );
		$urls = array_filter( $urls );

		$results = array();
		$page    = 1;

		foreach ( $urls as $url ) {
			$page_key    = sprintf( '%s/chapter-%d/page-%03d', $manga_slug, $chapter_num, $page );
			$result      = $this->upload( $url, $page_key );
			$results[ sprintf( 'page-%03d', $page ) ] = $result;
			$page++;
		}

		return $results;
	}

	/**
	 * Get all stored URLs for a chapter.
	 *
	 * @param string $manga_slug  Manga slug.
	 * @param int    $chapter_num Chapter number.
	 * @param int    $max_pages   Maximum pages to look for.
	 * @return array Ordered array of URLs.
	 */
	public function get_chapter_urls( $manga_slug, $chapter_num, $max_pages = 500 ) {
		$manga_slug  = sanitize_title( $manga_slug );
		$chapter_num = absint( $chapter_num );
		$urls        = array();

		for ( $i = 1; $i <= $max_pages; $i++ ) {
			$page_key = sprintf( '%s/chapter-%d/page-%03d', $manga_slug, $chapter_num, $i );
			$url      = $this->get_url( $page_key );

			if ( empty( $url ) ) {
				break;
			}

			$urls[] = $url;
		}

		return $urls;
	}

	/*--------------------------------------------------------------------------
	 * Proxy endpoint.
	 *------------------------------------------------------------------------*/

	/**
	 * Register the rewrite rule for the proxy endpoint.
	 *
	 * @return void
	 */
	public function register_proxy_endpoint() {
		add_rewrite_rule(
			'^starter-image-proxy/?$',
			'index.php?starter_image_proxy=1',
			'top'
		);
		add_rewrite_tag( '%starter_image_proxy%', '1' );
	}

	/**
	 * Handle an incoming proxy request.
	 *
	 * Serves the external image through the local domain so the visitor's
	 * browser never contacts the remote host directly.
	 *
	 * Usage: /starter-image-proxy/?url=<base64-encoded-url>
	 *
	 * @return void
	 */
	public function handle_proxy_request() {
		if ( ! get_query_var( 'starter_image_proxy' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$encoded_url = isset( $_GET['url'] ) ? sanitize_text_field( wp_unslash( $_GET['url'] ) ) : '';

		if ( empty( $encoded_url ) ) {
			status_header( 400 );
			exit;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$url = base64_decode( $encoded_url, true );

		if ( false === $url || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			status_header( 400 );
			exit;
		}

		// Only allow image MIME types.
		$response = wp_remote_get( $url, array(
			'timeout'   => 30,
			'sslverify' => false,
		) );

		if ( is_wp_error( $response ) ) {
			status_header( 502 );
			exit;
		}

		$content_type = wp_remote_retrieve_header( $response, 'content-type' );

		if ( empty( $content_type ) || 0 !== strpos( $content_type, 'image/' ) ) {
			status_header( 403 );
			exit;
		}

		$body = wp_remote_retrieve_body( $response );

		// Cache in browser for 24 hours.
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Length: ' . strlen( $body ) );
		header( 'Cache-Control: public, max-age=86400' );
		header( 'X-Proxy: starter-theme' );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $body;
		exit;
	}

	/**
	 * Build the proxy URL for a given external image URL.
	 *
	 * @param string $url Original external URL.
	 * @return string Local proxy URL.
	 */
	private function get_proxy_url( $url ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$encoded = base64_encode( $url );
		return home_url( '/starter-image-proxy/?url=' . rawurlencode( $encoded ) );
	}

	/*--------------------------------------------------------------------------
	 * Utility.
	 *------------------------------------------------------------------------*/

	/**
	 * Sanitize a logical storage key.
	 *
	 * @param string $key Raw key.
	 * @return string
	 */
	private function sanitize_key( $key ) {
		$key = str_replace( '\\', '/', $key );
		$key = str_replace( '../', '', $key );
		$key = ltrim( $key, '/' );
		$key = preg_replace( '#/+#', '/', $key );
		return sanitize_text_field( $key );
	}
}
